<?php

use App\Models\Employee;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\GanttZoom;
use App\Support\RoleName;
use App\Support\TaskStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| Tasks → Gantt (Phase 10)
|--------------------------------------------------------------------------
|
| The fourth view of one query. Almost nothing here is new machinery and that
| is the point of most of these tests: the scoping is Task::visibleTo()'s, the
| filters are TaskService::filters()', the window is the same `date_from` /
| `date_to` pair the Calendar uses, and a date write is `PUT …/tasks/{task}`
| with UpdateTaskRequest's validation. What IS new is the geometry, the three
| shapes, and the dependency rule — and the dependency rule is the one with a
| privacy edge on it, so it gets the longest test in the file.
|
| Every constant and helper below is prefixed `GANTT_`: Pest declares them
| globally, and two files that both define `WINDOW_START` silently give one of
| them the other's date.
|
*/

const GANTT_FROM = '2026-09-14';
const GANTT_TO = '2026-10-11';
const GANTT_OUTSIDE = '2027-04-01';

/** The window every test below asks for, unless it is testing the default. */
function ganttWindow(array $extra = []): array
{
    return ['date_from' => GANTT_FROM, 'date_to' => GANTT_TO, ...$extra];
}

/** Every task on a Gantt payload, flattened out of its project groups. */
function ganttTasks(array $props): array
{
    return collect($props['gantt']['rows'])->flatMap(fn (array $row): array => $row['tasks'])->all();
}

function ganttTitles(array $props): array
{
    return collect(ganttTasks($props))->pluck('title')->sort()->values()->all();
}

function ganttFor(array $props, string $title): ?array
{
    return collect(ganttTasks($props))->firstWhere('title', $title)['gantt'] ?? null;
}

function ganttProps(TestResponse $response): array
{
    return $response->viewData('page')['props'];
}

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->manager = Employee::factory()->forRole(RoleName::MANAGER)->create()->user;
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();

    $this->tapuEmployee = Employee::where('employee_number', 'GT-003')->firstOrFail();

    $this->project = Project::factory()->create(['name' => 'Gantt — Test Project']);
});

/*
|--------------------------------------------------------------------------
| Who may reach it
|--------------------------------------------------------------------------
*/

it('renders the gantt on both surfaces for the roles that may', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.tasks.gantt', ganttWindow()))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Tasks/Gantt', false)
            ->has('gantt.window')
            ->has('gantt.units')
            ->has('gantt.rows')
            ->has('gantt.edges')
            ->has('gantt.total')
            ->has('gantt.unscheduled_count')
            // The chip bar's option lists travel with it, so the filters survive a switch
            // from any of the other three views.
            ->has('filters')
            ->has('statuses')
            ->has('priorities')
            ->has('projects')
            ->has('tags')
            ->has('employees')
            ->has('zooms')
            ->where('can_plan', true),
        );

    $this->actingAs($this->tapu)
        ->get(route('employee.tasks.gantt', ganttWindow()))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Employee/Tasks/Gantt', false)
            ->has('gantt.rows')
            // `TaskService::mayPlan()` — the same definition that makes the date fields
            // `prohibited` in UpdateTaskRequest, so an inert handle and a refused write
            // cannot come apart.
            ->where('can_plan', false),
        );
})->group('phase10');

it('lets the manager who shares the employee surface plan on it', function () {
    $this->actingAs($this->manager)
        ->get(route('employee.tasks.gantt', ganttWindow()))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('can_plan', true)->etc());
})->group('phase10');

it('refuses the gantt to every role the other task views refuse it to', function () {
    // The same shape as the Board's and the Calendar's row in this matrix: a manager and an
    // employee are on the employee surface, so the admin route is 403 for them; the
    // accountant holds no tasks.* permission and is refused on both.
    foreach ([$this->manager, $this->tapu, $this->accountant] as $user) {
        $this->actingAs($user)->get(route('admin.tasks.gantt'))->assertForbidden();
    }

    foreach (['admin.tasks.gantt', 'employee.tasks.gantt'] as $route) {
        $this->actingAs($this->accountant)->get(route($route))->assertForbidden();
    }

    $this->actingAs($this->admin)->get(route('employee.tasks.gantt'))->assertForbidden();
})->group('phase10');

