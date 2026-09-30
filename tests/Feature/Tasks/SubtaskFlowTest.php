<?php

use App\Events\TaskChanged;
use App\Models\Notification;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskService;
use App\Support\NotificationType;
use App\Support\TaskStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| F2 task-subtasks — a parent task is split into real, followed-up subtasks
|--------------------------------------------------------------------------
|
| A subtask is a task with a `parent_id`: its own assignee, due date and status
| machine, born through `TaskService::create()` so the assignment notification,
| the audit/activity rows and the F1 `TaskChanged` broadcast all fire exactly as
| they do for any task. One level deep only; the subtask lives in its parent's
| project; archive and delete of a parent carry its subtasks with it.
|
| Privacy: an employee assigned to the subtask but not to the parent sees the
| subtask, and NOTHING of the parent — no `parent` key, and 404 on its id.
|
| Constants and helpers are global in Pest, so everything here is SUBTASK_.
|
*/

const SUBTASK_TITLE = 'Draft the hero copy';

/**
 * Every task id on a List or Board payload.
 *
 * @return list<int>
 */
function SUBTASK_ids(array $props): array
{
    $groups = $props['tasks']['groups'] ?? $props['board']['columns'];

    return collect($groups)->flatMap(fn (array $group) => array_column($group['tasks'], 'id'))
        ->map('intval')->unique()->values()->all();
}

/** One task's row off a Board payload. */
function SUBTASK_card(array $props, int $id): ?array
{
    return collect($props['board']['columns'])->flatMap(fn (array $column) => $column['tasks'])
        ->firstWhere('id', $id);
}

beforeEach(function () {
    $this->seed();

    $this->tasks = app(TaskService::class);
    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();

    $this->project = Project::factory()->create(['name' => 'Subtask flow project']);

    // Tapu's task. Yaseen is NOT on it, so Yaseen may not see it.
    $this->parent = Task::factory()->for($this->project)->status(TaskStatus::Todo)
        ->assignedTo($this->tapu->employee)->create(['title' => 'Launch the landing page']);
});

