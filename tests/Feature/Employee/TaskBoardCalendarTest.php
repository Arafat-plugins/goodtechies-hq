<?php

use App\Models\Employee;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\RoleName;
use App\Support\TaskStatus;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| Employee Board and Calendar
|--------------------------------------------------------------------------
|
| The same two routes as the Admin surface, over the same query, scoped the way
| everything on this surface is scoped: an employee's board holds the tasks
| they are ASSIGNED to and nothing else — not even a task on a project they are
| a member of. That scoping is Task::visibleTo()'s, not the route's.
|
| The second half of this file confirms the drag rules the plan states and the
| existing endpoints are supposed to already enforce, from the board's point of
| view. Most of them already had a test; which ones, and where, is noted on
| each. The ones that did not are here because a rule everybody believes is
| enforced and nobody has asserted is a rule waiting to stop being true.
|
*/

beforeEach(function () {
    $this->seed();

    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->manager = Employee::factory()->forRole(RoleName::MANAGER)->create()->user;
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();

    $this->tapuEmployee = Employee::where('employee_number', 'GT-003')->firstOrFail();

    $this->project = Project::where('name', 'Buffalo Modular — SEO')->firstOrFail();
    // In progress, assigned to Tapu, on a project Yaseen is not a member of.
    $this->task = Task::where('title', 'Fix the duplicate canonical tags on model pages')->firstOrFail();
});

/*
|--------------------------------------------------------------------------
| The two routes
|--------------------------------------------------------------------------
*/