it('sends a guest to the login page rather than a payload', function () {
    $this->get(route('admin.tasks.gantt'))->assertRedirect(route('login'));
})->group('phase10');

/*
|--------------------------------------------------------------------------
| The window
|--------------------------------------------------------------------------
*/

it('is a window: a task outside it is absent, and the query asks the database for one', function () {
    Task::factory()->status(TaskStatus::Todo)->create([
        'project_id' => $this->project->id,
        'title' => 'Inside the window',
        'start_date' => '2026-09-20',
        'due_date' => '2026-09-24',
    ]);
    Task::factory()->status(TaskStatus::Todo)->create([
        'project_id' => $this->project->id,
        'title' => 'Outside the window',
        'start_date' => GANTT_OUTSIDE,
        'due_date' => '2027-04-10',
    ]);

    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
    });

    $props = ganttProps($this->actingAs($this->admin)->get(route('admin.tasks.gantt', ganttWindow()))->assertOk());

    expect(ganttTitles($props))->toContain('Inside the window')
        ->and(ganttTitles($props))->not->toContain('Outside the window');

    // Not merely "the screen shows fewer rows": the narrowing has to be in the SQL, or a
    // timeline over three years of work is three years of rows fetched and thrown away.
    $windowed = collect($queries)->first(fn (array $q): bool => str_contains($q['sql'], 'from "tasks"')
        && str_contains($q['sql'], 'coalesce(tasks.due_date, tasks.start_date) >=')
        && str_contains($q['sql'], 'coalesce(tasks.start_date, tasks.due_date) <='));

    expect($windowed)->not->toBeNull()
        ->and($windowed['bindings'])->toContain(GANTT_FROM)
        ->and($windowed['bindings'])->toContain(GANTT_TO);
})->group('phase10');

it('reads the zoom and the window out of the URL', function () {
    foreach (GanttZoom::cases() as $zoom) {
        $props = ganttProps(
            $this->actingAs($this->admin)
                ->get(route('admin.tasks.gantt', ganttWindow(['zoom' => $zoom->value])))
                ->assertOk(),
        );

        expect($props['gantt']['window']['zoom'])->toBe($zoom->value)
            ->and($props['gantt']['window']['from'])->toBe(GANTT_FROM)
            ->and($props['gantt']['window']['to'])->toBe(GANTT_TO)
            // The columns are the server's, so the header cannot disagree with the bars.
            ->and($props['gantt']['units'])->not->toBeEmpty()
            ->and($props['gantt']['units'][0]['start'])->toBe(GANTT_FROM)
            ->and(end($props['gantt']['units'])['end'])->toBe(GANTT_TO);
    }

    // A zoom that never existed is a typo worth naming, unlike a stale `?status=` chip.
    $this->actingAs($this->admin)
        ->get(route('admin.tasks.gantt', ['zoom' => 'quarter']))
        ->assertSessionHasErrors('zoom');

    // A backwards window, refused here exactly as the Calendar refuses one.
    $this->actingAs($this->admin)
        ->get(route('admin.tasks.gantt', ['date_from' => GANTT_TO, 'date_to' => GANTT_FROM]))
        ->assertSessionHasErrors('date_to');
})->group('phase10');

it('opens each zoom on its own default window when the URL names none', function () {
    foreach (GanttZoom::cases() as $zoom) {
        $props = ganttProps(
            $this->actingAs($this->admin)->get(route('admin.tasks.gantt', ['zoom' => $zoom->value]))->assertOk(),
        );

        $window = $props['gantt']['window'];

        expect($window['zoom'])->toBe($zoom->value)
            ->and($window['clamped'])->toBeFalse()
            ->and($window['days'])->toBeLessThanOrEqual($zoom->maxDays())
            // A plan is read to see what is late as much as what is next, so every default
            // window opens before today rather than on it.
            ->and($window['from'])->toBeLessThan($window['today'])
            ->and($window['to'])->toBeGreaterThan($window['today']);
    }
})->group('phase10');

