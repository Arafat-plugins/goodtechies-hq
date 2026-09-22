<?php

use App\Models\Employee;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskService;
use App\Support\RoleName;
use App\Support\TaskStatus;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| TaskService — the date window, the board shape and the calendar payload
|--------------------------------------------------------------------------
|
| The predicate under test is an OVERLAP, not a containment:
|
|     coalesce(due_date, start_date) >= date_from
|     coalesce(start_date, due_date) <= date_to
|
| `whereBetween('due_date', …)` is the obvious thing to write here and it is
| wrong in the direction that loses work: a task started in August and due in
| September is being worked on for the first three days of September and the
| last four of August, and belongs on both months' grids. The first test below
| is exactly the case whereBetween drops.
|
| Every test pins its dates and its project. A calendar test that trusted
| today() would pass in September and fail in October, and one that trusted the
| seed's tasks would fail the day somebody moved one.
|
*/

beforeEach(function () {
    $this->seed();

    $this->service = app(TaskService::class);
    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();
    $this->manager = Employee::factory()->forRole(RoleName::MANAGER)->create()->user;

    // A project of this test's own, so the seed's tasks — which carry dates relative to
    // whenever the suite runs — cannot wander into the windows below.
    $this->project = Project::factory()->create();
    $this->window = ['project_id' => $this->project->id];
});

/**
 * A dated task on this test's project, in a status that owes work.
 */
function dated(int $projectId, ?string $start, ?string $due, string $title): Task
{
    return Task::factory()->status(TaskStatus::Todo)->create([
        'project_id' => $projectId,
        'title' => $title,
        'start_date' => $start,
        'due_date' => $due,
    ]);
}

/**
 * The titles the base query returns for a set of filters, sorted so the assertion reads as a
 * set rather than as the query's ordering.
 *
 * @return list<string>
 */
function windowTitles(array $filters): array
{
    return app(TaskService::class)
        ->query(User::where('email', 'shahadat@goodtechies.test')->firstOrFail(), $filters)
        ->get()
        ->pluck('title')
        ->sort()
        ->values()
        ->all();
}

/*
|--------------------------------------------------------------------------
| The predicate
|--------------------------------------------------------------------------
*/

it('keeps a span that straddles the edge of the window, which whereBetween would drop', function () {
    // The case the whole predicate exists for: due in September, started in August.
    dated($this->project->id, '2026-08-28', '2026-09-03', 'Straddles into September');
    // And its mirror: started in September, due in October.
    dated($this->project->id, '2026-09-28', '2026-10-04', 'Straddles out of September');
    // Wholly inside, and wholly outside.
    dated($this->project->id, '2026-09-10', '2026-09-12', 'Inside September');
    dated($this->project->id, '2026-07-01', '2026-07-31', 'July only');

    expect(windowTitles($this->window + ['date_from' => '2026-09-01', 'date_to' => '2026-09-30']))->toBe([
        'Inside September',
        'Straddles into September',
        'Straddles out of September',
    ]);

    // The same straddling task is in August's window too. One task on two grids is the point:
    // a test on `due_date` alone would keep it out of August's, where its bar should run to
    // the edge — and out of September's in the mirrored case, where the due date is October's.
    expect(windowTitles($this->window + ['date_from' => '2026-08-01', 'date_to' => '2026-08-31']))
        ->toBe(['Straddles into September']);

    expect(windowTitles($this->window + ['date_from' => '2026-10-01', 'date_to' => '2026-10-31']))
        ->toBe(['Straddles out of September']);
})->group('phase2');

it('spans a task carrying only one date as a single day on that date', function () {
    dated($this->project->id, null, '2026-09-15', 'Due only');
    dated($this->project->id, '2026-09-15', null, 'Start only');

    expect(windowTitles($this->window + ['date_from' => '2026-09-15', 'date_to' => '2026-09-15']))
        ->toBe(['Due only', 'Start only']);

    // One day either side and neither is in the window: a lone date is a point, not a
    // half-open range that runs to the end of time.
    expect(windowTitles($this->window + ['date_from' => '2026-09-16', 'date_to' => '2026-09-30']))->toBe([])
        ->and(windowTitles($this->window + ['date_from' => '2026-09-01', 'date_to' => '2026-09-14']))->toBe([]);
})->group('phase2');

it('leaves a task with no dates out of every window', function () {
    dated($this->project->id, null, null, 'Undated');
    dated($this->project->id, '2026-09-10', '2026-09-12', 'Dated');

    // The coalesce comparison is NULL for a row with no dates, so it is absent rather than
    // accidentally present — but with no window at all it is a task like any other.
    expect(windowTitles($this->window + ['date_from' => '2026-09-01', 'date_to' => '2026-09-30']))->toBe(['Dated'])
        ->and(windowTitles($this->window))->toBe(['Dated', 'Undated']);
})->group('phase2');

