<?php

use App\Exceptions\TaskStateException;
use App\Models\ActivityLog;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tag;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\TaskLink;
use App\Models\User;
use App\Services\TaskService;
use App\Support\AuditEvent;
use App\Support\Permission as PermissionKey;
use App\Support\RoleName;
use App\Support\TaskPriority;
use App\Support\TaskStatus;
use App\Support\UserStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| TaskService — the write layer
|--------------------------------------------------------------------------
|
| Every rule the plan names, tested where it lives. The UI that would otherwise
| expose a mistake does not exist yet, so nothing here is left to a screen:
| the status machine per role, the work-summary requirement, the primary
| assignee's ownership of completion, the hand-off that moves it, the reopening
| that must not lose the first completion, soft delete, archive, the sparse
| position arithmetic, and the audit rows each of them writes.
|
*/

beforeEach(function () {
    $this->seed();

    $this->service = app(TaskService::class);

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();

    // A manager who is the PM of their own project, so the review verdicts have somebody to
    // come from who is not an Admin.
    $this->manager = Employee::factory()->forRole(RoleName::MANAGER)->create();
    $this->project = Project::factory()->create(['pm_id' => $this->manager->id]);
    $this->managerUser = $this->manager->user;

    $this->primary = Employee::where('employee_number', 'GT-003')->firstOrFail();   // Tapu
    $this->second = Employee::where('employee_number', 'GT-004')->firstOrFail();    // Yaseen
});

/**
 * A task on the manager's project, in a given status, assigned primary-first.
 */
function writable(TaskStatus $status, Employee ...$assignees): Task
{
    /** @var TestCase $test */
    $test = test();

    return Task::factory()
        ->status($status)
        ->assignedTo(...($assignees === [] ? [$test->primary] : $assignees))
        ->create(['project_id' => $test->project->id, 'position' => 1000])
        ->fresh(['project', 'assignees']);
}

/** Put a work summary on the task as if $author had written it. */
function summarisedBy(Task $task, User $author, string $summary = 'Done and checked.'): Task
{
    $task->forceFill([
        'work_summary' => $summary,
        'work_summary_by' => $author->id,
        'work_summary_at' => now(),
    ])->save();

    return $task->fresh(['project', 'assignees']);
}

/*
|--------------------------------------------------------------------------
| The status machine: every transition, allowed and forbidden, per role
|--------------------------------------------------------------------------
*/

it('lets exactly the transitions through that the policy allows, for every role', function (string $from) {
    $fromStatus = TaskStatus::from($from);

    $actors = [
        'ADMIN' => $this->admin,
        'MANAGER' => $this->managerUser,
        'REMOTE_EMPLOYEE' => $this->tapu,
        'ACCOUNTANT' => $this->accountant,
    ];

    $wrong = [];

    // Every status, not only the legal targets: an illegal move must be refused as loudly as
    // an unauthorised one, and for a different reason.
    foreach (TaskStatus::cases() as $to) {
        foreach ($actors as $role => $actor) {
            $task = writable($fromStatus, $this->primary);
            // Both requirements out of the way, so what is left is the permission question.
            $task = summarisedBy($task, $this->primary->user);

            $allowed = Gate::forUser($actor)->allows('transition', [$task, $to]);
            $refused = null;

            try {
                $this->service->transition($actor, $task, $to, null, 'because the test says so');
            } catch (AuthorizationException) {
                $refused = 'authorization';
            } catch (TaskStateException) {
                $refused = 'state';
            }

            $legal = $fromStatus->canTransitionTo($to);

            if ($allowed && $refused !== null) {
                $wrong[] = "{$from} → {$to->value} as {$role}: policy allows it, service refused ({$refused})";
            }

            if (! $allowed && $refused === null) {
                $wrong[] = "{$from} → {$to->value} as {$role}: policy forbids it, service let it through";
            }

            if (! $legal && $refused === 'authorization') {
                $wrong[] = "{$from} → {$to->value} as {$role}: illegal move refused as a permission problem";
            }
        }
    }

    expect($wrong)->toBe([]);
})->with(array_map(
    fn (TaskStatus $status): array => [$status->value],
    TaskStatus::cases(),
))->group('phase2');

