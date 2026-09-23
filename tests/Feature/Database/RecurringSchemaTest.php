<?php

use App\Models\Employee;
use App\Models\Project;
use App\Models\RecurringGenerationLog;
use App\Models\RecurringTask;
use App\Models\Task;
use App\Support\GenerationOutcome;
use App\Support\RecurrenceFrequency;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Phase 3 schema — recurring_tasks, recurring_generation_log, tasks' origin
|--------------------------------------------------------------------------
|
| An index that is not asserted here is an index somebody can delete without a
| test noticing, and one of the indexes here is the entire duplicate rule.
|
*/

beforeEach(function () {
    $this->seed();
});

it('creates the two Phase 3 tables', function (string $table) {
    expect(Schema::hasTable($table))->toBeTrue();
})->with([['recurring_tasks'], ['recurring_generation_log']])->group('phase3');

it('carries every column the spec names on recurring_tasks', function (string $column) {
    expect(Schema::hasColumn('recurring_tasks', $column))->toBeTrue();
})->with([
    ['project_id'], ['title_template'], ['checklist_template'], ['recurrence_rule'],
    ['next_run_at'], ['default_assignee_id'], ['active'], ['created_at'], ['updated_at'],
    // Not in the spec's list: the case in a column of its own so a list can filter on it and a
    // Form Request can validate against it without opening the JSON, and the author, who is the
    // first candidate for the actor the engine creates instances as.
    ['frequency'], ['created_by'],
])->group('phase3');

it('carries every column the spec names on recurring_generation_log', function (string $column) {
    expect(Schema::hasColumn('recurring_generation_log', $column))->toBeTrue();
})->with([
    ['recurring_task_id'], ['period'], ['outcome'], ['task_id'], ['created_at'],
    // The previous-open-instance flag (§6) and the sentence explaining a skip (§21).
    ['previous_open_task_id'], ['message'],
])->group('phase3');

it('renames the Phase 2 placeholder column to the name the spec gives it', function () {
    // Phase 2 created `recurring_template_id` before the table existed. Renaming it while every
    // value was still NULL cost nothing; leaving it would have left a column whose name did not
    // match the table it points at for the life of the product.
    expect(Schema::hasColumn('tasks', 'recurring_task_id'))->toBeTrue()
        ->and(Schema::hasColumn('tasks', 'recurring_period'))->toBeTrue()
        ->and(Schema::hasColumn('tasks', 'recurring_template_id'))->toBeFalse();
})->group('phase3');

/*
|--------------------------------------------------------------------------
| The unique index: the whole duplicate rule
|--------------------------------------------------------------------------
*/

it('holds the partial unique index the engine rests on', function () {
    $index = DB::selectOne(
        'select indexdef from pg_indexes where tablename = ? and indexname = ?',
        ['tasks', 'tasks_recurring_task_period_unique'],
    );

    expect($index)->not->toBeNull();

    $definition = strtolower((string) $index->indexdef);

    expect($definition)->toContain('unique')
        ->toContain('recurring_task_id')
        ->toContain('recurring_period')
        // Partial, so the overwhelming majority of tasks — the hand-made ones — cost it nothing.
        ->toContain('where (recurring_task_id is not null)');

    // And it deliberately does NOT exclude soft-deleted rows: a deleted instance still occupies
    // its period, so tomorrow's 00:05 run cannot resurrect a task somebody deleted on purpose.
    expect($definition)->not->toContain('deleted_at');
})->group('phase3');