it('clamps a window wider than the zoom draws, and says so rather than silently cutting it', function () {
    $props = ganttProps(
        $this->actingAs($this->admin)
            ->get(route('admin.tasks.gantt', ['zoom' => 'day', 'date_from' => '2026-01-01', 'date_to' => '2030-01-01']))
            ->assertOk(),
    );

    // The number of columns IS the number of days, so an unclamped hand-edited window is not
    // a slow page, it is a hundred thousand of them.
    expect($props['gantt']['window']['clamped'])->toBeTrue()
        ->and($props['gantt']['window']['days'])->toBe(GanttZoom::Day->maxDays())
        ->and($props['gantt']['window']['from'])->toBe('2026-01-01')
        ->and(count($props['gantt']['units']))->toBe(GanttZoom::Day->maxDays());
})->group('phase10');

/*
|--------------------------------------------------------------------------
| The three shapes
|--------------------------------------------------------------------------
*/

it('draws a task with no start date as a milestone on its due date', function () {
    Task::factory()->status(TaskStatus::Todo)->create([
        'project_id' => $this->project->id,
        'title' => 'A milestone',
        'start_date' => null,
        'due_date' => '2026-09-24',
    ]);

    $geometry = ganttFor(
        ganttProps($this->actingAs($this->admin)->get(route('admin.tasks.gantt', ganttWindow()))->assertOk()),
        'A milestone',
    );

    expect($geometry['shape'])->toBe('milestone')
        ->and($geometry['start_date'])->toBeNull()
        ->and($geometry['due_date'])->toBe('2026-09-24')
        // It sits on ONE day. The Calendar draws the same task as a one-day BAR, and the
        // difference is the whole point: "a milestone on the due date" and "a day of work"
        // are different claims, and the plan names the first one.
        ->and($geometry['start'])->toBe('2026-09-24')
        ->and($geometry['end'])->toBe('2026-09-24')
        ->and($geometry['days'])->toBe(1)
        ->and($geometry['is_single_day'])->toBeTrue();
})->group('phase10');

it('draws a task with no due date as an open-ended mark on its start date, not as a day of work', function () {
    // The case the plan does not name. It is NOT drawn as a one-day bar (that says the work
    // takes a day) and NOT run to the edge of the window (that says it is due at the edge).
    // It is a start mark with a fading tail, and it says "no due date" in its own words.
    Task::factory()->status(TaskStatus::InProgress)->create([
        'project_id' => $this->project->id,
        'title' => 'Open ended work',
        'start_date' => '2026-09-18',
        'due_date' => null,
    ]);

    $geometry = ganttFor(
        ganttProps($this->actingAs($this->admin)->get(route('admin.tasks.gantt', ganttWindow()))->assertOk()),
        'Open ended work',
    );

    expect($geometry['shape'])->toBe('open_ended')
        ->and($geometry['start_date'])->toBe('2026-09-18')
        ->and($geometry['due_date'])->toBeNull()
        ->and($geometry['start'])->toBe('2026-09-18')
        ->and($geometry['end'])->toBe('2026-09-18')
        ->and($geometry['continues_after'])->toBeFalse();
})->group('phase10');

it('counts a task with no dates at all instead of losing it', function () {
    Task::factory()->status(TaskStatus::Backlog)->create([
        'project_id' => $this->project->id,
        'title' => 'Undated',
        'start_date' => null,
        'due_date' => null,
    ]);

    $props = ganttProps($this->actingAs($this->admin)->get(route('admin.tasks.gantt', ganttWindow()))->assertOk());

    expect(ganttTitles($props))->not->toContain('Undated')
        ->and($props['gantt']['unscheduled_count'])->toBeGreaterThanOrEqual(1);
})->group('phase10');

