<?php

use App\Models\Employee;
use App\Models\Project;
use App\Models\Tag;
use App\Models\Task;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Phase 2 schema — tasks, task_assignees, tags, task_tags
|--------------------------------------------------------------------------
|
| The columns later phases depend on, and the constraints the service layer is
| allowed to assume. An index that is not asserted here is an index somebody
| can delete without a test noticing.
|
*/

beforeEach(function () {
    $this->seed();
});

it('creates the four Phase 2 tables', function (string $table) {
    expect(Schema::hasTable($table))->toBeTrue();
})->with([['tasks'], ['task_assignees'], ['tags'], ['task_tags']])->group('phase2');

it('leaves the recurring columns nullable and empty on a hand-made task', function () {
    // Phase 2 created these nullable so Phase 3 could add its table and a foreign key without
    // ALTERing a populated tasks table. Phase 3 renamed the first to the name the spec gives it
    // (`recurring_task_id`) and constrained both — see
    // 2026_09_23_000003_add_recurring_origin_to_tasks_table. They stay nullable, because the
    // overwhelming majority of tasks are made by hand and belong to no period at all.
    expect(Schema::hasColumn('tasks', 'recurring_task_id'))->toBeTrue()
        ->and(Schema::hasColumn('tasks', 'recurring_period'))->toBeTrue();

    $nullable = fn (string $column): bool => DB::selectOne(
        'select is_nullable from information_schema.columns where table_name = ? and column_name = ?',
        ['tasks', $column],
    )->is_nullable === 'YES';

    expect($nullable('recurring_task_id'))->toBeTrue()
        ->and($nullable('recurring_period'))->toBeTrue()
        // The seeders make templates and generate nothing, so every seeded task is hand-made.
        ->and(Task::whereNotNull('recurring_task_id')->count())->toBe(0)
        ->and(Task::whereNotNull('recurring_period')->count())->toBe(0);
})->group('phase2');

it('carries every column the spec names', function (string $column) {
    expect(Schema::hasColumn('tasks', $column))->toBeTrue();
})->with([
    ['title'], ['description'], ['project_id'], ['priority'], ['start_date'], ['due_date'],
    ['created_at'], ['created_by'], ['status'], ['estimated_minutes'], ['tracked_seconds'],
    ['work_summary'], ['completed_by'], ['completed_at'], ['position'], ['archived_at'],
    ['deleted_at'],
])->group('phase2');

it('indexes what the List view filters and sorts on', function (string $index) {
    $indexes = collect(DB::select('select indexname from pg_indexes where tablename = ?', ['tasks']))
        ->pluck('indexname')
        ->all();

    expect($indexes)->toContain($index);
})->with([
    // The Kanban column: one project, one status, in drag order.
    ['tasks_project_id_status_position_index'],
    // The List view's default: grouped by status, sorted by due date.
    ['tasks_status_due_date_index'],
    // The group-by-priority variant.
    ['tasks_priority_due_date_index'],
    // The overdue bucket, which crosses every status.
    ['tasks_due_date_index'],
])->group('phase2');

it('indexes the assignee direction both lists read', function () {
    $indexes = collect(DB::select('select indexname from pg_indexes where tablename = ?', ['task_assignees']))
        ->pluck('indexname')
        ->all();

    expect($indexes)->toContain('task_assignees_employee_id_task_id_index')
        ->and($indexes)->toContain('task_assignees_task_id_employee_id_unique')
        ->and($indexes)->toContain('task_assignees_one_primary_per_task');
})->group('phase2');

it('allows at most one primary assignee per task', function () {
    $project = Project::query()->firstOrFail();
    $a = Employee::where('employee_number', 'GT-003')->firstOrFail();
    $b = Employee::where('employee_number', 'GT-004')->firstOrFail();

    $task = Task::factory()->for($project)->create(['title' => 'Two-primary attempt']);

    $task->assignees()->attach($a->id, ['is_primary' => true]);

    // A second NON-primary assignee is the ordinary two-person case and must be fine...
    $task->assignees()->attach($b->id, ['is_primary' => false]);
    expect($task->assignees()->count())->toBe(2);

    // ...but a second PRIMARY is refused by the database, not merely by the service.
    expect(fn () => DB::table('task_assignees')
        ->where('task_id', $task->id)
        ->where('employee_id', $b->id)
        ->update(['is_primary' => true]))
        ->toThrow(QueryException::class);
})->group('phase2');

