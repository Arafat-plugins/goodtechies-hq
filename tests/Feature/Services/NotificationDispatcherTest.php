<?php

use App\Models\Notification;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\ConversationService;
use App\Services\ProjectService;
use App\Services\TaskService;
use App\Support\NotificationType;
use App\Support\ProjectStatus;
use App\Support\TaskStatus;

/*
|--------------------------------------------------------------------------
| Which events reach whom
|--------------------------------------------------------------------------
|
| The engine's other half. NotificationService decides that a notification is
| written and how it groups; NotificationDispatcher decides who it is written
| for, and these are the assertions of that.
|
| Every one of them goes through the real service call — TaskService::create,
| ::transition, ::syncAssignees, ::handOff, ::delete, ConversationService::post
| and ProjectService::changeStatus — rather than firing an event by hand. An
| event nobody fires is an event nobody receives, and a test that fires its own
| would pass while the wiring was missing.
|
| The Accountant is asserted absent from every one of them, and never once by
| being named as a role: they are simply never a recipient, because they hold
| no tasks.* permission and every Phase 2 notification type requires one.
|
*/

beforeEach(function () {
    $this->seed();

    $this->tasks = app(TaskService::class);
    $this->conversations = app(ConversationService::class);
    $this->projects = app(ProjectService::class);

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->faruk = User::where('email', 'faruk@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();

    // Shahadat is this project's PM, so he is its reviewer (TaskReviewers), and he creates the
    // task below, so he is also its `created_by` — the spec's "original assigner".
    $this->project = Project::where('name', 'Buffalo Modular — SEO')->firstOrFail();

    Notification::query()->delete();

    $this->task = $this->tasks->create($this->admin, [
        'project_id' => $this->project->id,
        'title' => 'Rewrite the county landing pages',
        'status' => 'todo',
    ], [$this->tapu->employee->id]);
});

/**
 * The types this user has been sent, newest last.
 *
 * @return list<string>
 */
function typesFor(User $user): array
{
    return Notification::query()
        ->forUser($user)
        ->orderBy('id')
        ->pluck('type')
        ->map(fn (mixed $type): string => $type instanceof NotificationType ? $type->value : (string) $type)
        ->all();
}

/*
|--------------------------------------------------------------------------
| Assigned / reassigned
|--------------------------------------------------------------------------
*/

it('tells the assignee they were given the task, and tells the assigner nothing', function () {
    expect(typesFor($this->tapu))->toBe([NotificationType::TaskAssigned->value])
        // The actor never hears about their own act.
        ->and(typesFor($this->admin))->toBe([])
        ->and(typesFor($this->accountant))->toBe([]);
})->group('phase2');

it('tells both the person added and the person taken off when a task is reassigned', function () {
    Notification::query()->delete();

    $this->tasks->syncAssignees($this->admin, $this->task, [$this->yaseen->employee->id]);

    expect(typesFor($this->tapu))->toBe([NotificationType::TaskReassigned->value])
        ->and(typesFor($this->yaseen))->toBe([NotificationType::TaskReassigned->value])
        ->and(typesFor($this->accountant))->toBe([]);
})->group('phase2');

it('tells both sides of a hand-off', function () {
    $this->tasks->syncAssignees(
        $this->admin,
        $this->task,
        [$this->tapu->employee->id, $this->yaseen->employee->id],
        $this->tapu->employee->id,
    );

    Notification::query()->delete();

    $this->tasks->handOff($this->admin, $this->task->fresh(), $this->yaseen->employee, 'Tapu is on leave');

    // The assignee set did not change; the primary did, and both of them need to know.
    expect(typesFor($this->tapu))->toBe([NotificationType::TaskReassigned->value])
        ->and(typesFor($this->yaseen))->toBe([NotificationType::TaskReassigned->value]);
})->group('phase2');

it('does not notify anybody when a re-save changes no assignee at all', function () {
    Notification::query()->delete();

    $this->tasks->syncAssignees($this->admin, $this->task, [$this->tapu->employee->id]);

    expect(Notification::query()->count())->toBe(0);
})->group('phase2');

/*
|--------------------------------------------------------------------------
| Status
|--------------------------------------------------------------------------
*/

it('tells the person who asked for the task when its status moves', function () {
    Notification::query()->delete();

    $this->tasks->transition($this->tapu, $this->task->fresh(), TaskStatus::InProgress);

    expect(typesFor($this->admin))->toBe([NotificationType::TaskStatusChanged->value])
        // The mover hears nothing about their own move.
        ->and(typesFor($this->tapu))->toBe([])
        ->and(typesFor($this->accountant))->toBe([]);

    $row = Notification::query()->forUser($this->admin)->first();

    expect($row->summary())->toBe('"Rewrite the county landing pages" moved to In progress');
})->group('phase2');

it('sends the reviewer ONE notification when a task goes to review, not two', function () {
    $this->tasks->transition($this->tapu, $this->task->fresh(), TaskStatus::InProgress);

    Notification::query()->delete();

    $this->tasks->transition(
        $this->tapu,
        $this->task->fresh(),
        TaskStatus::InReview,
        'Rewrote all eight pages and re-crawled.',
    );

    // Shahadat is the PM, so the reviewer — and he is `created_by` as well. One event, one
    // notification: he must not also receive a generic "status changed".
    expect(typesFor($this->admin))->toBe([NotificationType::TaskSubmittedForReview->value])
        ->and(Notification::query()->forUser($this->admin)->first()->summary())
        ->toBe('"Rewrite the county landing pages" is waiting for your review');
})->group('phase2');

it('tells the original assigner when a task is completed', function () {
    // The reviewer of this project is Shahadat (its PM), and he is also the creator of
    // $this->task — so completing that one would notify nobody, because the actor never hears
    // about their own act. A task FARUK asked for separates the two: the reviewer completes it
    // and the "original assigner" is somebody else.
    $farukstask = $this->tasks->create($this->faruk, [
        'project_id' => $this->project->id,
        'title' => 'Refresh the meta descriptions',
        'status' => 'todo',
    ], [$this->tapu->employee->id]);

    $this->tasks->transition($this->tapu, $farukstask->fresh(), TaskStatus::InProgress);
    $this->tasks->transition($this->tapu, $farukstask->fresh(), TaskStatus::InReview, 'Done and re-crawled.');

    Notification::query()->delete();

    $this->tasks->transition($this->admin, $farukstask->fresh(), TaskStatus::Completed);

    expect(typesFor($this->faruk))->toBe([NotificationType::TaskCompleted->value])
        // And the person who actually did the work. The spec's recipient list says
        // `created_by` + reviewer and stops there; its own acceptance sentence says a
        // notification appears at every step, and on this team those two people are usually
        // one person who is also the actor — so an approval used to write zero rows and the
        // assignee was never told. See NotificationDispatcher::onTaskCompleted().
        ->and(typesFor($this->tapu))->toBe([NotificationType::TaskCompleted->value])
        // The reviewer completed it, so the reviewer is the actor and hears nothing.
        ->and(typesFor($this->admin))->toBe([])
        ->and(typesFor($this->accountant))->toBe([]);
})->group('phase2');

it('tells the assignee their work was approved, even when the reviewer asked for it', function () {
    // The acceptance walk's last step, exactly as the plan writes it: Shahadat both created
    // the task and reviews it, and he is the one who approves. Every candidate the spec's
    // recipient list names is therefore the actor, and the actor is always dropped — so before
    // the assignee was added to the list, this step was silent for everybody.
    $this->tasks->transition($this->tapu, $this->task->fresh(), TaskStatus::InProgress);
    $this->tasks->transition($this->tapu, $this->task->fresh(), TaskStatus::InReview, 'Rewritten and re-crawled.');
    $this->tasks->transition($this->admin, $this->task->fresh(), TaskStatus::ChangesRequested, 'Two titles still run long.');
    $this->tasks->transition($this->tapu, $this->task->fresh(), TaskStatus::InProgress);
    $this->tasks->transition($this->tapu, $this->task->fresh(), TaskStatus::InReview, 'Both shortened.');

    Notification::query()->delete();

    $this->tasks->transition($this->admin, $this->task->fresh(), TaskStatus::Completed);

    expect(typesFor($this->tapu))->toBe([NotificationType::TaskCompleted->value])
        ->and(Notification::query()->forUser($this->tapu)->first()->summary())
        ->toBe('"'.$this->task->title.'" was completed');
})->group('phase2');

it('says a resubmission is back rather than repeating the first request', function () {
    // A second submission groups into the reviewer's existing unread row. Without a plural
    // branch the sentence still described the first one, so a reviewer who had already asked
    // for changes saw a bell that said exactly what it said an hour ago.
    Notification::query()->delete();

    $this->tasks->transition($this->tapu, $this->task->fresh(), TaskStatus::InProgress);
    $this->tasks->transition($this->tapu, $this->task->fresh(), TaskStatus::InReview, 'First pass.');

    // By type, not `first()`: moving to In progress notifies the creator too, and which of
    // the two rows is newest is not what this test is about.
    $review = fn () => Notification::query()->forUser($this->admin)
        ->where('type', NotificationType::TaskSubmittedForReview->value)
        ->first();

    expect($review()->summary())
        ->toBe('"'.$this->task->title.'" is waiting for your review');

    $this->tasks->transition($this->admin, $this->task->fresh(), TaskStatus::ChangesRequested, 'Titles run long.');
    $this->tasks->transition($this->tapu, $this->task->fresh(), TaskStatus::InProgress);
    $this->tasks->transition($this->tapu, $this->task->fresh(), TaskStatus::InReview, 'Shortened.');

    $row = $review();

    expect((int) $row->count)->toBe(2)
        ->and($row->summary())->toBe('"'.$this->task->title.'" is waiting for your review again');
})->group('phase2');

/*
|--------------------------------------------------------------------------
| Comments — the dedup example, end to end
|--------------------------------------------------------------------------
*/

it('collapses twelve real comments into one row reading "12 new comments"', function () {
    Notification::query()->delete();

    $conversation = $this->conversations->forTask($this->task);

    foreach (range(1, 12) as $i) {
        $this->conversations->post($this->admin, $conversation, 'Comment number '.$i);
    }

    $rows = Notification::query()->forUser($this->tapu)->get();

    expect($rows)->toHaveCount(1)
        ->and((int) $rows->first()->count)->toBe(12)
        ->and($rows->first()->summary())->toBe('12 new comments in "Rewrite the county landing pages"')
        ->and(typesFor($this->accountant))->toBe([]);
})->group('phase2');

it('does not tell an employee about a task they are not assigned to', function () {
    Notification::query()->delete();

    // Yaseen is an EMPLOYEE and is not on this task, so TaskPolicy::view says no and the
    // dispatcher drops him — the same 404-shaped rule the list endpoints enforce.
    $this->conversations->post($this->admin, $this->conversations->forTask($this->task), 'Anyone looking at this?');

    expect(typesFor($this->yaseen))->toBe([])
        ->and(typesFor($this->tapu))->toBe([NotificationType::TaskCommented->value]);
})->group('phase2');

/*
|--------------------------------------------------------------------------
| Deleted
|--------------------------------------------------------------------------
*/

it('tells the assignees when their task is deleted', function () {
    Notification::query()->delete();

    $this->tasks->delete($this->admin, $this->task->fresh());

    expect(typesFor($this->tapu))->toBe([NotificationType::TaskDeleted->value])
        ->and(Notification::query()->forUser($this->tapu)->first()->summary())
        ->toBe('"Rewrite the county landing pages" was deleted');
})->group('phase2');

/*
|--------------------------------------------------------------------------
| The cancelled project (Phase 1's side effect, finally connected)
|--------------------------------------------------------------------------
*/

it('prompts the PM and the admins to close or reassign a cancelled project\'s open tasks', function () {
    Notification::query()->delete();

    $openIds = Task::query()->where('project_id', $this->project->id)->open()->notArchived()->pluck('id');

    expect($openIds)->not->toBeEmpty();

    // Faruk cancels it, so the PM (Shahadat) is the one being prompted and the actor is not.
    $this->projects->changeStatus($this->faruk, $this->project, ProjectStatus::Cancelled, 'Client stopped paying');

    $row = Notification::query()->forUser($this->admin)->first();

    expect(typesFor($this->admin))->toBe([NotificationType::ProjectCancelled->value])
        ->and($row->type->tab()->value)->toBe('system')
        ->and($row->payload['context']['open_task_count'])->toBe($openIds->count())
        ->and($row->summary())->toContain('to close or reassign')
        ->and(typesFor($this->faruk))->toBe([])
        ->and(typesFor($this->accountant))->toBe([])
        // The tasks themselves are untouched: cancelling a project asks a person to decide, it
        // does not decide for them.
        ->and(Task::query()->where('project_id', $this->project->id)->open()->notArchived()->count())
        ->toBe($openIds->count());
})->group('phase2');

it('says nothing when a cancelled project has no open tasks left', function () {
    $empty = Project::factory()->create(['status' => ProjectStatus::Active, 'pm_id' => $this->admin->employee->id]);

    Notification::query()->delete();

    $this->projects->changeStatus($this->admin, $empty, ProjectStatus::Cancelled);

    expect(Notification::query()->count())->toBe(0);
})->group('phase2');