it('clips a bar to the window and says which edge it runs off', function () {
    Task::factory()->status(TaskStatus::InProgress)->create([
        'project_id' => $this->project->id,
        'title' => 'Runs through',
        'start_date' => '2026-09-01',
        'due_date' => '2026-11-30',
    ]);

    $geometry = ganttFor(
        ganttProps($this->actingAs($this->admin)->get(route('admin.tasks.gantt', ganttWindow()))->assertOk()),
        'Runs through',
    );

    expect($geometry['visible_start'])->toBe(GANTT_FROM)
        ->and($geometry['visible_end'])->toBe(GANTT_TO)
        ->and($geometry['offset_days'])->toBe(0)
        ->and($geometry['continues_before'])->toBeTrue()
        ->and($geometry['continues_after'])->toBeTrue()
        // The dates as STORED still ride along, because a drag computes its move from them.
        ->and($geometry['start_date'])->toBe('2026-09-01')
        ->and($geometry['due_date'])->toBe('2026-11-30');
})->group('phase10');

/*
|--------------------------------------------------------------------------
| Rows, grouped by project
|--------------------------------------------------------------------------
*/

it('groups the rows by project and keeps the order the same between two requests', function () {
    Task::factory()->status(TaskStatus::Todo)->create([
        'project_id' => $this->project->id,
        'title' => 'Second',
        'start_date' => '2026-09-22',
        'due_date' => '2026-09-25',
    ]);
    Task::factory()->status(TaskStatus::Todo)->create([
        'project_id' => $this->project->id,
        'title' => 'First',
        'start_date' => '2026-09-16',
        'due_date' => '2026-09-18',
    ]);

    $read = fn (): array => ganttProps(
        $this->actingAs($this->admin)->get(route('admin.tasks.gantt', ganttWindow()))->assertOk(),
    );

    $a = $read();
    $b = $read();

    $row = collect($a['gantt']['rows'])->firstWhere('project_id', $this->project->id);

    expect($row)->not->toBeNull()
        ->and($row['project']['name'])->toBe('Gantt — Test Project')
        ->and(collect($row['tasks'])->pluck('title')->all())->toBe(['First', 'Second']);

    // A timeline whose rows shuffle between renders is a timeline nobody can point at.
    expect(collect($b['gantt']['rows'])->pluck('project_id')->all())
        ->toBe(collect($a['gantt']['rows'])->pluck('project_id')->all());
})->group('phase10');

/*
|--------------------------------------------------------------------------
| Employee scoping, and the dependency rule that has a privacy edge on it
|--------------------------------------------------------------------------
*/

it('carries only the tasks an employee may see', function () {
    $mine = Task::factory()->status(TaskStatus::InProgress)->assignedTo($this->tapuEmployee)->create([
        'project_id' => $this->project->id,
        'title' => 'Tapu owns this',
        'start_date' => '2026-09-16',
        'due_date' => '2026-09-20',
    ]);

    Task::factory()->status(TaskStatus::InProgress)->create([
        'project_id' => $this->project->id,
        'title' => 'Somebody else owns this',
        'start_date' => '2026-09-16',
        'due_date' => '2026-09-20',
    ]);

    $props = ganttProps(
        $this->actingAs($this->tapu)->get(route('employee.tasks.gantt', ganttWindow()))->assertOk(),
    );

    expect(ganttTitles($props))->toContain('Tapu owns this')
        ->and(ganttTitles($props))->not->toContain('Somebody else owns this');

    // Not merely absent from the picture: absent from the payload, which is the only place
    // absence means anything.
    expect(json_encode($props['gantt']))->not->toContain('Somebody else owns this');

    // And the one they do see is theirs to open but not to re-plan.
    $row = collect(ganttTasks($props))->firstWhere('id', $mine->id);

    expect($row['permissions']['can_update'])->toBeTrue()
        ->and($props['can_plan'])->toBeFalse();
})->group('phase10');