it('refuses the same person on a task twice', function () {
    $task = Task::factory()->for(Project::query()->firstOrFail())->create(['title' => 'Duplicate assignee attempt']);
    $employee = Employee::where('employee_number', 'GT-003')->firstOrFail();

    $task->assignees()->attach($employee->id, ['is_primary' => true]);

    expect(fn () => $task->assignees()->attach($employee->id, ['is_primary' => false]))
        ->toThrow(QueryException::class);
})->group('phase2');

it('refuses the same tag on a task twice', function () {
    $task = Task::factory()->for(Project::query()->firstOrFail())->create(['title' => 'Duplicate tag attempt']);
    $tag = Tag::where('name', 'SEO')->firstOrFail();

    $task->tags()->attach($tag->id);

    expect(fn () => $task->tags()->attach($tag->id))->toThrow(QueryException::class);
})->group('phase2');

it('refuses two tags of the same name in the same scope', function () {
    $project = Project::query()->firstOrFail();

    Tag::create(['project_id' => $project->id, 'name' => 'Scoped', 'colour' => 'progress']);

    expect(fn () => Tag::create(['project_id' => $project->id, 'name' => 'Scoped', 'colour' => 'done']))
        ->toThrow(QueryException::class);
})->group('phase2');

it('lets a tag be global or scoped to one project', function () {
    $project = Project::query()->firstOrFail();

    $global = Tag::where('name', 'SEO')->firstOrFail();
    $scoped = Tag::create(['project_id' => $project->id, 'name' => 'Client-specific', 'colour' => 'review']);

    expect($global->isGlobal())->toBeTrue()
        ->and($scoped->isGlobal())->toBeFalse()
        // The picker for a project offers its own plus every global one.
        ->and(Tag::query()->usableOn($project)->pluck('id')->all())
        ->toContain($global->id)
        ->toContain($scoped->id);

    $other = Project::where('id', '!=', $project->id)->firstOrFail();
    expect(Tag::query()->usableOn($other)->pluck('id')->all())->not->toContain($scoped->id);
})->group('phase2');

it('takes a task\'s assignees and tags with it when it is force-deleted', function () {
    $task = Task::factory()
        ->for(Project::query()->firstOrFail())
        ->assignedTo(Employee::where('employee_number', 'GT-003')->firstOrFail())
        ->create(['title' => 'Cascade check']);

    $task->tags()->attach(Tag::where('name', 'SEO')->firstOrFail()->id);

    $id = $task->id;
    $task->forceDelete();

    expect(DB::table('task_assignees')->where('task_id', $id)->count())->toBe(0)
        ->and(DB::table('task_tags')->where('task_id', $id)->count())->toBe(0);
})->group('phase2');

it('keeps a soft-deleted task and its pivots', function () {
    $task = Task::factory()
        ->for(Project::query()->firstOrFail())
        ->assignedTo(Employee::where('employee_number', 'GT-003')->firstOrFail())
        ->create(['title' => 'Soft delete check']);

    $task->delete();

    // Soft so the history survives: the row and its joins are still there.
    expect(Task::whereKey($task->id)->exists())->toBeFalse()
        ->and(Task::withTrashed()->whereKey($task->id)->exists())->toBeTrue()
        ->and(DB::table('task_assignees')->where('task_id', $task->id)->count())->toBe(1);
})->group('phase2');

it('seeds 25 demo tasks with the four spec tags', function () {
    expect(Task::count())->toBe(25)
        ->and(Tag::pluck('name')->sort()->values()->all())
        ->toBe(['Branding', 'Development', 'Maintenance', 'SEO']);

    // Every seeded task has a start date or is explicitly unstarted, a project, and a creator.
    expect(Task::whereNull('project_id')->count())->toBe(0)
        ->and(Task::whereNull('created_by')->count())->toBe(0)
        ->and(Task::whereNotNull('start_date')->count())->toBeGreaterThan(0);

    // Some overdue, some in review — the demo has to look real.
    expect(Task::query()->overdue()->count())->toBeGreaterThan(0)
        ->and(Task::where('status', 'in_review')->count())->toBeGreaterThan(0);

    // Every task that reached review or completion carries its mandatory work summary.
    expect(Task::whereIn('status', ['in_review', 'completed', 'changes_requested'])
        ->whereNull('work_summary')
        ->count())->toBe(0);
})->group('phase2');

it('reseeds without duplicating a task', function () {
    $before = Task::count();

    $this->seed();

    expect(Task::count())->toBe($before);
})->group('phase2');