it('never lets an employee reach completed, however they ask', function () {
    $task = summarisedBy(writable(TaskStatus::InReview), $this->primary->user);

    expect(fn () => $this->service->transition($this->tapu, $task, TaskStatus::Completed))
        ->toThrow(AuthorizationException::class);

    // …and the same call with a drag's payload — a position to land in — is the same call.
    $other = writable(TaskStatus::InReview);

    expect(fn () => $this->service->transition($this->tapu, $task, TaskStatus::Completed, null, null, $other))
        ->toThrow(AuthorizationException::class);

    expect($task->fresh()->status)->toBe(TaskStatus::InReview);
})->group('phase2');

it('lets an employee reach in review, which is what the work summary is for', function () {
    $task = writable(TaskStatus::InProgress);

    // Without a summary the move is refused by the task's state, not by permission.
    expect(fn () => $this->service->transition($this->tapu, $task, TaskStatus::InReview))
        ->toThrow(TaskStateException::class);

    expect($task->fresh()->status)->toBe(TaskStatus::InProgress);

    $moved = $this->service->transition($this->tapu, $task, TaskStatus::InReview, 'Rewrote all eight titles.');

    expect($moved->status)->toBe(TaskStatus::InReview)
        ->and($moved->work_summary)->toBe('Rewrote all eight titles.')
        ->and($moved->work_summary_by)->toBe($this->tapu->id);
})->group('phase2');

it('refuses a cancellation or a reopening with no reason', function () {
    $cancellable = writable(TaskStatus::InProgress);

    expect(fn () => $this->service->transition($this->admin, $cancellable, TaskStatus::Cancelled))
        ->toThrow(TaskStateException::class);

    $completed = summarisedBy(writable(TaskStatus::Completed), $this->primary->user);

    expect(fn () => $this->service->transition($this->admin, $completed, TaskStatus::InProgress))
        ->toThrow(TaskStateException::class);

    expect($cancellable->fresh()->status)->toBe(TaskStatus::InProgress)
        ->and($completed->fresh()->status)->toBe(TaskStatus::Completed);
})->group('phase2');

/*
|--------------------------------------------------------------------------
| Completion: the primary assignee's work summary, or an explicit hand-off
|--------------------------------------------------------------------------
*/

it('completes only on the primary assignee\'s work summary', function () {
    $task = writable(TaskStatus::InReview, $this->primary, $this->second);

    // The SECOND assignee submitted the work. That is allowed — both of them work on it —
    // but it is not what completion needs.
    $task = summarisedBy($task, $this->second->user);

    // The reviewer here is the project's PM, so the verdict comes from the manager — an Admin
    // who is not this project's PM could not pass it either.
    expect(fn () => $this->service->transition($this->managerUser, $task, TaskStatus::Completed))
        ->toThrow(TaskStateException::class);

    expect($task->fresh()->status)->toBe(TaskStatus::InReview);

    // The primary's summary completes it.
    $task = summarisedBy($task->fresh(['project', 'assignees']), $this->primary->user, 'All eight canonicals fixed.');

    $completed = $this->service->transition($this->managerUser, $task, TaskStatus::Completed);

    expect($completed->status)->toBe(TaskStatus::Completed)
        ->and($completed->completed_by)->toBe($this->managerUser->id)
        ->and($completed->completed_at)->not->toBeNull();
})->group('phase2');

it('refuses a completion with no work summary at all', function () {
    $task = writable(TaskStatus::InReview);

    expect(fn () => $this->service->transition($this->managerUser, $task, TaskStatus::Completed))
        ->toThrow(TaskStateException::class);
})->group('phase2');

it('lets a hand-off move who completion belongs to', function () {
    $task = writable(TaskStatus::InReview, $this->primary, $this->second);
    $task = summarisedBy($task, $this->second->user, 'I picked this up while Tapu was away.');

    // Refused before the hand-off…
    expect(fn () => $this->service->transition($this->managerUser, $task, TaskStatus::Completed))
        ->toThrow(TaskStateException::class);

    $task = $this->service->handOff(
        $this->admin,
        $task->fresh(['project', 'assignees']),
        $this->second,
        'Tapu is on leave until the 12th.',
    );

    expect($task->primary()?->id)->toBe($this->second->id);

    // …and accepted after it, on the same summary, because it is now the primary's.
    $completed = $this->service->transition(
        $this->managerUser,
        $task->fresh(['project', 'assignees']),
        TaskStatus::Completed,
    );

    expect($completed->status)->toBe(TaskStatus::Completed);
})->group('phase2');