it('takes either end of the window on its own', function () {
    dated($this->project->id, '2026-08-01', '2026-08-05', 'August');
    dated($this->project->id, '2026-09-10', '2026-09-12', 'September');
    dated($this->project->id, '2026-10-01', '2026-10-05', 'October');

    expect(windowTitles($this->window + ['date_from' => '2026-09-01']))->toBe(['October', 'September'])
        ->and(windowTitles($this->window + ['date_to' => '2026-09-30']))->toBe(['August', 'September']);
})->group('phase2');

it('drops an unparseable window rather than passing it to the query', function () {
    dated($this->project->id, '2026-09-10', '2026-09-12', 'September');

    $filters = $this->service->filters(['date_from' => 'banana', 'date_to' => '']);

    expect($filters['date_from'])->toBeNull()
        ->and($filters['date_to'])->toBeNull()
        ->and(windowTitles($this->window + ['date_from' => 'banana']))->toBe(['September']);
})->group('phase2');

it('defaults every window key so the filter shape never varies', function () {
    expect($this->service->filters([]))->toHaveKeys(['date_from', 'date_to']);
})->group('phase2');

/*
|--------------------------------------------------------------------------
| The calendar payload
|--------------------------------------------------------------------------
*/

it('answers with the window it was actually given', function () {
    $calendar = $this->service->calendar($this->admin, $this->window + [
        'date_from' => '2026-09-01',
        'date_to' => '2026-09-30',
    ]);

    expect($calendar['window'])->toBe([
        'from' => '2026-09-01',
        'to' => '2026-09-30',
        'days' => 30,
        'month' => '2026-09',
    ]);
})->group('phase2');

it('defaults the window to the as-of month, and says so in the payload', function () {
    $calendar = $this->service->calendar($this->admin, $this->window + [
        'as_of' => Carbon::parse('2026-09-21'),
    ]);

    expect($calendar['window']['from'])->toBe('2026-09-01')
        ->and($calendar['window']['to'])->toBe('2026-09-30')
        ->and($calendar['window']['month'])->toBe('2026-09');
})->group('phase2');

it('fills in the other end of a half-given window', function () {
    $from = $this->service->calendar($this->admin, $this->window + ['date_from' => '2026-09-10']);
    $to = $this->service->calendar($this->admin, $this->window + ['date_to' => '2026-09-10']);

    expect($from['window']['from'])->toBe('2026-09-10')
        ->and($from['window']['to'])->toBe('2026-09-30')
        // Not a whole calendar month, so there is no month for the heading to name.
        ->and($from['window']['month'])->toBeNull()
        ->and($to['window']['from'])->toBe('2026-09-01')
        ->and($to['window']['to'])->toBe('2026-09-10');
})->group('phase2');

it('clips a span to the window and says which edge it runs off', function () {
    dated($this->project->id, '2026-08-28', '2026-09-03', 'Straddles');

    $september = $this->service->calendar($this->admin, $this->window + [
        'date_from' => '2026-09-01',
        'date_to' => '2026-09-30',
    ]);

    expect($september['entries'])->toHaveCount(1)
        ->and($september['entries'][0]['span'])->toBe([
            'start' => '2026-08-28',
            'end' => '2026-09-03',
            'visible_start' => '2026-09-01',
            'visible_end' => '2026-09-03',
            'days' => 3,
            'total_days' => 7,
            'continues_before' => true,
            'continues_after' => false,
            'is_single_day' => false,
        ]);

    // The same task, the same span, clipped the other way — one bar drawn in two months
    // without the screen ever doing date arithmetic of its own.
    $august = $this->service->calendar($this->admin, $this->window + [
        'date_from' => '2026-08-01',
        'date_to' => '2026-08-31',
    ]);

    expect($august['entries'][0]['span']['visible_start'])->toBe('2026-08-28')
        ->and($august['entries'][0]['span']['visible_end'])->toBe('2026-08-31')
        ->and($august['entries'][0]['span']['days'])->toBe(4)
        ->and($august['entries'][0]['span']['continues_before'])->toBeFalse()
        ->and($august['entries'][0]['span']['continues_after'])->toBeTrue();
})->group('phase2');

it('marks a one-day task as a single day rather than a bar', function () {
    dated($this->project->id, null, '2026-09-15', 'Due only');

    $calendar = $this->service->calendar($this->admin, $this->window + [
        'date_from' => '2026-09-01',
        'date_to' => '2026-09-30',
    ]);

    expect($calendar['entries'][0]['span']['is_single_day'])->toBeTrue()
        ->and($calendar['entries'][0]['span']['days'])->toBe(1)
        ->and($calendar['entries'][0]['span']['start'])->toBe('2026-09-15')
        ->and($calendar['entries'][0]['span']['end'])->toBe('2026-09-15');
})->group('phase2');

it('orders entries by the day they become visible, longest bar first', function () {
    dated($this->project->id, '2026-09-05', '2026-09-06', 'Short, later');
    dated($this->project->id, '2026-09-01', '2026-09-02', 'Short, first');
    dated($this->project->id, '2026-08-20', '2026-09-20', 'Long, clipped to the first');

    $calendar = $this->service->calendar($this->admin, $this->window + [
        'date_from' => '2026-09-01',
        'date_to' => '2026-09-30',
    ]);

    expect(array_map(fn (array $entry): string => $entry['task']->title, $calendar['entries']))
        ->toBe(['Long, clipped to the first', 'Short, first', 'Short, later']);
})->group('phase2');

