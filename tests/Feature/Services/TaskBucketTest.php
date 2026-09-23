<?php

use App\Models\Employee;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskService;
use App\Support\RoleName;
use App\Support\TaskBucket;
use App\Support\TaskStatus;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Task buckets
|--------------------------------------------------------------------------
|
| A bucket is a question — "what is late", "what is due today", "what is
| waiting on a reviewer" — and `TaskBucket::apply()` is the only place any of
| them is spelled out. These tests are about the two properties that make the
| My Tasks page and the dashboard cards trustworthy:
|
|   1. A count is a COUNT, scoped by Task::visibleTo(), over the whole bucket —
|      never a length taken from a page of rows.
|   2. A card's number and the list its link opens are the SAME predicate. The
|      overdue bucket is `Task::scopeOverdue()`, which is also what
|      `TaskService::overdue()` and the `overdue` filter use, so there is one
|      definition of late and not three.
|
| `mine` is the other half. An Admin's Task::visibleTo() is the whole agency,
| so "my plate" has to be an explicit narrowing — and a user with no employee
| row owns no tasks rather than everyone's.
|
*/

beforeEach(function () {
    // Roles and their permission keys, and nothing else: Task::visibleTo() refuses a user
    // whose role holds no tasks.view, so without this every bucket would be empty for the
    // right reason and the wrong one at once. The demo tasks are deliberately NOT seeded —
    // these tests count their own fixtures, and a count is only a proof if you know the
    // answer.
    $this->seed(RolePermissionSeeder::class);

    $this->service = app(TaskService::class);
    $this->asOf = Carbon::parse('2026-09-22');

    $this->project = Project::factory()->create();

    $this->admin = Employee::factory()->forRole(RoleName::ADMIN)->create();
    $this->worker = Employee::factory()->forRole(RoleName::EMPLOYEE)->create();

    // The admin's own plate: one of each shape the seven buckets ask about.
    $this->adminOverdue = Task::factory()
        ->for($this->project)
        ->overdue($this->asOf)
        ->assignedTo($this->admin)
        ->create();

    $this->adminDueToday = Task::factory()
        ->for($this->project)
        ->status(TaskStatus::Todo)
        ->assignedTo($this->admin)
        ->create(['start_date' => $this->asOf, 'due_date' => $this->asOf]);

    $this->adminWaiting = Task::factory()
        ->for($this->project)
        ->status(TaskStatus::Waiting)
        ->assignedTo($this->admin)
        ->create(['due_date' => $this->asOf->copy()->addWeek()]);

    $this->adminInReview = Task::factory()
        ->for($this->project)
        ->status(TaskStatus::InReview)
        ->assignedTo($this->admin)
        ->create(['due_date' => $this->asOf->copy()->addWeek()]);

    // Finished today, and one finished yesterday that "Completed today" must not count.
    $this->adminDoneToday = Task::factory()
        ->for($this->project)
        ->status(TaskStatus::Completed)
        ->assignedTo($this->admin)
        ->create(['due_date' => $this->asOf, 'completed_at' => $this->asOf->copy()->setTime(11, 0)]);

    $this->adminDoneYesterday = Task::factory()
        ->for($this->project)
        ->status(TaskStatus::Completed)
        ->assignedTo($this->admin)
        ->create([
            'due_date' => $this->asOf->copy()->subDay(),
            'completed_at' => $this->asOf->copy()->subDay()->setTime(16, 0),
        ]);

    // Somebody else's late work. It is in the agency's overdue bucket and not the admin's.
    $this->workerOverdue = Task::factory()
        ->for($this->project)
        ->overdue($this->asOf)
        ->assignedTo($this->worker)
        ->create();

    $this->adminUser = $this->admin->user;
    $this->workerUser = $this->worker->user;
});

/** Shorthand: this user's count of one bucket, as of the fixed date. */
function bucketCount(User $user, TaskBucket $bucket, bool $mine = false): int
{
    return app(TaskService::class)->count($user, [
        'bucket' => $bucket->value,
        'mine' => $mine,
        'as_of' => test()->asOf,
    ]);
}

it('counts a bucket over the agency for an admin and over their own work for an employee', function () {
    // No `mine`: Task::visibleTo() gives an Admin every task, so both late ones are counted.
    expect(bucketCount($this->adminUser, TaskBucket::Overdue))->toBe(2);

    // The employee's own visibility already excludes the admin's work.
    expect(bucketCount($this->workerUser, TaskBucket::Overdue))->toBe(1)
        ->and(bucketCount($this->workerUser, TaskBucket::Overdue, mine: true))->toBe(1);

    // And an Admin's plate is theirs, not the agency's — this is the narrowing that makes
    // /admin/my-tasks a different screen from /admin/tasks.
    expect(bucketCount($this->adminUser, TaskBucket::Overdue, mine: true))->toBe(1);
})->group('phase2');