it('audits a hand-off as a reassignment, with the reason', function () {
    $task = writable(TaskStatus::InProgress, $this->primary, $this->second);

    $this->service->handOff($this->admin, $task, $this->second, 'Tapu is on leave.');

    $audit = AuditLog::where('event', AuditEvent::TaskReassigned->value)->latest('id')->firstOrFail();

    expect($audit->old_value['primary_employee_id'])->toBe($this->primary->id)
        ->and($audit->new_value['primary_employee_id'])->toBe($this->second->id)
        ->and($audit->new_value['reason'])->toBe('Tapu is on leave.')
        ->and($audit->new_value['hand_off'])->toBeTrue()
        ->and($audit->actor_id)->toBe($this->admin->id);
})->group('phase2');

it('refuses a hand-off to somebody who is not on the task', function () {
    $task = writable(TaskStatus::InProgress, $this->primary);
    $outsider = Employee::where('employee_number', 'GT-004')->firstOrFail();

    expect(fn () => $this->service->handOff($this->admin, $task, $outsider, 'because'))
        ->toThrow(TaskStateException::class);

    expect($task->fresh()->primary()?->id)->toBe($this->primary->id);
})->group('phase2');

it('lets the primary hand their own work over but not a bystander', function () {
    $task = writable(TaskStatus::InProgress, $this->primary, $this->second);

    // The second assignee may not promote themselves: that is the hole the rule exists to close.
    expect(fn () => $this->service->handOff($this->second->user, $task, $this->second, 'I will finish it'))
        ->toThrow(AuthorizationException::class);

    $handed = $this->service->handOff($this->primary->user, $task, $this->second, 'Off sick.');

    expect($handed->primary()?->id)->toBe($this->second->id);
})->group('phase2');

/*
|--------------------------------------------------------------------------
| Reopening: the first completion survives
|--------------------------------------------------------------------------
*/

it('keeps the original completion when a task is reopened', function () {
    $task = summarisedBy(writable(TaskStatus::InReview), $this->primary->user, 'First pass, all eight fixed.');

    $completed = $this->service->transition($this->managerUser, $task, TaskStatus::Completed);

    $firstAt = $completed->completed_at;
    $firstBy = $completed->completed_by;

    expect($firstAt)->not->toBeNull()
        ->and($completed->first_completed_at?->toIso8601String())->toBe($firstAt?->toIso8601String())
        ->and($completed->first_work_summary)->toBe('First pass, all eight fixed.');

    $reopened = $this->service->transition(
        $this->admin,
        $completed->fresh(['project', 'assignees']),
        TaskStatus::InProgress,
        null,
        'Two of the canonicals came back wrong.',
    );

    expect($reopened->status)->toBe(TaskStatus::InProgress)
        // The live completion is gone, because the task is genuinely not complete.
        ->and($reopened->completed_at)->toBeNull()
        ->and($reopened->completed_by)->toBeNull()
        // The first one is still readable, on the row, as a field and not as prose in a log.
        ->and($reopened->first_completed_at?->toIso8601String())->toBe($firstAt?->toIso8601String())
        ->and($reopened->first_completed_by)->toBe($firstBy)
        ->and($reopened->first_work_summary)->toBe('First pass, all eight fixed.')
        ->and($reopened->wasEverCompleted())->toBeTrue();
})->group('phase2');

it('keeps the FIRST completion through a second one', function () {
    $task = summarisedBy(writable(TaskStatus::InReview), $this->primary->user, 'First pass.');

    $completed = $this->service->transition($this->managerUser, $task, TaskStatus::Completed);
    $firstAt = $completed->first_completed_at;

    $reopened = $this->service->transition(
        $this->admin,
        $completed->fresh(['project', 'assignees']),
        TaskStatus::InProgress,
        null,
        'Came back wrong.',
    );

    $again = $this->service->transition(
        $this->tapu,
        $reopened->fresh(['project', 'assignees']),
        TaskStatus::InReview,
        'Second pass, properly this time.',
    );

    $completedAgain = $this->service->transition(
        $this->managerUser,
        $again->fresh(['project', 'assignees']),
        TaskStatus::Completed,
    );

    expect($completedAgain->first_completed_at?->toIso8601String())->toBe($firstAt?->toIso8601String())
        ->and($completedAgain->first_work_summary)->toBe('First pass.')
        // …while the live completion is the new one.
        ->and($completedAgain->work_summary)->toBe('Second pass, properly this time.');
})->group('phase2');

