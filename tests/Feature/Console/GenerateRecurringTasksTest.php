<?php

use App\Jobs\GenerateRecurringTask;
use App\Models\Employee;
use App\Models\Notification;
use App\Models\Project;
use App\Models\RecurringGenerationLog;
use App\Models\RecurringTask;
use App\Models\Task;
use App\Models\User;
use App\Services\ProjectService;
use App\Services\RecurringTaskEngine;
use App\Support\GenerationOutcome;
use App\Support\ProjectStatus;
use App\Support\TaskStatus;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| hq:generate-recurring-tasks
|--------------------------------------------------------------------------
|
| The acceptance criterion for this whole phase is one sentence (Part F §3.7):
| "a recurring monthly project auto-generates its next batch with zero manual
| recreation for two consecutive cycles". The time-travel test below is that
| sentence, run against the real command with Carbon's test-now moved.
|
| The queue connection is `sync` under phpunit.xml, so dispatching a job runs
| it. That is deliberate: these tests exercise the command, the job and the
| engine as one thing, which is how they run at 00:05.
|
*/

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();

    $this->project = Project::where('name', 'Buffalo Modular — SEO')->firstOrFail();

    $this->template = RecurringTask::factory()
        ->for($this->project)
        ->assignedTo(Employee::where('employee_number', 'GT-003')->firstOrFail())
        ->withChecklist(['Rank review', 'Monthly report'])
        ->create([
            'title_template' => 'Buffalo Modular SEO — {period}',
            'created_by' => $this->admin->id,
        ]);

    // The seeder ships the agency's three real retainers, which is what the demo needs and what
    // one test below is about. Every other test here is about ONE template's behaviour over
    // time, so they are switched off rather than filtered out of each assertion — a count that
    // has to subtract the seed data is a count that will be wrong the day the seed data changes.
    RecurringTask::query()->whereKeyNot($this->template->getKey())->update(['active' => false]);
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * The instances of one template, oldest period first.
 *
 * @return list<string>
 */
function generatedPeriods(RecurringTask $template): array
{
    return Task::where('recurring_task_id', $template->getKey())
        ->orderBy('recurring_period')
        ->pluck('recurring_period')
        ->all();
}

/*
|--------------------------------------------------------------------------
| Two consecutive cycles
|--------------------------------------------------------------------------
*/

it('generates exactly one instance per month across two consecutive months, travelling in time', function () {
    // Time travel is the TEST, not the implementation: there is no branch anywhere in the engine
    // that asks whether it is running under test. The command reads Carbon::today(), the clock is
    // moved, and every day of two months is run through it exactly as cron would.
    $day = Carbon::parse('2026-10-01');
    $last = Carbon::parse('2026-11-30');

    while ($day->lte($last)) {
        Carbon::setTestNow($day->copy()->setTime(0, 5));

        $this->artisan('hq:generate-recurring-tasks')->assertSuccessful();

        $day->addDay();
    }

    // Sixty-one runs. Two tasks.
    expect(generatedPeriods($this->template))->toBe(['2026-10', '2026-11'])
        ->and(Task::where('recurring_task_id', $this->template->id)->count())->toBe(2);

    $october = Task::where('recurring_task_id', $this->template->id)
        ->where('recurring_period', '2026-10')->firstOrFail();
    $november = Task::where('recurring_task_id', $this->template->id)
        ->where('recurring_period', '2026-11')->firstOrFail();

    expect($october->title)->toBe('Buffalo Modular SEO — October 2026')
        ->and($october->status)->toBe(TaskStatus::Todo)
        ->and($october->due_date->toDateString())->toBe('2026-10-31')
        ->and($november->title)->toBe('Buffalo Modular SEO — November 2026')
        ->and($november->due_date->toDateString())->toBe('2026-11-30');

    // Zero manual recreation, and the assignee was told about each one through the Phase 2
    // engine — one notification per task, not one per run.
    expect(Notification::query()
        ->where('user_id', $this->tapu->id)
        ->where('type', 'task.assigned')
        ->count())->toBe(2);

    // Two attempts, two `generated` rows, and the sixty other mornings said nothing because
    // there was nothing to say.
    expect(RecurringGenerationLog::where('recurring_task_id', $this->template->id)->count())->toBe(2);
})->group('phase3');

it('produces one task and one warning row when the scheduler runs twice in the same day', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-01 00:05'));

    $this->artisan('hq:generate-recurring-tasks')->assertSuccessful();

    // The 00:05 cron ran, then somebody re-ran it by hand at 00:06 because they were watching.
    Carbon::setTestNow(Carbon::parse('2026-10-01 00:06'));

    $this->artisan('hq:generate-recurring-tasks')->assertSuccessful();

    expect(Task::where('recurring_task_id', $this->template->id)->count())->toBe(1);

    $log = RecurringGenerationLog::where('recurring_task_id', $this->template->id)
        ->orderBy('id')
        ->get();

    expect($log)->toHaveCount(2)
        ->and($log[0]->outcome)->toBe(GenerationOutcome::Generated)
        ->and($log[1]->outcome)->toBe(GenerationOutcome::SkippedDuplicate)
        ->and($log[1]->task_id)->toBe($log[0]->task_id)
        ->and($log[1]->isWarning())->toBeTrue()
        // One assignment, so the assignee's bell says the task arrived once.
        ->and(Notification::query()
            ->where('user_id', $this->tapu->id)
            ->where('type', 'task.assigned')
            ->count())->toBe(1);
})->group('phase3');