it('defines overdue exactly once — the bucket and TaskService::overdue() are the same rows', function () {
    $fromService = $this->service->overdue($this->adminUser, [], $this->asOf)->pluck('id')->sort()->values();

    $fromBucket = $this->service
        ->query($this->adminUser, ['bucket' => TaskBucket::Overdue->value, 'as_of' => $this->asOf])
        ->pluck('id')->sort()->values();

    expect($fromBucket->all())->toBe($fromService->all())
        ->and($fromBucket)->toHaveCount(2);

    // And the `overdue` chip on the Tasks List is the third caller of the same scope.
    expect($this->service->count($this->adminUser, ['overdue' => true, 'as_of' => $this->asOf]))
        ->toBe(2);
})->group('phase2');

it('counts only work somebody still owes in the Due today bucket', function () {
    // One task is due today and open; one is due today and finished this morning.
    expect(bucketCount($this->adminUser, TaskBucket::DueToday, mine: true))->toBe(1);

    $ids = $this->service
        ->query($this->adminUser, [
            'bucket' => TaskBucket::DueToday->value,
            'mine' => true,
            'as_of' => $this->asOf,
        ])->pluck('id')->all();

    expect($ids)->toBe([$this->adminDueToday->id])
        ->and($ids)->not->toContain($this->adminDoneToday->id);
})->group('phase2');

it('counts Completed today by when it was finished, not by when it was due', function () {
    expect(bucketCount($this->adminUser, TaskBucket::CompletedToday, mine: true))->toBe(1)
        // Both are completed; only one was completed today.
        ->and(bucketCount($this->adminUser, TaskBucket::Completed, mine: true))->toBe(2);

    $ids = $this->service
        ->query($this->adminUser, [
            'bucket' => TaskBucket::CompletedToday->value,
            'mine' => true,
            'as_of' => $this->asOf,
        ])->pluck('id')->all();

    expect($ids)->toBe([$this->adminDoneToday->id]);
})->group('phase2');

it('keeps the umbrella bucket to work that is still owed', function () {
    // Four open tasks on the admin's plate: overdue, due today, waiting, in review. The two
    // completed ones are not on a plate — that is what "Completed" is a separate bucket for.
    expect(bucketCount($this->adminUser, TaskBucket::Open, mine: true))->toBe(4)
        ->and(bucketCount($this->adminUser, TaskBucket::Waiting, mine: true))->toBe(1)
        ->and(bucketCount($this->adminUser, TaskBucket::InReview, mine: true))->toBe(1)
        ->and(bucketCount($this->adminUser, TaskBucket::InProgress, mine: true))->toBe(1);
})->group('phase2');

it('answers zero for somebody with nothing assigned, rather than refusing them', function () {
    // Zero is an answer. A person with an empty plate gets the page and seven noughts; the
    // dangerous failure is the opposite one — `mine` quietly doing nothing and handing an
    // Admin the agency's whole backlog as their own, which the first test above rules out.
    $idle = Employee::factory()->forRole(RoleName::EMPLOYEE)->create()->user;

    $counts = $this->service->bucketCounts($idle, TaskBucket::myTasks(), [
        'mine' => true,
        'as_of' => $this->asOf,
    ]);

    expect(array_values($counts))->toBe([0, 0, 0, 0, 0, 0, 0]);
})->group('phase2');

it('leaves archived tasks out of every bucket', function () {
    $this->adminOverdue->forceFill(['archived_at' => now()])->save();

    expect(bucketCount($this->adminUser, TaskBucket::Overdue, mine: true))->toBe(0)
        ->and(bucketCount($this->adminUser, TaskBucket::Open, mine: true))->toBe(3);
})->group('phase2');

it('counts the whole bucket, not the rows a screen happens to have loaded', function () {
    Task::factory()
        ->count(12)
        ->for($this->project)
        ->overdue($this->asOf)
        ->assignedTo($this->worker)
        ->create();

    $count = bucketCount($this->workerUser, TaskBucket::Overdue, mine: true);

    // The count is a COUNT; a screen that took five rows would have reported five.
    expect($count)->toBe(13)
        ->and($this->service
            ->query($this->workerUser, [
                'bucket' => TaskBucket::Overdue->value,
                'mine' => true,
                'as_of' => $this->asOf,
            ])
            ->limit(5)->get())
        ->toHaveCount(5);
})->group('phase2');

it('sends the seven My Tasks cards in the order the plan names them', function () {
    $cards = $this->service->bucketCards(
        $this->adminUser,
        TaskBucket::myTasks(),
        ['mine' => true, 'as_of' => $this->asOf],
    );

    expect(array_column($cards, 'key'))->toBe([
        'open', 'due_today', 'overdue', 'in_progress', 'waiting', 'in_review', 'completed',
    ])
        // The umbrella bucket is the filter `open` and the card "My tasks".
        ->and($cards[0]['label'])->toBe('My tasks')
        ->and(TaskBucket::Open->label())->toBe('Open')
        ->and($cards[2]['label'])->toBe('Overdue')
        ->and($cards[2]['count'])->toBe(1);
})->group('phase2');