it('logs a reopening with its actor and its reason, and says the first completion is kept', function () {
    $task = summarisedBy(writable(TaskStatus::InReview), $this->primary->user);
    $completed = $this->service->transition($this->managerUser, $task, TaskStatus::Completed);

    // Reopening is an Admin move, whoever completed it.
    $this->service->transition(
        $this->admin,
        $completed->fresh(['project', 'assignees']),
        TaskStatus::InProgress,
        null,
        'The client rejected it.',
    );

    $line = ActivityLog::where('object_type', $task->getMorphClass())
        ->where('object_id', $task->id)
        ->latest('id')
        ->firstOrFail();

    expect($line->description)->toContain('Reopened')
        ->and($line->description)->toContain('The client rejected it.')
        ->and($line->description)->toContain('kept')
        ->and($line->actor_id)->toBe($this->admin->id);

    $audit = AuditLog::where('event', AuditEvent::TaskStatusChanged->value)->latest('id')->firstOrFail();

    expect($audit->old_value['status'])->toBe('completed')
        ->and($audit->new_value['status'])->toBe('in_progress')
        ->and($audit->new_value['reason'])->toBe('The client rejected it.')
        // The audit row itself carries the preserved completion, so an auditor can see the
        // reopening did not take it.
        ->and($audit->new_value['first_completed_at'])->not->toBeNull();
})->group('phase2');

/*
|--------------------------------------------------------------------------
| The status machine has one door
|--------------------------------------------------------------------------
*/

it('throws if anything writes a status without going through the machine', function () {
    $task = writable(TaskStatus::Todo);

    // What a future drag handler, a job, or a controller filling from request input would do.
    expect(fn () => $task->forceFill(['status' => TaskStatus::Completed])->save())
        ->toThrow(TaskStateException::class);

    expect($task->fresh()->status)->toBe(TaskStatus::Todo);
})->group('phase2');

it('refuses an illegal status even through its own door', function () {
    $task = writable(TaskStatus::Todo);

    // applyTransition() is the sanctioned write and it still checks the map, so no caller —
    // sanctioned or not — can park a task where the machine has no edge.
    expect(fn () => $task->applyTransition(TaskStatus::Completed))
        ->toThrow(TaskStateException::class);
})->group('phase2');

it('drops a status handed to the general update, exactly as a project does', function () {
    $task = writable(TaskStatus::Todo);

    $updated = $this->service->update($this->admin, $task, [
        'title' => 'Renamed',
        'status' => TaskStatus::Completed->value,
    ]);

    expect($updated->title)->toBe('Renamed')
        ->and($updated->status)->toBe(TaskStatus::Todo);
})->group('phase2');

/*
|--------------------------------------------------------------------------
| Create, assign, delete, archive
|--------------------------------------------------------------------------
*/

it('creates a task in backlog, at the bottom of the column, with its assignees audited', function () {
    Task::factory()->status(TaskStatus::Backlog)->create([
        'project_id' => $this->project->id,
        'position' => 1000,
    ]);

    $task = $this->service->create($this->admin, [
        'project_id' => $this->project->id,
        'title' => 'A new task',
        'priority' => TaskPriority::High->value,
        // Ignored: a task is born in backlog or to do, never anywhere else.
        'status' => TaskStatus::Completed->value,
    ], [$this->primary->id, $this->second->id], $this->second->id);

    expect($task->status)->toBe(TaskStatus::Backlog)
        ->and($task->created_by)->toBe($this->admin->id)
        ->and($task->position)->toBe(2000)
        ->and($task->primary()?->id)->toBe($this->second->id);

    $audit = AuditLog::where('event', AuditEvent::TaskAssigned->value)->latest('id')->firstOrFail();

    expect($audit->new_value['assignee_ids'])->toEqualCanonicalizing([$this->primary->id, $this->second->id])
        ->and($audit->new_value['primary_employee_id'])->toBe($this->second->id);
})->group('phase2');

it('can be born in to do, and in nothing else', function () {
    $todo = $this->service->create($this->admin, [
        'project_id' => $this->project->id,
        'title' => 'Start here',
        'status' => TaskStatus::Todo->value,
    ]);

    $review = $this->service->create($this->admin, [
        'project_id' => $this->project->id,
        'title' => 'Not like this',
        'status' => TaskStatus::InReview->value,
    ]);

    expect($todo->status)->toBe(TaskStatus::Todo)
        ->and($review->status)->toBe(TaskStatus::Backlog);
})->group('phase2');

