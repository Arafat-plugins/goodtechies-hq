<?php

use App\Models\Conversation;
use App\Models\Employee;
use App\Models\File;
use App\Models\Message;
use App\Models\Task;
use App\Models\User;
use App\Services\ConversationService;
use App\Services\FileService;
use App\Services\TaskService;
use App\Support\RoleName;
use App\Support\UserStatus;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| The discussion: who can read it, and what happens when that changes
|--------------------------------------------------------------------------
|
| The spec's line is "an employee sees the discussion only of tasks they are
| assigned to. The task conversation's membership follows task access."
|
| `conversation_members` is a stored list and task access is computed, so this
| file exists to prove which of the two is the answer. The decision was that
| access is COMPUTED — every read asks TaskPolicy about the linked task, and a
| member row holds read state and grants nothing. The tests below are the
| shape that decision has to survive: a stale row must buy nobody anything,
| and a reassignment must close the door with no sync having run.
|
*/

beforeEach(function () {
    $this->seed();
    Storage::fake(config('filesystems.default'));

    $this->conversations = app(ConversationService::class);

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();

    // The same pair every task privacy test uses: a task Tapu is on and Yaseen is not.
    $this->tapusTask = Task::query()->forEmployee($this->tapu->employee)->notArchived()->orderBy('id')->firstOrFail();
    $this->conversation = $this->conversations->forTask($this->tapusTask);

    // The seeder puts a demo thread on some of these tasks; this file counts messages
    // absolutely, so it starts from a known one.
    $this->conversation->messages()->delete();

    $this->conversations->post($this->tapu, $this->conversation, 'Only the people on this task should read this.');
});

/*
|--------------------------------------------------------------------------
| An employee sees the discussion only of tasks they are on
|--------------------------------------------------------------------------
*/

it('answers 404 when an employee opens the discussion of a task they are not on', function () {
    // 404 and not 403: a task they may not see is ABSENT, so a discussion on it has to be
    // absent in exactly the same way, or the shape of the refusal becomes a way of asking
    // whether the task exists.
    $this->actingAs($this->yaseen)
        ->getJson("/employee/tasks/{$this->tapusTask->id}/discussion")
        ->assertNotFound();
})->group('phase2');

it('answers 404 when they try to post on one', function () {
    $this->actingAs($this->yaseen)
        ->post("/employee/tasks/{$this->tapusTask->id}/discussion", ['body' => 'Hello?'])
        ->assertNotFound();

    expect($this->conversation->messages()->count())->toBe(1);
})->group('phase2');

it('lets the assignee read and post on the same task', function () {
    // The 404 above is about the person, not about a broken route.
    $this->actingAs($this->tapu)
        ->getJson("/employee/tasks/{$this->tapusTask->id}/discussion")
        ->assertOk()
        ->assertJsonPath('can_post', true);

    $this->actingAs($this->tapu)
        ->post("/employee/tasks/{$this->tapusTask->id}/discussion", ['body' => 'Working on it.'])
        ->assertRedirect();

    expect($this->conversation->messages()->count())->toBe(2);
})->group('phase2');

/*
|--------------------------------------------------------------------------
| A stored member row is not a grant
|--------------------------------------------------------------------------
*/

