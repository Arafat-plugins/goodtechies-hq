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
| Admin Board and Calendar
|--------------------------------------------------------------------------
|
| The Vue pages arrive in the next brief, so nothing here asserts on markup:
| these are the two routes, their gates and the exact shape of what they send.
|
| Two decisions are under test rather than merely implemented. The views are
| routes — `/admin/tasks/board`, `/admin/tasks/calendar` — rather than a
| `?view=` on the index, so each is deep-linkable and each has its own row in
| the permission matrix. And a card leaves the server through TaskResource,
| which composes ProjectResource rather than reading a project's columns, so no
| finance field can reach a board card by somebody widening a select list.
|
*/

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->manager = Employee::factory()->forRole(RoleName::MANAGER)->create()->user;
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();

    $this->project = Project::factory()->create();
});

/*
|--------------------------------------------------------------------------
| The Board
|--------------------------------------------------------------------------
*/

it('renders the board with a column per status, in lifecycle order, empty ones included', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.tasks.board'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Tasks/Board', false)
            ->has('board.columns', count(TaskStatus::cases()))
            ->has('board.total')
            ->has('board.overdue_count')
            ->where('board.columns.0.key', TaskStatus::Backlog->value)
            ->where('board.columns.0.label', TaskStatus::Backlog->label())
            ->where('board.columns.0.tone', TaskStatus::Backlog->tone())
            ->has('board.columns.0.count')
            ->has('board.columns.0.tasks')
            // The chip bar's option lists travel with the board, so the filters survive a
            // switch from the List view.
            ->has('filters')
            ->has('statuses')
            ->has('priorities')
            ->has('projects')
            ->has('tags')
            ->has('employees')
            ->has('transitions'),
        );
})->group('phase2');

it('puts the columns in the same order the enum draws the board in', function () {
    $page = $this->actingAs($this->admin)->get(route('admin.tasks.board'));

    $keys = collect($page->viewData('page')['props']['board']['columns'])->pluck('key')->all();

    expect($keys)->toBe(array_map(fn (TaskStatus $s): string => $s->value, TaskStatus::boardOrder()));
})->group('phase2');

it('sends a board card everything the card draws, and nothing from the project finance', function () {
    $task = Task::factory()->status(TaskStatus::Todo)->create([
        'project_id' => $this->project->id,
        'title' => 'A card with everything on it',
        'due_date' => '2026-09-15',
    ]);

    $this->actingAs($this->admin)
        ->get(route('admin.tasks.board', ['project_id' => $this->project->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Tasks/Board', false)
            ->has('board.columns.1.tasks.0', fn (Assert $card) => $card
                ->where('id', $task->id)
                ->where('title', 'A card with everything on it')
                ->where('due_date', '2026-09-15')
                ->has('priority')
                ->has('priority_label')
                ->has('status')
                ->has('status_tone')
                ->has('is_overdue')
                ->has('assignees')
                ->has('primary_assignee')
                ->has('tags')
                // Checklist counts exist because checklists do, and so now does the
                // attachment count — slice 4 gave the paperclip something real to print.
                // Comments arrive in the next slice and are still absent rather than sent as
                // a zero that would look like a count of something.
                ->has('subtask_count')
                ->has('subtasks_done_count')
                ->has('attachment_count')
                ->missing('comment_count')
                // The attachments themselves are the detail page's: a board must not sign a
                // URL per file per card to draw a number.
                ->missing('attachments')
                // The project fragment is ProjectResource's, not a select list of columns.
                ->has('project', fn (Assert $project) => $project
                    ->has('id')
                    ->has('name')
                    ->etc(),
                )
                ->etc(),
            ),
        );
})->group('phase2');

it('keeps the filters the view was switched with', function () {
    Task::factory()->status(TaskStatus::Todo)->create([
        'project_id' => $this->project->id,
        'title' => 'Keep me',
    ]);
    Task::factory()->status(TaskStatus::Todo)->create([
        'project_id' => $this->project->id,
        'title' => 'Drop me',
    ]);

    $this->actingAs($this->admin)
        ->get(route('admin.tasks.board', ['project_id' => $this->project->id, 'search' => 'keep']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('board.total', 1)
            ->where('filters.search', 'keep')
            ->where('filters.project_id', $this->project->id)
            ->where('board.columns.1.tasks.0.title', 'Keep me'),
        );
})->group('phase2');

it('tells the board which columns this role may drag between', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.tasks.board'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('transitions.in_review', fn ($targets): bool => in_array('completed', collect($targets)->all(), true))
            ->where('transitions.todo', fn ($targets): bool => in_array('cancelled', collect($targets)->all(), true))
            ->etc(),
        );
})->group('phase2');

/*
|--------------------------------------------------------------------------
| The Calendar
|--------------------------------------------------------------------------
*/

it('renders the calendar with the window it was asked for', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.tasks.calendar', ['date_from' => '2026-09-01', 'date_to' => '2026-09-30']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Tasks/Calendar', false)
            // The grid is told what it asked for rather than left to infer it from the tasks
            // it happened to get, which would be wrong on the first empty month.
            ->where('calendar.window.from', '2026-09-01')
            ->where('calendar.window.to', '2026-09-30')
            ->where('calendar.window.days', 30)
            ->where('calendar.window.month', '2026-09')
            ->has('calendar.tasks')
            ->has('calendar.total')
            ->has('calendar.unscheduled_count')
            ->has('can_plan')
            ->has('filters')
            ->has('projects')
            ->has('tags')
            ->has('employees'),
        );
})->group('phase2');