it('flags a still-open October when it generates November', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-01 00:05'));
    $this->artisan('hq:generate-recurring-tasks')->assertSuccessful();

    Carbon::setTestNow(Carbon::parse('2026-11-01 00:05'));
    $this->artisan('hq:generate-recurring-tasks')->assertSuccessful();

    $november = RecurringGenerationLog::where('recurring_task_id', $this->template->id)
        ->where('period', '2026-11')
        ->firstOrFail();

    expect($november->outcome)->toBe(GenerationOutcome::Generated)
        ->and($november->previousOpenTask?->recurring_period)->toBe('2026-10')
        // A warning, never a refusal: November exists anyway.
        ->and(generatedPeriods($this->template))->toBe(['2026-10', '2026-11']);
})->group('phase3');

it('generates nothing for a cancelled project and says so in the log', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-01 00:05'));
    $this->artisan('hq:generate-recurring-tasks')->assertSuccessful();

    app(ProjectService::class)->changeStatus($this->admin, $this->project, ProjectStatus::Cancelled);

    Carbon::setTestNow(Carbon::parse('2026-11-01 00:05'));
    $this->artisan('hq:generate-recurring-tasks')->assertSuccessful();

    expect(generatedPeriods($this->template))->toBe(['2026-10']);

    $november = RecurringGenerationLog::where('recurring_task_id', $this->template->id)
        ->where('period', '2026-11')
        ->firstOrFail();

    expect($november->outcome)->toBe(GenerationOutcome::SkippedProjectClosed)
        ->and($november->message)->toContain('Cancelled');
})->group('phase3');

/*
|--------------------------------------------------------------------------
| The command itself
|--------------------------------------------------------------------------
*/

it('dispatches one queue job per due template and decides nothing itself', function () {
    Queue::fake();

    // A second template on another project, so "one job per template" means something.
    RecurringTask::factory()
        ->for(Project::where('name', 'Heat Gap — SEO Retainer')->firstOrFail())
        ->create(['created_by' => $this->admin->id]);

    $this->artisan('hq:generate-recurring-tasks --as-of=2026-10-01')->assertSuccessful();

    // The active pair: this file's template and the one just made. The seeded three are off.
    Queue::assertPushed(GenerateRecurringTask::class, 2);

    // Faked, so nothing ran: the command really does only dispatch.
    expect(Task::whereNotNull('recurring_task_id')->count())->toBe(0);
})->group('phase3');

it('carries the run date into the job so a replayed night generates the night it meant to', function () {
    // The operator replaying a night the scheduler was down.
    Carbon::setTestNow(Carbon::parse('2026-12-20 11:00'));

    $this->artisan('hq:generate-recurring-tasks --as-of=2026-10-01')->assertSuccessful();

    expect(generatedPeriods($this->template))->toBe(['2026-10']);
})->group('phase3');

it('falls back to today rather than throwing on an unreadable --as-of', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-01 00:05'));

    $this->artisan('hq:generate-recurring-tasks --as-of=the-first-of-never')
        ->expectsOutputToContain('Could not read')
        ->assertSuccessful();

    expect(generatedPeriods($this->template))->toBe(['2026-10']);
})->group('phase3');

it('says nothing is due on an ordinary morning in the middle of a period', function () {
    $this->artisan('hq:generate-recurring-tasks --as-of=2026-10-01')->assertSuccessful();

    // The 12th: the rule's day is long past, October has been dealt with, and there is nothing
    // to say. No task, and no log row for somebody to read past on the templates screen.
    $this->artisan('hq:generate-recurring-tasks --as-of=2026-10-12')
        ->expectsOutputToContain('No recurring template is due')
        ->assertSuccessful();

    expect(generatedPeriods($this->template))->toBe(['2026-10'])
        ->and(RecurringGenerationLog::where('recurring_task_id', $this->template->id)->count())->toBe(1);
})->group('phase3');

it('shrugs off a template deleted between the sweep and the worker', function () {
    $id = (int) $this->template->getKey();
    $this->template->delete();

    (new GenerateRecurringTask($id, '2026-10-01'))->handle(app(RecurringTaskEngine::class));
})->group('phase3')->throwsNoExceptions();

/*
|--------------------------------------------------------------------------
| The seeded retainers
|--------------------------------------------------------------------------
*/

it('generates the three seeded retainers on the first of the month, to the right people', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-01 00:05'));

    // Put the seeded three back and take this file's template out, so this is about the seed
    // data and nothing else.
    $this->template->forceDelete();
    RecurringTask::query()->update(['active' => true]);

    $this->artisan('hq:generate-recurring-tasks')->assertSuccessful();

    $titles = Task::whereNotNull('recurring_task_id')->orderBy('id')->pluck('title')->all();

    expect($titles)->toBe([
        'abc.com Monthly Maintenance — October 2026',
        'Heat Gap Monthly SEO Tasks — October 2026',
        'Buffalo Modular Monthly SEO — October 2026',
    ]);

    $maintenance = Task::where('recurring_period', '2026-10')
        ->where('title', 'like', 'abc.com%')
        ->firstOrFail();

    // The spec's eight-item checklist, on Yaseen's plate.
    expect($maintenance->checklistItems()->count())->toBe(8)
        ->and($maintenance->primary()?->employee_number)->toBe('GT-004');

    expect(Task::where('recurring_period', '2026-10')
        ->where('title', 'like', 'Heat Gap%')
        ->firstOrFail()
        ->primary()?->employee_number)->toBe('GT-003');
})->group('phase3');

/*
|--------------------------------------------------------------------------
| Registration
|--------------------------------------------------------------------------
*/

it('is scheduled daily at 00:05, without overlapping, in the app timezone', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $event) => str_contains($event->command ?? '', 'hq:generate-recurring-tasks'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('5 0 * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->timezone ?? config('app.timezone'))->toBe(config('app.timezone'))
        ->and(trim(Str::after($event->command, 'artisan'), " '\""))->toBe('hq:generate-recurring-tasks');
})->group('phase3');