it('gives nothing to somebody who has a membership row but not the task', function () {
    // This is the decision, asserted. If `conversation_members` were the access list — or even
    // half of it — this row would be a hole, and it is precisely the row a sync-on-assignment
    // scheme leaves behind when somebody is taken off a task.
    DB::table('conversation_members')->insert([
        'conversation_id' => $this->conversation->id,
        'user_id' => $this->yaseen->id,
        'last_read_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($this->yaseen->can('view', $this->conversation))->toBeFalse()
        ->and($this->yaseen->can('post', $this->conversation))->toBeFalse();

    $this->actingAs($this->yaseen)
        ->getJson("/employee/tasks/{$this->tapusTask->id}/discussion")
        ->assertNotFound();
})->group('phase2');

it('closes the discussion the moment the task is reassigned, with no sync having run', function () {
    $tasks = app(TaskService::class);

    // Tapu is on it and can read it.
    expect($this->tapu->can('view', $this->conversation))->toBeTrue();

    // Tapu has read it, so he has a member row — exactly the stale row the stored-membership
    // design would have had to remember to delete.
    $this->conversations->markRead($this->tapu, $this->conversation);
    expect(DB::table('conversation_members')
        ->where('conversation_id', $this->conversation->id)
        ->where('user_id', $this->tapu->id)
        ->exists())->toBeTrue();

    // Hand the task to Yaseen. Nothing in this call knows that conversations exist.
    $tasks->syncAssignees($this->admin, $this->tapusTask, [$this->yaseen->employee->id]);

    // The door is shut for Tapu and open for Yaseen, immediately, because the answer was never
    // stored — and Tapu's member row is still there, carrying a timestamp and nothing else.
    expect($this->tapu->fresh()->can('view', $this->conversation->fresh()))->toBeFalse()
        ->and($this->yaseen->fresh()->can('view', $this->conversation->fresh()))->toBeTrue()
        ->and(DB::table('conversation_members')
            ->where('conversation_id', $this->conversation->id)
            ->where('user_id', $this->tapu->id)
            ->exists())->toBeTrue();

    $this->actingAs($this->tapu)
        ->getJson("/employee/tasks/{$this->tapusTask->id}/discussion")
        ->assertNotFound();

    $this->actingAs($this->yaseen)
        ->getJson("/employee/tasks/{$this->tapusTask->id}/discussion")
        ->assertOk();
})->group('phase2');

it('shuts it for a deactivated user without touching the task', function () {
    // The case a sync hung off assignment could never have caught: nothing about the task
    // changed at all.
    expect($this->tapu->can('view', $this->conversation))->toBeTrue();

    $this->tapu->employee->user->forceFill(['status' => UserStatus::Inactive])->save();

    expect($this->tapu->fresh()->can('view', $this->conversation))->toBeFalse();
})->group('phase2');

/*
|--------------------------------------------------------------------------
| A message's attachment is as invisible as the message
|--------------------------------------------------------------------------
*/

it('hides an attachment on a discussion the requester cannot reach', function () {
    $files = app(FileService::class);

    $message = $this->conversations->post(
        $this->tapu,
        $this->conversation,
        'The export.',
        UploadedFile::fake()->create('crawl.pdf', 20, 'application/pdf'),
    );

    /** @var File $file */
    $file = $message->attachments->firstOrFail();

    // A real, unexpired, untampered link. The chain the refusal runs down is
    // file → message → conversation → task → TaskPolicy, and none of those links states a rule
    // of its own.
    $url = $files->url($file);

    $this->actingAs($this->yaseen)->get($url)->assertNotFound();
    $this->actingAs($this->tapu)->get($url)->assertOk();
    $this->actingAs($this->accountant)->get($url)->assertNotFound();
})->group('phase2');

it('never lets a message attachment be replaced, not even by its author', function () {
    $message = $this->conversations->post(
        $this->tapu,
        $this->conversation,
        'Version one.',
        UploadedFile::fake()->create('report.pdf', 20, 'application/pdf'),
    );

    $file = $message->attachments->firstOrFail();

    // A message is what somebody said at a time, and there is no edit endpoint for one.
    // Swapping the file underneath would rewrite what the room was answering.
    expect($this->tapu->can('replace', $file))->toBeFalse()
        ->and($this->admin->can('replace', $file))->toBeFalse();

    $this->actingAs($this->tapu)
        ->post("/employee/files/{$file->id}/versions", [
            'file' => UploadedFile::fake()->create('report.pdf', 21, 'application/pdf'),
        ])
        ->assertForbidden();

    expect($file->fresh()->version)->toBe(1);
})->group('phase2');

/*
|--------------------------------------------------------------------------
| The Accountant gets none of it
|--------------------------------------------------------------------------
*/

it('gives the Accountant no discussion on either surface', function () {
    // Not by naming them anywhere: they hold no tasks.* permission and are on neither surface.
    $this->actingAs($this->accountant)
        ->getJson("/admin/tasks/{$this->tapusTask->id}/discussion")
        ->assertForbidden();

    $this->actingAs($this->accountant)
        ->getJson("/employee/tasks/{$this->tapusTask->id}/discussion")
        ->assertForbidden();

    $this->actingAs($this->accountant)
        ->post("/admin/tasks/{$this->tapusTask->id}/discussion", ['body' => 'Invoice query'])
        ->assertForbidden();

    expect($this->accountant->can('view', $this->conversation))->toBeFalse()
        ->and($this->conversation->messages()->count())->toBe(1);
})->group('phase2');

it('keeps each surface to its own role', function () {
    $manager = Employee::factory()->forRole(RoleName::MANAGER)->create()->user;

    $this->actingAs($this->admin)
        ->getJson("/employee/tasks/{$this->tapusTask->id}/discussion")
        ->assertForbidden();

    $this->actingAs($manager)
        ->getJson("/admin/tasks/{$this->tapusTask->id}/discussion")
        ->assertForbidden();
})->group('phase2');

/*
|--------------------------------------------------------------------------
| And a conversation with no task behind it
|--------------------------------------------------------------------------
*/

it('opens to nobody when the task it is about has gone', function () {
    // Not defensive: the alternative to "no subject, no answer" is a discussion that outlives
    // the access rule it was computed from. A task's delete is SOFT — so the conversation and
    // its messages survive, exactly as the task's attachments and checklist do — and the
    // computed subject resolves to nothing, which ends at a denial.
    $task = Task::factory()->create();
    $conversation = $this->conversations->forTask($task);
    Message::factory()->inConversation($conversation)->by($this->admin)->create();

    $task->delete();

    $conversation = Conversation::findOrFail($conversation->id);

    expect($conversation->messages()->count())->toBe(1)
        ->and($this->admin->can('view', $conversation))->toBeFalse()
        ->and($this->admin->can('post', $conversation))->toBeFalse();
})->group('phase2');

it('takes the discussion with the task on a hard delete', function () {
    // The other half of the same rule, at the database: `linked_task_id` cascades, so a task
    // that is really gone leaves no discussion behind to be found by id.
    $task = Task::factory()->create();
    $conversation = $this->conversations->forTask($task);
    Message::factory()->inConversation($conversation)->by($this->admin)->create();

    $task->forceDelete();

    expect(Conversation::whereKey($conversation->id)->exists())->toBeFalse()
        ->and(Message::where('conversation_id', $conversation->id)->exists())->toBeFalse();
})->group('phase2');