it('falls back to the current month when the calendar is asked for nothing', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.tasks.calendar'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('calendar.window.from', now()->startOfMonth()->toDateString())
            ->where('calendar.window.to', now()->endOfMonth()->toDateString())
            ->where('calendar.window.month', now()->format('Y-m'))
            ->etc(),
        );
})->group('phase2');

it('sends a calendar task its span, clipped to the window', function () {
    $task = Task::factory()->status(TaskStatus::Todo)->create([
        'project_id' => $this->project->id,
        'title' => 'Straddles into September',
        'start_date' => '2026-08-28',
        'due_date' => '2026-09-03',
    ]);

    $this->actingAs($this->admin)
        ->get(route('admin.tasks.calendar', [
            'project_id' => $this->project->id,
            'date_from' => '2026-09-01',
            'date_to' => '2026-09-30',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('calendar.total', 1)
            ->has('calendar.tasks.0', fn (Assert $entry) => $entry
                ->where('id', $task->id)
                ->where('start_date', '2026-08-28')
                ->where('due_date', '2026-09-03')
                ->has('span', fn (Assert $span) => $span
                    ->where('start', '2026-08-28')
                    ->where('end', '2026-09-03')
                    ->where('visible_start', '2026-09-01')
                    ->where('visible_end', '2026-09-03')
                    ->where('days', 3)
                    ->where('total_days', 7)
                    ->where('continues_before', true)
                    ->where('continues_after', false)
                    ->where('is_single_day', false),
                )
                ->etc(),
            ),
        );
})->group('phase2');

it('refuses a window that ends before it starts, with a message rather than an empty grid', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.tasks.calendar', ['date_from' => '2026-09-30', 'date_to' => '2026-09-01']))
        ->assertRedirect()
        ->assertSessionHasErrors('date_to');

    $this->actingAs($this->admin)
        ->get(route('admin.tasks.calendar', ['date_from' => 'banana']))
        ->assertRedirect()
        ->assertSessionHasErrors('date_from');
})->group('phase2');

it('tells the calendar an admin may drag a date', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.tasks.calendar'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('can_plan', true)->etc());
})->group('phase2');

/*
|--------------------------------------------------------------------------
| Who may reach them
|--------------------------------------------------------------------------
*/

it('keeps every other role off the admin board and calendar', function () {
    foreach (['admin.tasks.board', 'admin.tasks.calendar'] as $route) {
        foreach ([$this->manager, $this->tapu, $this->accountant] as $user) {
            $this->actingAs($user)->get(route($route))->assertForbidden();
        }
    }

    // The guest cell is the matrix's — it forgets the guard between cells, which this loop
    // cannot do after an actingAs.
})->group('phase2');

it('refuses the accountant the board and the calendar on every surface', function () {
    // Four routes, one role, 403 on all four: the surface middleware answers first on the
    // employee pair and viewAny would refuse them anyway — they hold no tasks.* permission.
    foreach ([
        'admin.tasks.board',
        'admin.tasks.calendar',
        'employee.tasks.board',
        'employee.tasks.calendar',
    ] as $route) {
        $this->actingAs($this->accountant)->get(route($route))->assertForbidden();
    }
})->group('phase2');

it('still renders the list, which the same query now also windows', function () {
    // TaskService::query() grew a predicate, and the List view runs through it too.
    $this->actingAs($this->admin)
        ->get(route('admin.tasks.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Admin/Tasks/Index')->has('tasks.groups'));
})->group('phase2');
