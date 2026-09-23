<?php

use App\Models\Employee;
use App\Models\Project;
use App\Models\RecurringGenerationLog;
use App\Models\RecurringTask;
use App\Models\Task;
use App\Models\User;
use App\Services\ProjectService;
use App\Services\RecurringTaskEngine;
use App\Services\TaskService;
use App\Support\GenerationOutcome;
use App\Support\ProjectStatus;
use App\Support\RecurrenceRule;
use App\Support\TaskStatus;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| RecurringTaskEngine
|--------------------------------------------------------------------------
|
| The engine's whole job is one sentence: October's retainer task is simply
| there on the 1st, once, on the right person's plate. These tests are that
| sentence taken apart.
|
| Three rules run through all of them:
|
|   - a generated task is created through TaskService::create(), so it has an
|     actor, a gate check, an audit row, a conversation and a notification,
|     exactly like one made on the board;
|   - the duplicate is prevented by tasks_recurring_task_period_unique, not by
|     the engine's check — the check is the nice error;
|   - every attempt writes one row to recurring_generation_log, including the
|     refusals, because a month that produced nothing and said nothing is
|     indistinguishable from a broken scheduler.
|
*/

beforeEach(function () {
    $this->seed();

    $this->engine = app(RecurringTaskEngine::class);
    $this->tasks = app(TaskService::class);

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->tapuEmployee = Employee::where('employee_number', 'GT-003')->firstOrFail();

    $this->project = Project::where('name', 'Buffalo Modular — SEO')->firstOrFail();

    $this->template = RecurringTask::factory()
        ->for($this->project)
        ->assignedTo($this->tapuEmployee)
        ->withChecklist(['Rank review', 'Technical audit', 'Monthly report'])
        ->create([
            'title_template' => 'Buffalo Modular SEO — {period}',
            'created_by' => $this->admin->id,
        ]);
});

/*
|--------------------------------------------------------------------------
| Generating a period
|--------------------------------------------------------------------------
*/

it('generates one instance for the period, in TO DO, with its checklist and its assignee', function () {
    $log = $this->engine->generate($this->template, Carbon::parse('2026-10-01'));

    expect($log->outcome)->toBe(GenerationOutcome::Generated);

    $task = Task::findOrFail($log->task_id);

    expect($task->title)->toBe('Buffalo Modular SEO — October 2026')
        // §6: "New task lands in assignee's TO DO with due date per rule". TO DO is a birth
        // status, so no transition is involved and the status guard is never touched.
        ->and($task->status)->toBe(TaskStatus::Todo)
        ->and($task->project_id)->toBe($this->project->id)
        ->and($task->recurring_task_id)->toBe($this->template->id)
        ->and($task->recurring_period)->toBe('2026-10')
        ->and($task->start_date->toDateString())->toBe('2026-10-01')
        ->and($task->due_date->toDateString())->toBe('2026-10-31')
        ->and($task->checklistItems()->pluck('title')->all())
        ->toBe(['Rank review', 'Technical audit', 'Monthly report'])
        ->and($task->assignees()->pluck('employees.id')->all())->toBe([$this->tapuEmployee->id])
        ->and($task->primary()?->id)->toBe($this->tapuEmployee->id);
})->group('phase3');

it('creates the instance through TaskService, so it arrives with everything a hand-made task has', function () {
    $log = $this->engine->generate($this->template, Carbon::parse('2026-10-01'));
    $task = Task::findOrFail($log->task_id);

    // The actor is the template's author, and therefore the generated task's created_by — the
    // "original assigner" who hears about its completion.
    expect((int) $task->created_by)->toBe($this->admin->id)
        // Its discussion, created inside TaskService::create()'s transaction like every other
        // task's (Phase 2 slice 4).
        ->and(DB::table('conversations')->where('linked_task_id', $task->id)->count())->toBe(1)
        // The assignment audit row.
        ->and(DB::table('audit_logs')
            ->where('target_type', $task->getMorphClass())
            ->where('target_id', $task->id)
            ->where('event', 'task.assigned')
            ->count())->toBe(1)
        // And the notification §19 asks for, through the Phase 2 engine and no other path.
        ->and(DB::table('notifications')
            ->where('user_id', $this->tapu->id)
            ->where('type', 'task.assigned')
            ->count())->toBe(1);
})->group('phase3');