it('audits a reassignment as a reassignment, and a first assignment as an assignment', function () {
    $task = Task::factory()->create(['project_id' => $this->project->id]);

    $this->service->syncAssignees($this->admin, $task->fresh(['project', 'assignees']), [$this->primary->id]);

    expect(AuditLog::where('event', AuditEvent::TaskAssigned->value)->latest('id')->first()?->target_id)
        ->toBe($task->id);

    $this->service->syncAssignees($this->admin, $task->fresh(['project', 'assignees']), [$this->second->id]);

    $reassigned = AuditLog::where('event', AuditEvent::TaskReassigned->value)->latest('id')->firstOrFail();

    expect($reassigned->old_value['assignee_ids'])->toBe([$this->primary->id])
        ->and($reassigned->new_value['assignee_ids'])->toBe([$this->second->id]);
})->group('phase2');

it('refuses more than two assignees, and a primary who is not one of them', function () {
    $task = Task::factory()->create(['project_id' => $this->project->id])->fresh(['project', 'assignees']);
    $third = Employee::where('employee_number', 'GT-001')->firstOrFail();

    expect(fn () => $this->service->syncAssignees($this->admin, $task, [
        $this->primary->id, $this->second->id, $third->id,
    ]))->toThrow(TaskStateException::class);

    expect(fn () => $this->service->syncAssignees($this->admin, $task, [$this->primary->id], $this->second->id))
        ->toThrow(TaskStateException::class);
})->group('phase2');

it('keeps an employee out of the assignee list', function () {
    $task = writable(TaskStatus::InProgress, $this->primary);

    expect(fn () => $this->service->syncAssignees($this->tapu, $task, [$this->second->id]))
        ->toThrow(AuthorizationException::class);
})->group('phase2');

it('soft-deletes a task and leaves its history readable', function () {
    $task = writable(TaskStatus::InProgress);
    $id = $task->id;

    $this->service->transition($this->tapu, $task->fresh(['project', 'assignees']), TaskStatus::InReview, 'Ready.');

    $this->service->delete($this->admin, $task->fresh(['project', 'assignees']));

    expect(Task::find($id))->toBeNull()
        ->and(Task::withTrashed()->find($id)?->deleted_at)->not->toBeNull()
        // The history points at a row that is still there to point at.
        ->and(ActivityLog::where('object_id', $id)->where('object_type', $task->getMorphClass())->count())
        ->toBeGreaterThan(0);

    $audit = AuditLog::where('event', AuditEvent::TaskDeleted->value)->latest('id')->firstOrFail();

    expect($audit->target_id)->toBe($id)
        ->and($audit->old_value['title'])->toBe($task->title)
        ->and($audit->old_value['status'])->toBe('in_review')
        ->and($audit->new_value)->toBeNull();
})->group('phase2');

it('lets nobody but an admin or a manager delete', function () {
    $task = writable(TaskStatus::InProgress);

    expect(fn () => $this->service->delete($this->tapu, $task))->toThrow(AuthorizationException::class);
    expect(Task::find($task->id))->not->toBeNull();

    $this->service->delete($this->managerUser, $task->fresh(['project', 'assignees']));

    expect(Task::find($task->id))->toBeNull();
})->group('phase2');

it('hides an archived task from the active views and brings it back', function () {
    $task = writable(TaskStatus::InProgress);

    $this->service->archive($this->admin, $task);

    $ids = fn (array $grouped): array => collect($grouped['groups'])
        ->flatMap(fn (array $group) => collect($group['tasks'])->pluck('id'))
        ->all();

    expect($ids($this->service->grouped($this->admin)))->not->toContain($task->id)
        ->and($ids($this->service->grouped($this->admin, ['archived' => true])))->toContain($task->id);

    // …and it is read-only while it is there.
    expect(fn () => $this->service->update($this->admin, $task->fresh(['project', 'assignees']), ['title' => 'Nope']))
        ->toThrow(TaskStateException::class);

    // Only an Admin brings it back.
    expect(fn () => $this->service->unarchive($this->managerUser, $task->fresh(['project', 'assignees'])))
        ->toThrow(AuthorizationException::class);

    $back = $this->service->unarchive($this->admin, $task->fresh(['project', 'assignees']));

    expect($back->isArchived())->toBeFalse()
        ->and($back->status)->toBe(TaskStatus::InProgress)
        ->and($ids($this->service->grouped($this->admin)))->toContain($task->id);
})->group('phase2');

/*
|--------------------------------------------------------------------------
| Position: sparse, midpoint on drop, renumber only when they run out
|--------------------------------------------------------------------------
*/