it('counts the tasks no window can show instead of losing them quietly', function () {
    dated($this->project->id, '2026-09-10', '2026-09-12', 'Dated');
    dated($this->project->id, null, null, 'Undated one');
    dated($this->project->id, null, null, 'Undated two');

    $calendar = $this->service->calendar($this->admin, $this->window + [
        'date_from' => '2026-09-01',
        'date_to' => '2026-09-30',
    ]);

    expect($calendar['total'])->toBe(1)
        ->and($calendar['unscheduled_count'])->toBe(2);
})->group('phase2');

it('scopes the calendar through visibleTo like every other query', function () {
    dated($this->project->id, '2026-09-10', '2026-09-12', 'Nobody is assigned to this');

    $september = $this->window + ['date_from' => '2026-09-01', 'date_to' => '2026-09-30'];

    expect($this->service->calendar($this->admin, $september)['total'])->toBe(1)
        // Tapu is assigned to nothing on this project, so his September is empty — the task is
        // absent, not refused.
        ->and($this->service->calendar($this->tapu, $september)['total'])->toBe(0)
        ->and($this->service->calendar($this->accountant, $september)['total'])->toBe(0);
})->group('phase2');

it('keeps the other filters working inside a window', function () {
    dated($this->project->id, '2026-09-10', '2026-09-12', 'Keep me');
    dated($this->project->id, '2026-09-10', '2026-09-12', 'Drop me');

    $calendar = $this->service->calendar($this->admin, $this->window + [
        'date_from' => '2026-09-01',
        'date_to' => '2026-09-30',
        'search' => 'keep',
    ]);

    expect($calendar['total'])->toBe(1)
        ->and($calendar['entries'][0]['task']->title)->toBe('Keep me');
})->group('phase2');

/*
|--------------------------------------------------------------------------
| The board shape, and the role half of the drag rule
|--------------------------------------------------------------------------
*/

it('gives the board one column per status, in board order, empty ones included', function () {
    dated($this->project->id, '2026-09-10', '2026-09-12', 'Only task');

    $board = $this->service->board($this->admin, $this->window);

    expect(array_column($board['columns'], 'key'))
        ->toBe(array_map(fn (TaskStatus $s): string => $s->value, TaskStatus::boardOrder()))
        ->and($board['columns'])->toHaveCount(count(TaskStatus::cases()))
        ->and($board['total'])->toBe(1)
        // A column that vanishes when it is empty is a column nothing can be dragged into.
        ->and(array_sum(array_column($board['columns'], 'count')))->toBe(1);
})->group('phase2');

it('offers an employee only the columns their role may drag between', function () {
    $map = $this->service->transitionsFor($this->tapu);

    expect($map['todo'])->toContain('in_progress')->toContain('waiting')
        ->and($map['in_progress'])->toContain('todo')->toContain('waiting')->toContain('in_review')
        ->and($map['waiting'])->toContain('todo')->toContain('in_progress')
        // The three refusals the plan names, seen from the board's side.
        ->and($map['in_review'])->not->toContain('completed')
        ->and($map['todo'])->not->toContain('cancelled')
        ->and($map['completed'])->toBe([]);
})->group('phase2');

it('offers an admin and a manager the moves an employee is refused', function () {
    $admin = $this->service->transitionsFor($this->admin);
    $manager = $this->service->transitionsFor($this->manager);

    expect($admin['in_review'])->toContain('completed')->toContain('changes_requested')
        ->and($admin['todo'])->toContain('cancelled')
        // Reopening a completed task is the one move a manager does not get either.
        ->and($admin['completed'])->toContain('in_progress')
        ->and($manager['in_review'])->toContain('completed')
        ->and($manager['todo'])->toContain('cancelled')
        ->and($manager['completed'])->toBe([]);
})->group('phase2');

it('offers the accountant, and a caller with no user, no column at all', function () {
    foreach ([$this->accountant, null] as $user) {
        $map = $this->service->transitionsFor($user);

        expect(array_keys($map))
            ->toBe(array_map(fn (TaskStatus $s): string => $s->value, TaskStatus::boardOrder()));

        foreach ($map as $targets) {
            expect($targets)->toBe([]);
        }
    }
})->group('phase2');

it('answers the plan question once, for the form and the calendar alike', function () {
    expect(TaskService::mayPlan($this->admin))->toBeTrue()
        ->and(TaskService::mayPlan($this->manager))->toBeTrue()
        ->and(TaskService::mayPlan($this->tapu))->toBeFalse()
        ->and(TaskService::mayPlan($this->accountant))->toBeFalse()
        ->and(TaskService::mayPlan(null))->toBeFalse();
})->group('phase2');
