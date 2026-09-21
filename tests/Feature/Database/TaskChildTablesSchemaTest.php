<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\TaskLink;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Phase 2 slice 2 schema — checklists, links, dependencies, completion history
|--------------------------------------------------------------------------
|
| The constraints the service layer is allowed to assume, and the columns the
| completion rules read. A constraint that is not asserted here is a constraint
| somebody can drop without a test noticing.
|
*/

beforeEach(function () {
    $this->seed();
});

it('creates the three child tables', function (string $table) {
    expect(Schema::hasTable($table))->toBeTrue();
})->with([['task_checklists'], ['task_links'], ['task_dependencies']])->group('phase2');

it('carries the columns the completion rules read', function (string $column) {
    expect(Schema::hasColumn('tasks', $column))->toBeTrue();
})->with([
    // Who wrote the work summary — without it, "the PRIMARY assignee's summary" is unenforceable.
    ['work_summary_by'], ['work_summary_at'],
    // The first completion, which a reopening must not take with it.
    ['first_work_summary'], ['first_completed_by'], ['first_completed_at'],
])->group('phase2');

it('kept the soft delete the read slice already added', function () {
    // d3bfea1 created tasks with softDeletes(), so this slice added no column for it — it only
    // made something use it. The assertion is here so a later migration cannot quietly drop it.
    expect(Schema::hasColumn('tasks', 'deleted_at'))->toBeTrue();
})->group('phase2');

it('refuses a task that depends on itself, in the database', function () {
    $task = Task::factory()->create();

    expect(fn () => DB::table('task_dependencies')->insert([
        'task_id' => $task->id,
        'depends_on_task_id' => $task->id,
    ]))->toThrow(QueryException::class);
})->group('phase2');

it('refuses the same dependency twice', function () {
    $project = Project::factory()->create();
    $a = Task::factory()->create(['project_id' => $project->id]);
    $b = Task::factory()->create(['project_id' => $project->id]);

    DB::table('task_dependencies')->insert(['task_id' => $a->id, 'depends_on_task_id' => $b->id]);

    expect(fn () => DB::table('task_dependencies')->insert([
        'task_id' => $a->id,
        'depends_on_task_id' => $b->id,
    ]))->toThrow(QueryException::class);
})->group('phase2');

it('indexes each child table the way it is actually read', function (string $table, string $index) {
    $indexes = collect(DB::select('select indexname from pg_indexes where tablename = ?', [$table]))
        ->pluck('indexname')
        ->all();

    expect($indexes)->toContain($index);
})->with([
    // One task's checklist, in order: the only way that table is ever read.
    ['task_checklists', 'task_checklists_task_id_position_index'],
    ['task_links', 'task_links_task_id_id_index'],
    // The pair is the primary key, and the reverse direction — "what is waiting on this task" —
    // needs its own index because the primary key has the wrong leading column.
    ['task_dependencies', 'task_dependencies_pkey'],
    ['task_dependencies', 'task_dependencies_depends_on_task_id_task_id_index'],
])->group('phase2');

it('takes a task\'s children with it on a hard delete, and leaves them on a soft one', function () {
    $task = Task::factory()->create();
    TaskChecklistItem::create(['task_id' => $task->id, 'title' => 'A line', 'position' => 1000]);
    TaskLink::create(['task_id' => $task->id, 'url' => 'https://example.com']);

    // A soft delete is what the application does, and it keeps everything.
    $task->delete();

    expect(TaskChecklistItem::where('task_id', $task->id)->count())->toBe(1)
        ->and(TaskLink::where('task_id', $task->id)->count())->toBe(1);

    // A hard delete is what a purge would do, and the foreign keys cascade.
    $task->forceDelete();

    expect(TaskChecklistItem::where('task_id', $task->id)->count())->toBe(0)
        ->and(TaskLink::where('task_id', $task->id)->count())->toBe(0);
})->group('phase2');

it('lets hq_app write every new table', function (string $table) {
    // The runtime role owns nothing and is granted per table by deploy/sql/roles.sql's default
    // privileges. A new table that the app cannot write is a deploy-day failure.
    expect(DB::connection('pgsql')->table($table)->count())->toBeInt();
})->with([['task_checklists'], ['task_links'], ['task_dependencies']])->group('phase2');