it('drops a card on the midpoint between its new neighbours', function () {
    $a = Task::factory()->status(TaskStatus::Todo)->create(['project_id' => $this->project->id, 'position' => 1000]);
    $b = Task::factory()->status(TaskStatus::Todo)->create(['project_id' => $this->project->id, 'position' => 2000]);
    $moved = Task::factory()->status(TaskStatus::Todo)->create(['project_id' => $this->project->id, 'position' => 3000]);

    $this->service->reorder($this->admin, $moved->fresh(['project', 'assignees']), $a);

    expect($moved->fresh()->position)->toBe(1500)
        // Nothing else moved: that is what the sparse numbering buys.
        ->and($a->fresh()->position)->toBe(1000)
        ->and($b->fresh()->position)->toBe(2000);
})->group('phase2');

it('puts a card dropped at the top above everything, without renumbering', function () {
    $first = Task::factory()->status(TaskStatus::Todo)->create(['project_id' => $this->project->id, 'position' => 1000]);
    $moved = Task::factory()->status(TaskStatus::Todo)->create(['project_id' => $this->project->id, 'position' => 2000]);

    $this->service->reorder($this->admin, $moved->fresh(['project', 'assignees']), null);

    expect($moved->fresh()->position)->toBe(500)
        ->and($first->fresh()->position)->toBe(1000);
})->group('phase2');

it('renumbers the lane, and only the lane, when the midpoints run out', function () {
    $a = Task::factory()->status(TaskStatus::Todo)->create(['project_id' => $this->project->id, 'position' => 1000]);
    $b = Task::factory()->status(TaskStatus::Todo)->create(['project_id' => $this->project->id, 'position' => 1001]);
    $moved = Task::factory()->status(TaskStatus::Todo)->create(['project_id' => $this->project->id, 'position' => 9000]);

    // A card in another lane, which must not be touched.
    $elsewhere = Task::factory()->status(TaskStatus::Backlog)->create(['project_id' => $this->project->id, 'position' => 1001]);

    // A card in the SAME status on another project. It shares the lane — the Board's columns
    // are statuses across every project — so it renumbers with the rest. This assertion used
    // to say the opposite, back when a lane was a (project, status) pair and a manual order
    // could only ever reorder a card against its own project's cards.
    $otherProject = Task::factory()->status(TaskStatus::Todo)->create(['position' => 1002]);

    $this->service->reorder($this->admin, $moved->fresh(['project', 'assignees']), $a);

    // Absolute numbers are not the claim — the lane spans every project, so the seed's own
    // To-do cards are in it too and a renumber walks the whole thing. What must hold is the
    // ORDER, and that the renumber left a gap big enough to drop into again.
    $positions = fn (): array => [
        (int) $a->fresh()->position,
        (int) $moved->fresh()->position,
        (int) $b->fresh()->position,
        (int) $otherProject->fresh()->position,
    ];

    [$pa, $pMoved, $pb, $pOther] = $positions();

    expect($pa)->toBeLessThan($pMoved)
        ->and($pMoved)->toBeLessThan($pb)
        ->and($pb)->toBeLessThan($pOther)
        // Spread apart again, not left crammed at consecutive integers.
        ->and($pMoved - $pa)->toBeGreaterThan(1)
        // The other lane is untouched, which is the "and only the lane" half.
        ->and((int) $elsewhere->fresh()->position)->toBe(1001);
})->group('phase2');

it('reorders a card against one from a different project in the same lane', function () {
    // The case the Board actually produces: a lane mixes projects, so the card you drop under
    // is very often somebody else's. Before, this threw "A task can only be reordered against
    // a card in the same column" and the screen had to walk the anchor backwards to avoid it.
    $anchor = Task::factory()->status(TaskStatus::Todo)->create(['position' => 1000]);
    $moved = Task::factory()->status(TaskStatus::Todo)->create(['project_id' => $this->project->id, 'position' => 5000]);

    expect($anchor->project_id)->not->toBe($moved->project_id);

    $this->service->reorder($this->admin, $moved->fresh(['project', 'assignees']), $anchor);

    expect($moved->fresh()->position)->toBeGreaterThan((int) $anchor->fresh()->position);
})->group('phase2');

it('lands a card that changed column at the bottom of the new one', function () {
    Task::factory()->status(TaskStatus::InProgress)->create(['project_id' => $this->project->id, 'position' => 4000]);
    $moved = writable(TaskStatus::Todo);

    $after = $this->service->transition($this->admin, $moved, TaskStatus::InProgress);

    expect($after->position)->toBe(5000);
})->group('phase2');