it('draws an arrow only when both ends are on the timeline, and leaks no id when the other end is out of access', function () {
    // Three tasks. Tapu is assigned to the middle one only.
    //
    //   hidden  →  visible  →  offscreen
    //
    // For Tapu: `hidden` is out of ACCESS and `offscreen` is out of the WINDOW. They are
    // different absences and they are answered differently — one is counted, one produces
    // nothing at all — and getting that backwards is a leak in the shape of a line.
    $hidden = Task::factory()->status(TaskStatus::Todo)->create([
        'project_id' => $this->project->id,
        'title' => 'Tapu may not see this one',
        'start_date' => '2026-09-15',
        'due_date' => '2026-09-17',
    ]);

    $visible = Task::factory()->status(TaskStatus::Todo)->assignedTo($this->tapuEmployee)->create([
        'project_id' => $this->project->id,
        'title' => 'Tapu may see this one',
        'start_date' => '2026-09-18',
        'due_date' => '2026-09-22',
    ]);

    $offscreen = Task::factory()->status(TaskStatus::Todo)->assignedTo($this->tapuEmployee)->create([
        'project_id' => $this->project->id,
        'title' => 'Tapu may see this one too, but not this month',
        'start_date' => GANTT_OUTSIDE,
        'due_date' => '2027-04-10',
    ]);

    // `visible` waits for `hidden`; `offscreen` waits for `visible`.
    DB::table('task_dependencies')->insert([
        ['task_id' => $visible->id, 'depends_on_task_id' => $hidden->id, 'created_by' => $this->admin->id, 'created_at' => now()],
        ['task_id' => $offscreen->id, 'depends_on_task_id' => $visible->id, 'created_by' => $this->admin->id, 'created_at' => now()],
    ]);

    $props = ganttProps(
        $this->actingAs($this->tapu)->get(route('employee.tasks.gantt', ganttWindow()))->assertOk(),
    );

    $geometry = ganttFor($props, 'Tapu may see this one');

    expect($geometry)->not->toBeNull()
        // Nothing is drawn in either direction: one partner is out of access, the other out
        // of the window.
        ->and($props['gantt']['edges'])->toBe([])
        // Out of ACCESS: not drawn, not counted, not named. A count would be a smaller leak
        // than a line, and it would still be a leak.
        ->and($geometry['depends_on_visible'])->toBe(0)
        ->and($geometry['depends_on_offscreen'])->toBe(0)
        // Out of WINDOW: counted, because a planner should know there is more — but never
        // named, because a count is enough.
        ->and($geometry['blocks_visible'])->toBe(0)
        ->and($geometry['blocks_offscreen'])->toBe(1);

    // The hidden task's id must not appear anywhere in the payload — not as an edge, not as
    // a row, not as a stray key. Assert the payload, not the picture.
    $encoded = json_encode($props['gantt']);

    expect($encoded)->not->toContain('Tapu may not see this one')
        ->and($encoded)->not->toContain(sprintf(':%d,', $hidden->id))
        ->and(collect($props['gantt']['edges'])->flatMap(fn (array $e): array => array_values($e))->all())
        ->not->toContain($hidden->id);
})->group('phase10');