it('refuses a second instance for the same template and period, including against a deleted one', function () {
    $project = Project::query()->firstOrFail();
    $template = RecurringTask::factory()->for($project)->create();

    $row = [
        'project_id' => $project->id,
        'title' => 'October',
        'status' => 'todo',
        'priority' => 'medium',
        'position' => 0,
        'recurring_task_id' => $template->id,
        'recurring_period' => '2026-10',
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('tasks')->insert($row);

    expect(fn () => DB::transaction(fn () => DB::table('tasks')->insert($row)))
        ->toThrow(QueryException::class);

    // Soft-delete it and try again: still refused, because the index counts deleted rows.
    Task::where('recurring_task_id', $template->id)->delete();

    expect(fn () => DB::transaction(fn () => DB::table('tasks')->insert($row)))
        ->toThrow(QueryException::class);
})->group('phase3');

it('lets the same period exist on two different templates', function () {
    $project = Project::query()->firstOrFail();
    $a = RecurringTask::factory()->for($project)->create();
    $b = RecurringTask::factory()->for($project)->create();

    foreach ([$a, $b] as $template) {
        DB::table('tasks')->insert([
            'project_id' => $project->id,
            'title' => 'October for #'.$template->id,
            'status' => 'todo',
            'priority' => 'medium',
            'position' => 0,
            'recurring_task_id' => $template->id,
            'recurring_period' => '2026-10',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    expect(Task::whereNotNull('recurring_task_id')->count())->toBe(2);
})->group('phase3');

/*
|--------------------------------------------------------------------------
| What survives what
|--------------------------------------------------------------------------
*/

it('keeps the instances when the template is deleted, and takes the log with it', function () {
    $project = Project::query()->firstOrFail();
    $template = RecurringTask::factory()->for($project)->create();
    $task = Task::factory()->for($project)->create(['title' => 'Generated once']);

    $task->forceFill(['recurring_task_id' => $template->id, 'recurring_period' => '2026-10'])->save();

    RecurringGenerationLog::create([
        'recurring_task_id' => $template->id,
        'period' => '2026-10',
        'outcome' => GenerationOutcome::Generated,
        'task_id' => $task->id,
    ]);

    $template->delete();

    // The task lives on — deleting a template must not delete two years of completed
    // maintenance work — and keeps a period string that still reads back as a label.
    $task->refresh();

    expect(Task::whereKey($task->id)->exists())->toBeTrue()
        ->and($task->recurring_task_id)->toBeNull()
        ->and($task->recurring_period)->toBe('2026-10')
        ->and($task->recurringPeriodLabel())->toBe('October 2026')
        // The log is the template's own history and goes with it.
        ->and(RecurringGenerationLog::count())->toBe(0);
})->group('phase3');

it('keeps the log row when the task it points at is force-deleted', function () {
    $project = Project::query()->firstOrFail();
    $template = RecurringTask::factory()->for($project)->create();
    $task = Task::factory()->for($project)->create();

    $log = RecurringGenerationLog::create([
        'recurring_task_id' => $template->id,
        'period' => '2026-10',
        'outcome' => GenerationOutcome::Generated,
        'task_id' => $task->id,
    ]);

    $task->forceDelete();

    // The log has to keep saying October was generated even after somebody deletes it outright.
    expect($log->refresh()->outcome)->toBe(GenerationOutcome::Generated)
        ->and($log->task_id)->toBeNull();
})->group('phase3');

it('indexes what the templates screen and the sweep read', function () {
    $indexes = fn (string $table): array => collect(
        DB::select('select indexname from pg_indexes where tablename = ?', [$table]),
    )->pluck('indexname')->all();

    expect($indexes('recurring_tasks'))
        // The project detail page's Recurring tab.
        ->toContain('recurring_tasks_project_id_active_index')
        // The next-run preview.
        ->toContain('recurring_tasks_next_run_at_index');

    expect($indexes('recurring_generation_log'))
        // One template's attempts, newest first.
        ->toContain('recurring_generation_log_recurring_task_id_id_index')
        // due()'s question: has this template been attempted for this period?
        ->toContain('recurring_generation_log_recurring_task_id_period_index');
})->group('phase3');

/*
|--------------------------------------------------------------------------
| Seeded templates
|--------------------------------------------------------------------------
*/

it('seeds the three retainers the spec names, to the people it names', function () {
    $templates = RecurringTask::query()
        ->with(['project', 'defaultAssignee'])
        ->orderBy('id')
        ->get();

    expect($templates)->toHaveCount(3);

    $byTitle = $templates->keyBy('title_template');

    expect($byTitle->keys()->all())->toBe([
        'abc.com Monthly Maintenance — {period}',
        'Heat Gap Monthly SEO Tasks — {period}',
        'Buffalo Modular Monthly SEO — {period}',
    ]);

    $maintenance = $byTitle['abc.com Monthly Maintenance — {period}'];

    expect($maintenance->project->name)->toBe('abc.com — Monthly Maintenance')
        // Part D §6's eight-item example, verbatim.
        ->and($maintenance->checklistItems())->toHaveCount(8)
        ->and($maintenance->checklistItems()[0])->toBe('WordPress core updates')
        // Yaseen, the office employee who holds the maintenance projects.
        ->and($maintenance->defaultAssignee?->employee_number)->toBe('GT-004')
        ->and($maintenance->frequency)->toBe(RecurrenceFrequency::Monthly)
        ->and($maintenance->active)->toBeTrue()
        ->and($maintenance->next_run_at)->not->toBeNull()
        // The actor the engine will borrow: the project's PM.
        ->and($maintenance->created_by)->toBe($maintenance->project->pm?->user_id);

    // Both SEO retainers are Tapu's.
    expect($byTitle['Heat Gap Monthly SEO Tasks — {period}']->defaultAssignee?->employee_number)->toBe('GT-003')
        ->and($byTitle['Buffalo Modular Monthly SEO — {period}']->defaultAssignee?->employee_number)->toBe('GT-003');
})->group('phase3');

it('seeds templates and generates nothing', function () {
    // A seeder that pre-generated a month would be a second creation path with none of the
    // engine's rules — exactly what this phase exists to make impossible.
    expect(Task::whereNotNull('recurring_task_id')->count())->toBe(0)
        ->and(Task::whereNotNull('recurring_period')->count())->toBe(0)
        ->and(RecurringGenerationLog::count())->toBe(0);
})->group('phase3');

it('reseeds without duplicating a template', function () {
    // start-hq.bat runs db:seed on every start. A seeder that duplicated would give the agency
    // three copies of October's maintenance task — all of them legitimate, since the unique
    // index is per template.
    $before = RecurringTask::count();

    $this->seed();
    $this->seed();

    expect(RecurringTask::count())->toBe($before);
})->group('phase3');

it('refreshes a seeded template rather than leaving a stale row behind', function () {
    $template = RecurringTask::query()->orderBy('id')->firstOrFail();

    $template->forceFill([
        'checklist_template' => ['Something somebody typed'],
        'default_assignee_id' => Employee::where('employee_number', 'GT-001')->firstOrFail()->id,
        'active' => false,
    ])->save();

    $this->seed();

    // updateOrCreate, not firstOrCreate: an edited seeder should change the seeded data.
    expect($template->fresh()->checklistItems())->toHaveCount(8)
        ->and($template->fresh()->defaultAssignee?->employee_number)->toBe('GT-004')
        ->and($template->fresh()->active)->toBeTrue();
})->group('phase3');