it('refuses a reorder against a card in another column', function () {
    $moved = writable(TaskStatus::Todo);
    $elsewhere = Task::factory()->status(TaskStatus::Backlog)->create(['project_id' => $this->project->id]);

    expect(fn () => $this->service->reorder($this->admin, $moved, $elsewhere))
        ->toThrow(TaskStateException::class);
})->group('phase2');

/*
|--------------------------------------------------------------------------
| Checklist, links, dependencies
|--------------------------------------------------------------------------
*/

it('keeps a checklist, records who ticked a line, and clears it on an untick', function () {
    $task = writable(TaskStatus::InProgress);

    $first = $this->service->addChecklistItem($this->admin, $task, 'Draft it');
    $second = $this->service->addChecklistItem($this->admin, $task->fresh(['project', 'assignees']), 'Check it');

    expect([$first->position, $second->position])->toBe([1000, 2000]);

    $ticked = $this->service->updateChecklistItem($this->tapu, $first, ['is_done' => true]);

    expect($ticked->is_done)->toBeTrue()
        ->and($ticked->completed_by)->toBe($this->tapu->id)
        ->and($ticked->completed_at)->not->toBeNull();

    $unticked = $this->service->updateChecklistItem($this->tapu, $ticked, ['is_done' => false]);

    expect($unticked->completed_by)->toBeNull()
        ->and($unticked->completed_at)->toBeNull();

    $this->service->removeChecklistItem($this->admin, $unticked);

    expect(TaskChecklistItem::find($first->id))->toBeNull();
})->group('phase2');

it('keeps links, and labels an unlabelled one with its host', function () {
    $task = writable(TaskStatus::InProgress);

    $link = $this->service->addLink($this->admin, $task, 'https://example.com/a/very/long/path', null);

    expect($link->displayLabel())->toBe('example.com');

    $this->service->removeLink($this->tapu, $link);

    expect(TaskLink::find($link->id))->toBeNull();
})->group('phase2');

it('refuses a dependency on itself, across projects, or in a cycle', function () {
    $a = writable(TaskStatus::Todo);
    $b = Task::factory()->status(TaskStatus::Todo)->create(['project_id' => $this->project->id]);
    $c = Task::factory()->status(TaskStatus::Todo)->create(['project_id' => $this->project->id]);
    $elsewhere = Task::factory()->status(TaskStatus::Todo)->create();

    expect(fn () => $this->service->addDependency($this->admin, $a, $a))
        ->toThrow(TaskStateException::class);

    expect(fn () => $this->service->addDependency($this->admin, $a, $elsewhere))
        ->toThrow(TaskStateException::class);

    $this->service->addDependency($this->admin, $a->fresh(['project', 'assignees']), $b);
    $this->service->addDependency($this->admin, $b->fresh(['project', 'assignees']), $c);

    // c → a would close the loop a → b → c.
    expect(fn () => $this->service->addDependency($this->admin, $c->fresh(['project', 'assignees']), $a))
        ->toThrow(TaskStateException::class);

    expect($a->fresh()->dependencies()->pluck('tasks.id')->all())->toBe([$b->id])
        ->and($b->fresh()->dependents()->pluck('tasks.id')->all())->toBe([$a->id]);

    $this->service->removeDependency($this->admin, $a->fresh(['project', 'assignees']), $b);

    expect($a->fresh()->dependencies()->count())->toBe(0);
})->group('phase2');

/*
|--------------------------------------------------------------------------
| Reviewer resolution, in one place
|--------------------------------------------------------------------------
*/

it('resolves the reviewer as the project PM, and as every admin when there is none', function () {
    $withPm = writable(TaskStatus::InReview);

    expect($this->service->reviewersFor($withPm)->pluck('id')->all())
        ->toBe([$this->managerUser->id]);

    $this->project->forceFill(['pm_id' => null])->save();

    $withoutPm = $withPm->fresh(['project', 'assignees']);
    $admins = $this->service->reviewersFor($withoutPm)->pluck('email')->all();

    expect($admins)->toContain('shahadat@goodtechies.test')
        ->and($admins)->toContain('faruk@goodtechies.test')
        ->and($admins)->not->toContain('tapu@goodtechies.test');

    // And the policy asks the same question of the same code.
    expect(Gate::forUser($this->admin)->allows('review', $withoutPm))->toBeTrue()
        ->and(Gate::forUser($this->managerUser)->allows('review', $withoutPm))->toBeFalse();
})->group('phase2');

