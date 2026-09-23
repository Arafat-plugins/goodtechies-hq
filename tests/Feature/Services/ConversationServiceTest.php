<?php

use App\Exceptions\ConversationStateException;
use App\Exceptions\FileStateException;
use App\Models\Conversation;
use App\Models\Employee;
use App\Models\File;
use App\Models\Message;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\ConversationService;
use App\Services\TaskService;
use App\Support\ConversationType;
use App\Support\RoleName;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| The task discussion, at the service
|--------------------------------------------------------------------------
|
| One store: the spec's `task_comments` table is not created, and a task's
| comments are the messages of its own `task` conversation. So the first thing
| worth asserting is that a task is BORN with one, by every route that makes a
| task — the service, the seeder, the factory, and the migration backfill for
| the tasks that predate the table.
|
| Attachments go through FileService, which is the only thing in this
| application that writes bytes. The tests below check that this is true of
| the code and not just of the intention: a message attachment lands in the
| same `files` table, under the same rules, as a task attachment.
|
*/

beforeEach(function () {
    $this->seed();
    Storage::fake(config('filesystems.default'));

    $this->conversations = app(ConversationService::class);
    $this->tasks = app(TaskService::class);

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();

    $this->project = Project::where('name', 'Buffalo Modular — SEO')->firstOrFail();
    $this->tapusTask = Task::query()->forEmployee($this->tapu->employee)->notArchived()->orderBy('id')->firstOrFail();

    // The seeder puts a demo thread on some of these tasks. Several assertions below count
    // messages absolutely, so they start from an empty thread on the conversation the task
    // was born with.
    $this->conversations->forTask($this->tapusTask)->messages()->delete();
});

/*
|--------------------------------------------------------------------------
| Created on task create
|--------------------------------------------------------------------------
*/

it('gives a task its discussion the moment it is created', function () {
    $task = $this->tasks->create($this->admin, [
        'project_id' => $this->project->id,
        'title' => 'A task with somewhere to talk about it',
        'status' => 'backlog',
    ]);

    $conversation = Conversation::query()->forTask($task)->first();

    expect($conversation)->not->toBeNull()
        ->and($conversation->type)->toBe(ConversationType::Task)
        ->and($conversation->linked_task_id)->toBe($task->id)
        // A task discussion is named by its task; a copy of the title here would be a second
        // one to keep in step with every rename.
        ->and($conversation->title)->toBeNull();
})->group('phase2');

it('gives every seeded task one, so the demo is not half built', function () {
    expect(Task::count())->toBeGreaterThan(0)
        ->and(Conversation::ofType(ConversationType::Task)->count())->toBe(Task::count());
})->group('phase2');

it('creates exactly one however many callers ask for it', function () {
    // `forTask()` is a firstOrCreate behind a unique index, which is what lets the migration
    // backfill, the seeder and a controller all call it without knowing about each other.
    $task = Task::factory()->create();

    $first = $this->conversations->forTask($task);
    $second = $this->conversations->forTask($task);

    expect($second->id)->toBe($first->id)
        ->and(Conversation::query()->forTask($task)->count())->toBe(1);
})->group('phase2');

it('picks up a task that predates conversations', function () {
    // The case the migration's backfill exists for, reproduced: a task row with no
    // conversation, as every task in the database was before this slice. Opening the
    // discussion creates it rather than 404ing forever.
    $task = Task::factory()->create();
    Conversation::query()->forTask($task)->delete();

    expect(Conversation::query()->forTask($task)->exists())->toBeFalse();

    $conversation = $this->conversations->forTask($task);

    expect($conversation->exists)->toBeTrue()
        ->and($conversation->linked_task_id)->toBe($task->id);
})->group('phase2');

/*
|--------------------------------------------------------------------------
| Posting
|--------------------------------------------------------------------------
*/

it('posts a message', function () {
    $conversation = $this->conversations->forTask($this->tapusTask);

    $message = $this->conversations->post($this->tapu, $conversation, '  Canonicals are half done.  ');

    expect($message->body)->toBe('Canonicals are half done.')
        ->and($message->author_id)->toBe($this->tapu->id)
        ->and($message->conversation_id)->toBe($conversation->id);
})->group('phase2');

it('refuses a message with nothing in it', function () {
    $conversation = $this->conversations->forTask($this->tapusTask);

    // Whitespace is not a message. The rule cannot be a CHECK constraint, because half of its
    // answer lives in `message_attachments`, so it is stated at the only writer.
    expect(fn () => $this->conversations->post($this->tapu, $conversation, "   \n  "))
        ->toThrow(ConversationStateException::class);

    expect($conversation->messages()->count())->toBe(0);
})->group('phase2');

it('refuses somebody who may not see the task', function () {
    $conversation = $this->conversations->forTask($this->tapusTask);

    expect(fn () => $this->conversations->post($this->yaseen, $conversation, 'Let me in'))
        ->toThrow(AuthorizationException::class);

    expect($conversation->messages()->count())->toBe(0);
})->group('phase2');

it('puts a line on the task timeline without quoting what was said', function () {
    $conversation = $this->conversations->forTask($this->tapusTask);

    $this->conversations->post($this->tapu, $conversation, 'Something confidential about the client');

    $timeline = app(ActivityLogger::class)->for($this->tapusTask)->pluck('description');

    // activity_logs is a different audience from the discussion. Quoting a comment into it
    // would be publishing it twice, with one set of rules.
    expect($timeline)->toContain('Message posted in the discussion')
        ->and($timeline->implode(' '))->not->toContain('confidential');
})->group('phase2');