/** Create a subtask of the parent over HTTP, as the admin, assigned to Yaseen. */
function SUBTASK_create(object $test, ?Task $parent = null, array $extra = []): Task
{
    $parent ??= $test->parent;

    $test->actingAs($test->admin)
        ->post("/admin/tasks/{$parent->id}/subtasks", [
            'title' => SUBTASK_TITLE,
            'assignee_id' => $test->yaseen->employee->id,
            'due_date' => Carbon::today()->addDays(3)->toDateString(),
            ...$extra,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    return Task::query()->where('parent_id', $parent->id)->latest('id')->firstOrFail();
}

it('creates a subtask in the parent\'s project with its own assignee and due date', function () {
    $sub = SUBTASK_create($this);

    expect((int) $sub->parent_id)->toBe($this->parent->id)
        ->and((int) $sub->project_id)->toBe((int) $this->parent->project_id)
        ->and($sub->title)->toBe(SUBTASK_TITLE)
        ->and($sub->due_date->toDateString())->toBe(Carbon::today()->addDays(3)->toDateString())
        ->and($sub->assignees()->pluck('employees.id')->map('intval')->all())->toBe([(int) $this->yaseen->employee->id]);
})->group('subtasks');

it('tells the subtask\'s assignee through the existing assignment notification', function () {
    Notification::query()->delete();

    $sub = SUBTASK_create($this);

    $rows = Notification::query()->where('user_id', $this->yaseen->id)->get();

    expect($rows->pluck('type')->map(fn ($type) => $type instanceof BackedEnum ? $type->value : $type)->all())
        ->toContain(NotificationType::TaskAssigned->value);
})->group('subtasks');

it('broadcasts TaskChanged for the new subtask and for its parent, ids only', function () {
    $collected = [];
    Event::listen(TaskChanged::class, function (TaskChanged $event) use (&$collected): void {
        $collected[] = $event;
    });

    $sub = SUBTASK_create($this);

    $ids = array_map(fn (TaskChanged $event): int => (int) $event->broadcastWith()['task_id'], $collected);

    expect($ids)->toContain((int) $sub->id)
        ->and($ids)->toContain((int) $this->parent->id);

    foreach ($collected as $event) {
        expect(array_keys($event->broadcastWith()))->toBe(['task_id', 'kind']);
    }
})->group('subtasks');

it('counts the parent\'s subtasks and the completed ones on the board card', function () {
    $first = SUBTASK_create($this);
    SUBTASK_create($this);

    // Through the machine, exactly as people would: Yaseen starts it and sends it for review
    // with his summary, and the project's reviewer passes it.
    $this->tasks->transition($this->yaseen, $first, TaskStatus::InProgress);
    $this->tasks->transition($this->yaseen, $first->refresh(), TaskStatus::InReview, 'Hero copy drafted.');
    $reviewer = $this->tasks->reviewersFor($first)->first();
    $this->tasks->transition($reviewer, $first->refresh(), TaskStatus::Completed);

    $props = $this->actingAs($this->admin)->get('/admin/tasks/board')->assertOk()->inertiaPage()['props'];
    $card = SUBTASK_card($props, $this->parent->id);

    expect($card['subtask_count'])->toBe(2)
        ->and($card['subtask_done_count'])->toBe(1);
})->group('subtasks');

it('refuses a subtask of a subtask with 403: one level deep only', function () {
    $sub = SUBTASK_create($this);

    $this->actingAs($this->admin)
        ->post("/admin/tasks/{$sub->id}/subtasks", ['title' => 'Too deep'])
        ->assertForbidden();

    expect(Task::query()->where('parent_id', $sub->id)->exists())->toBeFalse();
})->group('subtasks');

it('does not let an employee create a subtask', function () {
    $this->actingAs($this->yaseen)
        ->post("/admin/tasks/{$this->parent->id}/subtasks", ['title' => 'Mine now'])
        ->assertForbidden();

    expect(Task::query()->where('parent_id', $this->parent->id)->exists())->toBeFalse();
})->group('subtasks');

it('shows an employee their subtask but nothing of a parent they may not see', function () {
    $sub = SUBTASK_create($this);

    // In their own scope…
    $mine = $this->actingAs($this->yaseen)->get('/employee/tasks?scope=mine')->assertOk()->inertiaPage()['props'];
    expect(SUBTASK_ids($mine))->toContain((int) $sub->id);

    $row = collect($mine['tasks']['groups'])->flatMap(fn ($g) => $g['tasks'])->firstWhere('id', $sub->id);
    expect($row)->not->toHaveKey('parent');

    // …and its detail opens, with no parent key at all — absent, not null.
    $task = $this->actingAs($this->yaseen)->get("/employee/tasks/{$sub->id}")->assertOk()->inertiaPage()['props']['task'];
    expect($task)->not->toHaveKey('parent')
        ->and(json_encode($task))->not->toContain('Launch the landing page');

    // The parent itself is absent for them.
    $this->actingAs($this->yaseen)->get("/employee/tasks/{$this->parent->id}")->assertNotFound();
})->group('subtasks', 'privacy');

it('shows the parent to a viewer who may see it', function () {
    $sub = SUBTASK_create($this);

    $task = $this->actingAs($this->admin)->get("/admin/tasks/{$sub->id}")->assertOk()->inertiaPage()['props']['task'];

    expect($task['parent'])->toBe(['id' => $this->parent->id, 'title' => 'Launch the landing page']);

    $parent = $this->actingAs($this->admin)->get("/admin/tasks/{$this->parent->id}")->assertOk()->inertiaPage()['props']['task'];

    expect(array_column($parent['subtasks'], 'id'))->toBe([(int) $sub->id])
        ->and($parent['subtasks'][0])->toHaveKeys(['id', 'title', 'status', 'assignees', 'due_date', 'is_overdue']);
})->group('subtasks');

it('keeps subtasks off the general Board and List unless Show subtasks is on', function () {
    $sub = SUBTASK_create($this);

    foreach (['/admin/tasks', '/admin/tasks/board'] as $path) {
        $default = $this->actingAs($this->admin)->get($path)->assertOk()->inertiaPage()['props'];
        $shown = $this->actingAs($this->admin)->get("{$path}?subtasks=1")->assertOk()->inertiaPage()['props'];

        expect(SUBTASK_ids($default))->not->toContain((int) $sub->id)
            ->and(SUBTASK_ids($default))->toContain((int) $this->parent->id)
            ->and(SUBTASK_ids($shown))->toContain((int) $sub->id)
            ->and($default['filters']['subtasks'])->toBeFalse()
            ->and($shown['filters']['subtasks'])->toBeTrue();
    }

    // A person's own scope always carries their subtasks.
    $mine = $this->actingAs($this->yaseen)->get('/employee/tasks/board?scope=mine')->assertOk()->inertiaPage()['props'];
    expect(SUBTASK_ids($mine))->toContain((int) $sub->id);
})->group('subtasks');

it('carries archive, unarchive and delete of a parent to its subtasks', function () {
    $sub = SUBTASK_create($this);

    $this->actingAs($this->admin)->post("/admin/tasks/{$this->parent->id}/archive")->assertRedirect();
    expect($sub->refresh()->archived_at)->not->toBeNull();

    $this->actingAs($this->admin)->post("/admin/tasks/{$this->parent->id}/unarchive")->assertRedirect();
    expect($sub->refresh()->archived_at)->toBeNull();

    $this->actingAs($this->admin)->delete("/admin/tasks/{$this->parent->id}")->assertRedirect();
    expect(Task::withTrashed()->find($sub->id)->trashed())->toBeTrue();
})->group('subtasks');