it('draws the arrow when an admin can see both ends of the same dependency', function () {
    $prerequisite = Task::factory()->status(TaskStatus::Todo)->create([
        'project_id' => $this->project->id,
        'title' => 'Comes first',
        'start_date' => '2026-09-15',
        'due_date' => '2026-09-17',
    ]);

    $dependent = Task::factory()->status(TaskStatus::Todo)->create([
        'project_id' => $this->project->id,
        'title' => 'Comes second',
        'start_date' => '2026-09-18',
        'due_date' => '2026-09-22',
    ]);

    DB::table('task_dependencies')->insert([
        'task_id' => $dependent->id,
        'depends_on_task_id' => $prerequisite->id,
        'created_by' => $this->admin->id,
        'created_at' => now(),
    ]);

    $props = ganttProps($this->actingAs($this->admin)->get(route('admin.tasks.gantt', ganttWindow()))->assertOk());

    // The arrow runs from the thing that has to finish to the thing that is waiting.
    expect($props['gantt']['edges'])->toContain(['from' => $prerequisite->id, 'to' => $dependent->id])
        ->and(ganttFor($props, 'Comes second')['depends_on_visible'])->toBe(1)
        ->and(ganttFor($props, 'Comes first')['blocks_visible'])->toBe(1);
})->group('phase10');

/*
|--------------------------------------------------------------------------
| Drag and resize — through the endpoint the form uses, and nothing else
|--------------------------------------------------------------------------
*/