it('names the period in the task title so two months are tellable apart', function () {
    $october = $this->engine->generate($this->template, Carbon::parse('2026-10-01'));
    $november = $this->engine->generate($this->template, Carbon::parse('2026-11-01'));

    expect(Task::findOrFail($october->task_id)->title)->toBe('Buffalo Modular SEO — October 2026')
        ->and(Task::findOrFail($november->task_id)->title)->toBe('Buffalo Modular SEO — November 2026')
        // Task detail's "period <Month YYYY>", read back from the stored key alone.
        ->and(Task::findOrFail($november->task_id)->recurringPeriodLabel())->toBe('November 2026');
})->group('phase3');

/*
|--------------------------------------------------------------------------
| Duplicate prevention: the index is the guarantee
|--------------------------------------------------------------------------
*/

it('refuses a second task for the same template and period at the DATABASE, not in code', function () {
    // No service, no engine, no check: two INSERTs straight at the table. This is the guarantee
    // the whole engine rests on, and it holds whatever the application believed a moment ago.
    $row = [
        'project_id' => $this->project->id,
        'title' => 'Hand-written October',
        'status' => TaskStatus::Todo->value,
        'priority' => 'medium',
        'position' => 0,
        'recurring_task_id' => $this->template->id,
        'recurring_period' => '2026-10',
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('tasks')->insert($row);

    // Inside a transaction of its own: a statement Postgres refuses aborts the transaction it is
    // in, and this test has more to say afterwards. The savepoint is the test's scaffolding, not
    // part of the claim.
    expect(fn () => DB::transaction(fn () => DB::table('tasks')->insert($row)))
        ->toThrow(QueryException::class);

    // ...and the index is PARTIAL, so it costs the ordinary hand-made task nothing: two tasks
    // with no recurring origin at all are fine.
    $plain = ['recurring_task_id' => null, 'recurring_period' => null] + $row;

    DB::table('tasks')->insert(['title' => 'Plain one'] + $plain);
    DB::table('tasks')->insert(['title' => 'Plain two'] + $plain);

    expect(Task::where('title', 'like', 'Plain %')->count())->toBe(2);
})->group('phase3');

it('turns the ordinary second run into a readable log row rather than an exception', function () {
    $first = $this->engine->generate($this->template, Carbon::parse('2026-10-01'));
    $second = $this->engine->generate($this->template, Carbon::parse('2026-10-01'));

    expect($first->outcome)->toBe(GenerationOutcome::Generated)
        ->and($second->outcome)->toBe(GenerationOutcome::SkippedDuplicate)
        // It points at the instance that already exists, so the log is a way back to the task.
        ->and($second->task_id)->toBe($first->task_id)
        ->and($second->message)->toContain('October 2026')
        ->and($second->isWarning())->toBeTrue()
        ->and(Task::where('recurring_task_id', $this->template->id)->count())->toBe(1);
})->group('phase3');

it('is stopped by the index when another run wins the race after the check has passed', function () {
    // The scenario the constraint exists for: two schedulers, a retried queue job and a manual
    // "Generate now" in the same second. The engine's existing-instance check passes for all of
    // them, because at the moment each one asks, the answer really is "no instance".
    //
    // This listener IS the other run. It fires inside TaskService::create(), i.e. strictly AFTER
    // the engine's check and strictly BEFORE its INSERT, and writes the competing row by hand —
    // no model events, no service, exactly like a row that landed from another process.
    $raced = false;

    Task::creating(function (Task $task) use (&$raced): void {
        if ($raced || $task->recurring_task_id === null) {
            return;
        }

        $raced = true;

        DB::table('tasks')->insert([
            'project_id' => $task->project_id,
            'title' => 'The other run got here first',
            'status' => TaskStatus::Todo->value,
            'priority' => 'medium',
            'position' => 0,
            'recurring_task_id' => $task->recurring_task_id,
            'recurring_period' => $task->recurring_period,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $log = $this->engine->generate($this->template, Carbon::parse('2026-10-01'));

    expect($raced)->toBeTrue('the race must actually have been staged');

    // The check said yes and the database said no. The message is only reachable from the catch
    // block, which is how this test tells the two guards apart: if the engine's `if` had been
    // what stopped it, the message would be the "already exists" one from the branch above.
    expect($log->outcome)->toBe(GenerationOutcome::SkippedDuplicate)
        ->and($log->message)->toContain('unique index refused this one')
        // And nothing of this run survived: TaskService::create() is one transaction, so the
        // refused task took its assignees, its checklist and its conversation with it.
        ->and(Task::where('title', 'Buffalo Modular SEO — October 2026')->exists())->toBeFalse();
})->group('phase3');

it('sets a task\'s origin only at birth and never through an edit', function () {
    $log = $this->engine->generate($this->template, Carbon::parse('2026-10-01'));
    $task = Task::findOrFail($log->task_id);

    $other = RecurringTask::factory()->for($this->project)->create();

    // The edit path does not look at BIRTH_FIELDS at all, so a request that smuggled these keys
    // past its Form Request still cannot move a task onto another template or restamp its
    // period — which would be a way to make the unique index protect the wrong pair.
    $this->tasks->update($this->admin, $task, [
        'title' => 'Renamed',
        'recurring_task_id' => $other->id,
        'recurring_period' => '2027-01',
    ]);

    expect($task->fresh()->title)->toBe('Renamed')
        ->and($task->fresh()->recurring_task_id)->toBe($this->template->id)
        ->and($task->fresh()->recurring_period)->toBe('2026-10');
})->group('phase3');

/*
|--------------------------------------------------------------------------
| The previous-open flag: a warning, never a refusal
|--------------------------------------------------------------------------
*/

it('flags last period\'s still-open task and generates this period anyway', function () {
    $october = $this->engine->generate($this->template, Carbon::parse('2026-10-01'));
    $november = $this->engine->generate($this->template, Carbon::parse('2026-11-01'));

    expect($november->outcome)->toBe(GenerationOutcome::Generated)
        ->and($november->task_id)->not->toBeNull()
        // The flag. §6: "Previous period's still-open task is flagged, not silently duplicated."
        ->and($november->previous_open_task_id)->toBe($october->task_id)
        ->and($november->message)->toContain('October 2026')
        ->and($november->isWarning())->toBeTrue()
        ->and(Task::where('recurring_task_id', $this->template->id)->count())->toBe(2);
})->group('phase3');

it('does not flag a previous period that was finished', function () {
    $october = $this->engine->generate($this->template, Carbon::parse('2026-10-01'));
    $task = Task::findOrFail($october->task_id);

    // Through the machine, like every status move in this application.
    $this->tasks->transition($this->tapu, $task, TaskStatus::InProgress);
    $this->tasks->transition($this->tapu, $task, TaskStatus::InReview, 'Everything on the list is done.');
    $this->tasks->transition($this->admin, $task->refresh(), TaskStatus::Completed);

    $november = $this->engine->generate($this->template, Carbon::parse('2026-11-01'));

    expect($november->outcome)->toBe(GenerationOutcome::Generated)
        ->and($november->previous_open_task_id)->toBeNull()
        ->and($november->message)->toBeNull()
        ->and($november->isWarning())->toBeFalse();
})->group('phase3');

/*
|--------------------------------------------------------------------------
| Stop means stop — and says why
|--------------------------------------------------------------------------
*/

it('stops generating the moment the project is cancelled, and writes down why', function () {
    $this->engine->generate($this->template, Carbon::parse('2026-10-01'));

    // Through ProjectService, the only thing that moves a project's status.
    app(ProjectService::class)->changeStatus($this->admin, $this->project, ProjectStatus::Cancelled);

    $log = $this->engine->generate($this->template->fresh(), Carbon::parse('2026-11-01'));

    expect($log->outcome)->toBe(GenerationOutcome::SkippedProjectClosed)
        ->and($log->task_id)->toBeNull()
        ->and($log->message)->toContain('Cancelled')
        // October's instance is untouched: stopping generation is not closing work.
        ->and(Task::where('recurring_task_id', $this->template->id)->count())->toBe(1);
})->group('phase3');

it('stops for an archived project and for a completed one, and keeps going for one on hold', function (
    string $status,
    bool $stops,
) {
    $this->project->forceFill([
        'status' => $status,
        'archived_at' => $status === ProjectStatus::Archived->value ? now() : null,
    ])->save();

    $log = $this->engine->generate($this->template->fresh(), Carbon::parse('2026-10-01'));

    expect($log->outcome)->toBe($stops
        ? GenerationOutcome::SkippedProjectClosed
        : GenerationOutcome::Generated);
})->with([
    // "Ended" is defined as "the status is no longer open" — see RecurringTaskEngine::stopReason().
    'archived' => [ProjectStatus::Archived->value, true],
    'completed' => [ProjectStatus::Completed->value, true],
    'cancelled' => [ProjectStatus::Cancelled->value, true],
    // On Hold is the one status that means "paused, and coming back". A retainer that silently
    // stopped producing work while a client thought about a budget would be found a month late.
    'on hold' => [ProjectStatus::OnHold->value, false],
    'active' => [ProjectStatus::Active->value, false],
])->group('phase3');

it('does not treat a deadline that has passed as the end of the retainer', function () {
    // Every retainer in the seed data carries a rolling deadline a few days out. If a slipped
    // date ended generation, every retainer in the agency would stop within a week, silently —
    // which is the exact failure this module exists to prevent.
    $this->project->forceFill(['deadline' => Carbon::parse('2026-09-01')])->save();

    $log = $this->engine->generate($this->template->fresh(), Carbon::parse('2026-10-01'));

    expect($log->outcome)->toBe(GenerationOutcome::Generated);
})->group('phase3');

it('skips an inactive template when somebody asks it to generate now', function () {
    $this->template->forceFill(['active' => false])->save();

    $log = $this->engine->generate($this->template->fresh(), Carbon::parse('2026-10-01'), force: true);

    expect($log->outcome)->toBe(GenerationOutcome::SkippedInactive)
        ->and(Task::where('recurring_task_id', $this->template->id)->count())->toBe(0);
})->group('phase3');

/*
|--------------------------------------------------------------------------
| Weekly and custom rules
|--------------------------------------------------------------------------
*/

it('generates a weekly rule once per ISO week, keyed 2026-W41', function () {
    $this->template->setRule(RecurrenceRule::weekly(Carbon::MONDAY))->save();

    $first = $this->engine->generate($this->template->fresh(), Carbon::parse('2026-10-05'));
    // Still the same ISO week: Sunday the 11th.
    $again = $this->engine->generate($this->template->fresh(), Carbon::parse('2026-10-11'));
    // The next one.
    $next = $this->engine->generate($this->template->fresh(), Carbon::parse('2026-10-12'));

    expect($first->outcome)->toBe(GenerationOutcome::Generated)
        ->and($first->period)->toBe('2026-W41')
        ->and($again->outcome)->toBe(GenerationOutcome::SkippedDuplicate)
        ->and($next->outcome)->toBe(GenerationOutcome::Generated)
        ->and($next->period)->toBe('2026-W42')
        ->and($next->previous_open_task_id)->toBe($first->task_id);

    $task = Task::findOrFail($first->task_id);

    expect($task->title)->toBe('Buffalo Modular SEO — Week 41, 2026')
        ->and($task->start_date->toDateString())->toBe('2026-10-05')
        ->and($task->due_date->toDateString())->toBe('2026-10-11');
})->group('phase3');

it('generates a custom every-N-days rule once per cycle, keyed by the cycle\'s first day', function () {
    $this->template->setRule(RecurrenceRule::custom(14, '2026-10-07'))->save();

    $first = $this->engine->generate($this->template->fresh(), Carbon::parse('2026-10-07'));
    $midCycle = $this->engine->generate($this->template->fresh(), Carbon::parse('2026-10-20'));
    $next = $this->engine->generate($this->template->fresh(), Carbon::parse('2026-10-21'));

    expect($first->period)->toBe('2026-10-07')
        ->and($first->outcome)->toBe(GenerationOutcome::Generated)
        ->and($midCycle->outcome)->toBe(GenerationOutcome::SkippedDuplicate)
        ->and($next->period)->toBe('2026-10-21')
        ->and($next->outcome)->toBe(GenerationOutcome::Generated);

    $task = Task::findOrFail($first->task_id);

    expect($task->title)->toBe('Buffalo Modular SEO — From 7 Oct 2026')
        ->and($task->due_date->toDateString())->toBe('2026-10-20');
})->group('phase3');

/*
|--------------------------------------------------------------------------
| Which templates a run picks up
|--------------------------------------------------------------------------
*/

it('picks a template up on its generation day and leaves it alone the rest of the period', function () {
    $this->template->setRule(RecurrenceRule::monthly(15))->save();

    expect($this->engine->due(Carbon::parse('2026-10-14'))->pluck('id')->all())->not->toContain($this->template->id)
        ->and($this->engine->due(Carbon::parse('2026-10-15'))->pluck('id')->all())->toContain($this->template->id);

    $this->engine->generate($this->template->fresh(), Carbon::parse('2026-10-15'));

    // The day after: attempted already, nothing to say, no log row a person has to read past.
    expect($this->engine->due(Carbon::parse('2026-10-16'))->pluck('id')->all())->not->toContain($this->template->id);
})->group('phase3');

it('catches up when the scheduler did not run on the day', function () {
    // The server was down on the 1st. The 2nd still generates October, because the engine works
    // from the period the run date falls in and not from a next_run_at it had to hit exactly.
    $due = $this->engine->due(Carbon::parse('2026-10-02'));

    expect($due->pluck('id')->all())->toContain($this->template->id);

    $log = $this->engine->generate($this->template->fresh(), Carbon::parse('2026-10-02'));

    expect($log->outcome)->toBe(GenerationOutcome::Generated)
        ->and($log->period)->toBe('2026-10');
})->group('phase3');

it('leaves an inactive template out of the daily sweep entirely', function () {
    $this->template->forceFill(['active' => false])->save();

    expect($this->engine->due(Carbon::parse('2026-10-01'))->pluck('id')->all())
        ->not->toContain($this->template->id)
        // ...and does not write a row a day saying so.
        ->and(RecurringGenerationLog::where('recurring_task_id', $this->template->id)->count())->toBe(0);
})->group('phase3');

it('keeps a cancelled project\'s template in the sweep so the stop is logged once, not never', function () {
    app(ProjectService::class)->changeStatus($this->admin, $this->project, ProjectStatus::Cancelled);

    expect($this->engine->due(Carbon::parse('2026-10-01'))->pluck('id')->all())->toContain($this->template->id);

    $this->engine->generate($this->template->fresh(), Carbon::parse('2026-10-01'));

    // Logged once. The next morning the period has been attempted, so it is silent again.
    expect($this->engine->due(Carbon::parse('2026-10-02'))->pluck('id')->all())
        ->not->toContain($this->template->id);
})->group('phase3');

it('refreshes the next-run preview after every attempt', function () {
    $this->engine->generate($this->template, Carbon::parse('2026-10-01'));

    expect($this->template->fresh()->next_run_at->toDateString())->toBe('2026-11-01');
})->group('phase3');