/*
|--------------------------------------------------------------------------
| A message with a file on it
|--------------------------------------------------------------------------
*/

it('stores an attachment through FileService and nowhere else', function () {
    $conversation = $this->conversations->forTask($this->tapusTask);

    $message = $this->conversations->post(
        $this->tapu,
        $conversation,
        'The crawl export.',
        UploadedFile::fake()->create('crawl.pdf', 40, 'application/pdf'),
    );

    $file = $message->attachments->firstOrFail();

    expect($message->attachments)->toHaveCount(1)
        // Owned by the message: the fourth arm of the exclusive arc, not a second table.
        ->and($file->message_id)->toBe($message->id)
        ->and($file->task_id)->toBeNull()
        ->and($file->uploaded_by)->toBe($this->tapu->id)
        // Under the same path scheme, on the same disk, with the same checksum discipline as
        // a task attachment — because it is the same writer.
        ->and($file->path)->toStartWith('files/messages/'.$message->id.'/')
        ->and($file->checksum)->not->toBeNull()
        // How it rides on the bubble: a PDF is a download, not an inline image.
        ->and($file->pivot->kind)->toBe('file')
        ->and($file->pivot->duration_seconds)->toBeNull();

    Storage::disk($file->disk)->assertExists($file->path);
})->group('phase2');

it('marks an image attachment as one, from the file rather than from its name', function () {
    $conversation = $this->conversations->forTask($this->tapusTask);

    $message = $this->conversations->post(
        $this->tapu,
        $conversation,
        null,
        UploadedFile::fake()->image('screenshot.png'),
    );

    expect($message->body)->toBeNull()
        ->and($message->attachments->firstOrFail()->pivot->kind)->toBe('image');
})->group('phase2');

it('refuses an upload the application would not store, without writing a message', function () {
    $conversation = $this->conversations->forTask($this->tapusTask);

    // Checked BEFORE the message row, so a refused upload does not burn an id — and, more to
    // the point, so FileService never writes bytes inside a transaction that then disappears.
    expect(fn () => $this->conversations->post(
        $this->tapu,
        $conversation,
        'Here you go',
        UploadedFile::fake()->create('payload.php', 2, 'text/x-php'),
    ))->toThrow(FileStateException::class);

    expect($conversation->messages()->count())->toBe(0)
        ->and(File::query()->whereNotNull('message_id')->count())->toBe(0);
})->group('phase2');

/*
|--------------------------------------------------------------------------
| Read state — the only thing conversation_members is for
|--------------------------------------------------------------------------
*/

it('counts what somebody has not read, and never counts their own', function () {
    $conversation = $this->conversations->forTask($this->tapusTask);

    $this->conversations->post($this->admin, $conversation, 'One');
    $this->conversations->post($this->admin, $conversation, 'Two');

    expect($this->conversations->readState($this->tapu, $conversation))
        ->toMatchArray(['last_read_at' => null, 'unread_count' => 2]);

    $this->conversations->markRead($this->tapu, $conversation);

    expect($this->conversations->readState($this->tapu, $conversation)['unread_count'])->toBe(0);

    // Posting is reading: a count that went up when you spoke would be measuring the wrong
    // thing.
    $this->conversations->post($this->tapu, $conversation, 'Mine');

    expect($this->conversations->readState($this->tapu, $conversation)['unread_count'])->toBe(0);
})->group('phase2');

it('writes a member row that is only a timestamp', function () {
    $conversation = $this->conversations->forTask($this->tapusTask);

    $this->conversations->markRead($this->tapu, $conversation);
    $this->conversations->markRead($this->tapu, $conversation);

    $rows = DB::table('conversation_members')
        ->where('conversation_id', $conversation->id)
        ->where('user_id', $this->tapu->id)
        ->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->last_read_at)->not->toBeNull();
})->group('phase2');

/*
|--------------------------------------------------------------------------
| The four types Phase 6 owns
|--------------------------------------------------------------------------
*/

it('lets nobody into a conversation type this phase has no rules for', function (ConversationType $type) {
    // Deny by default. A conversation whose access rule has not been specified is one nobody
    // may open — including an Admin, who passes the same checks as everybody else here.
    $conversation = Conversation::factory()->ofType($type)->create();

    expect($this->admin->can('view', $conversation))->toBeFalse()
        ->and($this->admin->can('post', $conversation))->toBeFalse();

    expect(fn () => $this->conversations->post($this->admin, $conversation, 'Hello?'))
        ->toThrow(AuthorizationException::class);
})->with([
    'team' => [ConversationType::Team],
    'dm' => [ConversationType::Dm],
    'announcement' => [ConversationType::Announcement],
])->group('phase2');

it('keeps a manager reading a task discussion they are not assigned to', function () {
    // The other half of "membership follows task access": a Manager sees every task, so they
    // see every discussion, without a membership row ever being written for them.
    $manager = Employee::factory()->forRole(RoleName::MANAGER)->create()->user;
    $conversation = $this->conversations->forTask($this->tapusTask);

    Message::factory()->inConversation($conversation)->by($this->tapu)->create();

    expect($manager->can('view', $conversation))->toBeTrue()
        ->and(DB::table('conversation_members')->where('user_id', $manager->id)->count())->toBe(0);
})->group('phase2');