it('moves both dates through the same update endpoint the form posts to', function () {
    $task = Task::factory()->status(TaskStatus::InProgress)->create([
        'project_id' => $this->project->id,
        'title' => 'Dragged',
        'start_date' => '2026-09-16',
        'due_date' => '2026-09-20',
    ]);

    $this->actingAs($this->admin)
        ->put(route('admin.tasks.update', $task), ['start_date' => '2026-09-19', 'due_date' => '2026-09-23'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $task->refresh();

    expect($task->start_date?->toDateString())->toBe('2026-09-19')
        ->and($task->due_date?->toDateString())->toBe('2026-09-23');

    // Through TaskService::update(), so the move is on the task's timeline like any edit.
    $this->assertDatabaseHas('activity_logs', [
        'object_type' => Task::class,
        'object_id' => $task->id,
        'description' => 'Task updated: start_date, due_date',
    ]);
})->group('phase10');

it('refuses an invalid move with the form\'s own sentence and changes nothing in the database', function () {
    $task = Task::factory()->status(TaskStatus::InProgress)->create([
        'project_id' => $this->project->id,
        'title' => 'Not going backwards',
        'start_date' => '2026-09-16',
        'due_date' => '2026-09-20',
    ]);

    $response = $this->actingAs($this->admin)
        ->from(route('admin.tasks.gantt'))
        ->put(route('admin.tasks.update', $task), ['start_date' => '2026-09-25', 'due_date' => '2026-09-20']);

    $response->assertSessionHasErrors('due_date');

    // The same sentence the detail form gets, because it is the same rule in the same Form
    // Request — there is no Gantt endpoint and no second date rule to drift from it.
    expect(session('errors')->first('due_date'))
        ->toBe('The due date field must be a date after or equal to start date.');

    $task->refresh();

    expect($task->start_date?->toDateString())->toBe('2026-09-16')
        ->and($task->due_date?->toDateString())->toBe('2026-09-20');
})->group('phase10');

it('lets a milestone be moved without quietly growing a start date', function () {
    // The drag sends both keys every time, `null` included. If it sent the milestone's own
    // anchor day as a start date instead, the first drag would turn every milestone into a
    // one-day bar and the diamond would never come back.
    $task = Task::factory()->status(TaskStatus::Todo)->create([
        'project_id' => $this->project->id,
        'title' => 'A movable milestone',
        'start_date' => null,
        'due_date' => '2026-09-20',
    ]);

    $this->actingAs($this->admin)
        ->put(route('admin.tasks.update', $task), ['start_date' => null, 'due_date' => '2026-09-25'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $task->refresh();

    expect($task->start_date)->toBeNull()
        ->and($task->due_date?->toDateString())->toBe('2026-09-25');

    $geometry = ganttFor(
        ganttProps($this->actingAs($this->admin)->get(route('admin.tasks.gantt', ganttWindow()))->assertOk()),
        'A movable milestone',
    );

    expect($geometry['shape'])->toBe('milestone');
})->group('phase10');

it('refuses an employee a date drag and does not offer them the handles', function () {
    $task = Task::factory()->status(TaskStatus::InProgress)->assignedTo($this->tapuEmployee)->create([
        'project_id' => $this->project->id,
        'title' => 'Not theirs to re-plan',
        'start_date' => '2026-09-16',
        'due_date' => '2026-09-20',
    ]);

    $this->actingAs($this->tapu)
        ->put(route('employee.tasks.update', $task), ['start_date' => '2026-09-19', 'due_date' => '2026-09-23'])
        ->assertSessionHasErrors(['start_date', 'due_date']);

    expect($task->fresh()->start_date?->toDateString())->toBe('2026-09-16');

    // And the screen is told, from the same definition, so no handle is ever live for
    // somebody the write would refuse.
    $this->actingAs($this->tapu)
        ->get(route('employee.tasks.gantt', ganttWindow()))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('can_plan', false)->etc());
})->group('phase10');

it('refuses a date drag on an archived task with the state machine\'s own sentence', function () {
    $task = Task::factory()->status(TaskStatus::InProgress)->archived()->create([
        'project_id' => $this->project->id,
        'title' => 'Archived and frozen',
        'start_date' => '2026-09-16',
        'due_date' => '2026-09-20',
    ]);

    // A TaskStateException comes back 200 with a flashed sentence, which is exactly why
    // `mutateTask` treats a 2xx as "not necessarily accepted" and the bar goes back.
    $this->actingAs($this->admin)
        ->from(route('admin.tasks.gantt'))
        ->put(route('admin.tasks.update', $task), ['start_date' => '2026-09-19', 'due_date' => '2026-09-23'])
        ->assertRedirect();

    expect(session('error'))->toContain('archived');

    $task->refresh();

    expect($task->start_date?->toDateString())->toBe('2026-09-16')
        ->and($task->due_date?->toDateString())->toBe('2026-09-20');
})->group('phase10');

/*
|--------------------------------------------------------------------------
| The filters, which are the other three views' filters
|--------------------------------------------------------------------------
*/

it('wears the same filters as the other views, over the same query', function () {
    $other = Project::factory()->create(['name' => 'Gantt — Other Project']);

    Task::factory()->status(TaskStatus::Todo)->create([
        'project_id' => $this->project->id,
        'title' => 'Kept by the filter',
        'start_date' => '2026-09-16',
        'due_date' => '2026-09-20',
    ]);
    Task::factory()->status(TaskStatus::Todo)->create([
        'project_id' => $other->id,
        'title' => 'Dropped by the filter',
        'start_date' => '2026-09-16',
        'due_date' => '2026-09-20',
    ]);

    $props = ganttProps(
        $this->actingAs($this->admin)
            ->get(route('admin.tasks.gantt', ganttWindow(['project_id' => $this->project->id])))
            ->assertOk(),
    );

    expect(ganttTitles($props))->toContain('Kept by the filter')
        ->and(ganttTitles($props))->not->toContain('Dropped by the filter')
        // Echoed back, the way every other Tasks controller echoes them, so the chip bar
        // survives a switch between any two views.
        ->and($props['filters']['project_id'])->toBe($this->project->id);
})->group('phase10');

it('hides an archived task until it is asked for, like every other view', function () {
    Task::factory()->status(TaskStatus::InProgress)->archived()->create([
        'project_id' => $this->project->id,
        'title' => 'Archived task',
        'start_date' => '2026-09-16',
        'due_date' => '2026-09-20',
    ]);

    $without = ganttProps($this->actingAs($this->admin)->get(route('admin.tasks.gantt', ganttWindow()))->assertOk());
    $with = ganttProps(
        $this->actingAs($this->admin)->get(route('admin.tasks.gantt', ganttWindow(['archived' => 1])))->assertOk(),
    );

    expect(ganttTitles($without))->not->toContain('Archived task')
        ->and(ganttTitles($with))->toContain('Archived task');
})->group('phase10');