/*
|--------------------------------------------------------------------------
| Tags
|--------------------------------------------------------------------------
|
| Assigning an existing tag is an edit and rides on update(); creating one is
| slice 4's and is reachable from nowhere here.
|
*/

it('refuses another project\'s tag even when the Form Request is not in the way', function () {
    // UpdateTaskRequest says no first, with a message against the field. This is the same rule
    // at the only place that writes `task_tags`, so a job or a console command calling the
    // service directly is refused too — a validation rule alone would only be true over HTTP.
    $task = writable(TaskStatus::Todo);
    $foreign = Tag::factory()->forProject(Project::factory()->create())->create();

    expect(fn () => $this->service->update($this->admin, $task, ['tag_ids' => [$foreign->id]]))
        ->toThrow(TaskStateException::class);

    expect($task->fresh()->tags)->toHaveCount(0);
})->group('phase2');

it('records a tag change on the task\'s timeline and says nothing when nothing changed', function () {
    $task = writable(TaskStatus::Todo);
    $global = Tag::factory()->create(['name' => 'Retainer']);

    $this->service->update($this->admin, $task, ['tag_ids' => [$global->id]]);

    $tagged = fn (string $line): int => ActivityLog::where('object_type', $task->getMorphClass())
        ->where('object_id', $task->id)
        ->where('description', $line)
        ->count();

    expect($tagged('Tagged: Retainer'))->toBe(1);

    // The same list again is not an edit, and does not get a line of its own.
    $this->service->update($this->admin, $task->fresh(), ['tag_ids' => [$global->id]]);

    expect($tagged('Tagged: Retainer'))->toBe(1);

    // And an empty list is a change like any other.
    $this->service->update($this->admin, $task->fresh(), ['tag_ids' => []]);

    expect($task->fresh()->tags)->toHaveCount(0)
        ->and($tagged('Tags cleared'))->toBe(1);
})->group('phase2');

it('drops a tag that cannot travel when the task changes project, and logs that it did', function () {
    $task = writable(TaskStatus::Todo);
    $global = Tag::factory()->create(['name' => 'Retainer']);
    $mine = Tag::factory()->forProject($this->project)->create(['name' => 'Old project only']);
    $task->tags()->sync([$global->id, $mine->id]);

    $elsewhere = Project::factory()->create();

    $this->service->update($this->admin, $task->fresh(), ['project_id' => $elsewhere->id]);

    expect($task->fresh()->tags->pluck('id')->all())->toBe([$global->id])
        ->and(ActivityLog::where('object_type', $task->getMorphClass())
            ->where('object_id', $task->id)
            ->where('description', 'Tags dropped with the move to another project: Old project only')
            ->exists())->toBeTrue();
})->group('phase2');

/*
|--------------------------------------------------------------------------
| Who may hold a task
|--------------------------------------------------------------------------
*/

it('answers the assignee question with a permission, not a list of roles', function () {
    $ids = fn (): array => $this->service->assignableEmployees()->pluck('id')->all();

    $accountant = Employee::where('employee_number', 'GT-005')->firstOrFail();

    // The Accountant holds no tasks.* permission, so a task given to them is a task they
    // cannot even open — TaskPolicy::view() falls at its first line.
    expect(Gate::forUser($this->accountant)->allows('viewAny', Task::class))->toBeFalse()
        ->and($ids())->not->toContain($accountant->id)
        ->and($ids())->toContain($this->primary->id);

    $tasksView = Permission::where('key', PermissionKey::TasksView->value)->firstOrFail();

    // The filter is the permission. Give a role that could not hold a task the one permission
    // that makes a task workable and its people appear; take it off a role that had it and
    // its people leave — with nothing in TaskService changing either time. That is what keeps
    // it true for a role that does not exist yet.
    Role::where('name', RoleName::ACCOUNTANT->value)->firstOrFail()->permissions()->attach($tasksView->id);
    Role::where('name', RoleName::REMOTE_EMPLOYEE->value)->firstOrFail()->permissions()->detach($tasksView->id);

    expect($ids())->toContain($accountant->id)
        ->and($ids())->not->toContain($this->primary->id);
})->group('phase2');

it('keeps an inactive employee out of the assignee answer', function () {
    $this->second->forceFill(['status' => UserStatus::Inactive->value])->save();

    expect($this->service->assignableEmployees()->pluck('id')->all())->not->toContain($this->second->id);
})->group('phase2');