it('renders the employee board with every column, including the empty ones', function () {
    $this->actingAs($this->tapu)
        ->get(route('employee.tasks.board'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Employee/Tasks/Board', false)
            ->has('board.columns', count(TaskStatus::cases()))
            ->has('board.total')
            ->has('board.overdue_count')
            ->has('transitions')
            ->has('filters')
            ->has('statuses')
            ->has('priorities')
            ->has('projects')
            ->has('tags')
            // No assignee picker on this surface: every task here is already theirs.
            ->missing('employees'),
        );
})->group('phase2');

it('puts only this employee\'s own cards on their board', function () {
    $mine = Task::factory()
        ->status(TaskStatus::Todo)
        ->assignedTo($this->tapuEmployee)
        ->create(['project_id' => $this->project->id, 'title' => 'Mine']);

    Task::factory()
        ->status(TaskStatus::Todo)
        ->create(['project_id' => $this->project->id, 'title' => 'Somebody else\'s']);

    $page = $this->actingAs($this->tapu)->get(route('employee.tasks.board'))->assertOk();

    $titles = collect($page->viewData('page')['props']['board']['columns'])
        ->flatMap(fn (array $column): array => array_column($column['tasks'], 'title'))
        ->all();

    expect($titles)->toContain('Mine')
        // Absent, not refused: the query starts at visibleTo() rather than filtering after.
        ->and($titles)->not->toContain('Somebody else\'s')
        ->and($mine->id)->toBeInt();
})->group('phase2');

it('gives an employee assigned nothing an empty board rather than a refusal', function () {
    // Somebody brand new, on nothing. Eight empty columns is a 200 — an empty view is not a
    // refusal, and the columns are still all there to be dragged into once they have work.
    $newcomer = Employee::factory()->forRole(RoleName::EMPLOYEE)->create()->user;

    $this->actingAs($newcomer)
        ->get(route('employee.tasks.board'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Employee/Tasks/Board', false)
            ->where('board.total', 0)
            ->has('board.columns', count(TaskStatus::cases()))
            ->etc(),
        );
})->group('phase2');

it('renders the employee calendar over the same scoped query, with its window', function () {
    Task::factory()
        ->status(TaskStatus::Todo)
        ->assignedTo($this->tapuEmployee)
        ->create([
            'project_id' => $this->project->id,
            'title' => 'Mine, in September',
            'start_date' => '2026-08-28',
            'due_date' => '2026-09-03',
        ]);

    Task::factory()->status(TaskStatus::Todo)->create([
        'project_id' => $this->project->id,
        'title' => 'Somebody else\'s, in September',
        'start_date' => '2026-09-05',
        'due_date' => '2026-09-07',
    ]);

    $this->actingAs($this->tapu)
        // Scoped by search as well as by window: the seeded tasks carry dates relative to
        // whenever the suite runs and would otherwise drift into this month.
        ->get(route('employee.tasks.calendar', [
            'date_from' => '2026-09-01',
            'date_to' => '2026-09-30',
            'search' => 'in September',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Employee/Tasks/Calendar', false)
            ->where('calendar.window.from', '2026-09-01')
            ->where('calendar.window.to', '2026-09-30')
            ->where('calendar.window.month', '2026-09')
            ->where('calendar.total', 1)
            ->where('calendar.tasks.0.title', 'Mine, in September')
            // The span the straddling bar is drawn from, clipped to this window.
            ->where('calendar.tasks.0.span.visible_start', '2026-09-01')
            ->where('calendar.tasks.0.span.continues_before', true)
            ->etc(),
        );
})->group('phase2');

it('refuses a backwards window here too', function () {
    $this->actingAs($this->tapu)
        ->get(route('employee.tasks.calendar', ['date_from' => '2026-09-30', 'date_to' => '2026-09-01']))
        ->assertRedirect()
        ->assertSessionHasErrors('date_to');
})->group('phase2');

it('keeps an admin off the employee board and calendar', function () {
    $admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();

    $this->actingAs($admin)->get(route('employee.tasks.board'))->assertForbidden();
    $this->actingAs($admin)->get(route('employee.tasks.calendar'))->assertForbidden();
})->group('phase2');

it('still renders the employee list, which runs through the same widened query', function () {
    $this->actingAs($this->tapu)
        ->get(route('employee.tasks.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Employee/Tasks/Index')->has('tasks.groups'));
})->group('phase2');

/*
|--------------------------------------------------------------------------
| The drag rules, confirmed from the board's side
|--------------------------------------------------------------------------
|
| "An employee may drag only between the statuses they may set (TO DO ↔ IN
| PROGRESS ↔ WAITING, and to IN REVIEW — which opens the work-summary modal
| before the move commits); dragging to COMPLETED is Admin/Manager only. Date
| drags are Admin/Manager only; employees see the handles disabled."
|
*/

it('lets an employee drag between the three columns the plan gives them', function () {
    // The one leg of the rule nothing asserted end to end: the ALLOWED drag between columns,
    // through the status endpoint with a landing card. Service-level coverage existed
    // (TaskWriteServiceTest, "lets exactly the transitions through that the policy allows");
    // the endpoint only ever had the refused half.
    $landing = Task::factory()->status(TaskStatus::Waiting)->create(['project_id' => $this->project->id]);

    foreach ([TaskStatus::Waiting, TaskStatus::Todo, TaskStatus::InProgress] as $to) {
        $this->actingAs($this->tapu)
            ->post(route('employee.tasks.status', $this->task), [
                'status' => $to->value,
                'after_id' => $landing->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        expect($this->task->fresh()->status)->toBe($to);
    }
})->group('phase2');

it('opens the work-summary requirement before a drag into In review commits', function () {
    // Already covered from the form's side by Employee/TaskWriteEndpointsTest ("takes an
    // employee as far as in review and no further"). Repeated here with a drag's payload,
    // because the modal the plan describes is the client half of THIS refusal: with no
    // summary the move does not commit.
    $landing = Task::factory()->status(TaskStatus::InReview)->create(['project_id' => $this->project->id]);

    $this->actingAs($this->tapu)
        ->post(route('employee.tasks.status', $this->task), [
            'status' => TaskStatus::InReview->value,
            'after_id' => $landing->id,
        ])
        ->assertRedirect()
        ->assertSessionHas('error');

    expect($this->task->fresh()->status)->toBe(TaskStatus::InProgress);

    $this->actingAs($this->tapu)
        ->post(route('employee.tasks.status', $this->task), [
            'status' => TaskStatus::InReview->value,
            'after_id' => $landing->id,
            'work_summary' => 'Eight canonicals repointed.',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($this->task->fresh()->status)->toBe(TaskStatus::InReview);
})->group('phase2');

it('refuses an employee a drag into the Completed column', function () {
    // Covered already by Employee/TaskWriteEndpointsTest ("gives a drag exactly the same
    // answer as the form") and by TaskWriteServiceTest ("never lets an employee reach
    // completed, however they ask"). Kept here so the board's own rule list is readable in
    // one place, and asserted against the transitions map the board is actually given.
    $this->actingAs($this->tapu)->post(route('employee.tasks.status', $this->task), [
        'status' => TaskStatus::InReview->value,
        'work_summary' => 'Ready.',
    ])->assertRedirect();

    $landing = Task::factory()->status(TaskStatus::Completed)->create(['project_id' => $this->project->id]);

    $this->actingAs($this->tapu)
        ->post(route('employee.tasks.status', $this->task), [
            'status' => TaskStatus::Completed->value,
            'after_id' => $landing->id,
        ])
        ->assertForbidden();

    expect($this->task->fresh()->status)->toBe(TaskStatus::InReview);

    // And the board never offered the column in the first place.
    $page = $this->actingAs($this->tapu)->get(route('employee.tasks.board'))->assertOk();
    $transitions = $page->viewData('page')['props']['transitions'];

    expect($transitions['in_review'])->not->toContain('completed')
        ->and($transitions['todo'])->toContain('in_progress')
        ->and($transitions['in_progress'])->toContain('in_review')
        ->and($transitions['waiting'])->toContain('in_progress');
})->group('phase2');

it('refuses an employee a date drag, and tells the calendar not to offer the handles', function () {
    // The write half was already covered by Employee/TaskWriteEndpointsTest ("lets an
    // assignee edit the work and refuses them the plan"). What was missing is the other half
    // of the same rule: that the calendar is TOLD, from the same definition, so a handle is
    // never enabled for somebody the write would refuse.
    $this->actingAs($this->tapu)
        ->put(route('employee.tasks.update', $this->task), ['due_date' => '2026-12-01'])
        ->assertSessionHasErrors('due_date');

    $this->actingAs($this->tapu)
        ->put(route('employee.tasks.update', $this->task), ['start_date' => '2026-12-01'])
        ->assertSessionHasErrors('start_date');

    $this->actingAs($this->tapu)
        ->get(route('employee.tasks.calendar'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('can_plan', false)->etc());
})->group('phase2');

it('lets the manager on this surface drag a date, and says so on their calendar', function () {
    $this->actingAs($this->manager)
        ->put(route('employee.tasks.update', $this->task), ['due_date' => '2026-12-01'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($this->task->fresh()->due_date?->toDateString())->toBe('2026-12-01');

    $this->actingAs($this->manager)
        ->get(route('employee.tasks.calendar'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('can_plan', true)->etc());
})->group('phase2');
