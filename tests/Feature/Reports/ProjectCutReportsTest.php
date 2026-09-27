<?php

use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\RecurringTask;
use App\Models\User;
use App\Services\RecurringTaskEngine;
use App\Services\ReportService;
use App\Support\ReportColumn;
use App\Support\ReportFilter;
use App\Support\ReportFilters;
use App\Support\ReportKey;
use App\Support\ReportResult;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| The five project cuts — Completion, Maintenance, SEO, Website Project,
| Performance — against the seeded data
|--------------------------------------------------------------------------
|
| Part E Phase 10: "each report returns correct numbers against seeded
| data". Every figure below was added up from DemoSeeder's seven projects
| and TaskSeeder's twenty-five tasks, and the day a seeder changes this file
| is supposed to fail and be read rather than adjusted.
|
| Two figures are deliberately NOT literals: the tracked minutes. WorkSeeder
| lays them down relative to today() over real working days, so the total
| moves with the calendar. They are asserted as an IDENTITY with the Time
| report instead — which is the stronger statement anyway, because the thing
| that must be true is that two reports do not disagree about one number.
|
| Everything is prefixed SLICEB_ / sliceb*, because Pest declares constants
| and functions globally across the whole suite (AGENTS.md).
|
*/

/** A window wide enough to hold every dated seeded task — start -40, due +30 are the extremes. */
function slicebWindow(): array
{
    return [
        'from' => Carbon::today()->subDays(45)->toDateString(),
        'to' => Carbon::today()->addDays(35)->toDateString(),
    ];
}

function slicebBuild(ReportKey $key, User $viewer, array $input = []): ReportResult
{
    return app(ReportService::class)->build($key, $viewer, ReportFilters::for($key, $input, Carbon::today()));
}

/** One row of a result, found by the value of a named cell. */
function slicebRow(ReportResult $result, string $key, string $value): array
{
    foreach ($result->rows as $row) {
        if ((string) $row[$key] === $value) {
            return $row;
        }
    }

    throw new RuntimeException("No row where {$key} = {$value}.");
}

/** @return list<string> */
function slicebColumnKeys(ReportResult $result): array
{
    return array_map(fn (ReportColumn $column): string => $column->key, $result->columns);
}

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
});

/*
|--------------------------------------------------------------------------
| Completion
|--------------------------------------------------------------------------
*/

it('counts what was finished per project and how long it took, against the seed', function () {
    $result = slicebBuild(ReportKey::Completion, $this->admin, slicebWindow());
    $task = slicebBuild(ReportKey::Task, $this->admin, slicebWindow());

    // Every seeded project has work in this window, and the totals have to agree with the Task
    // report's — both are TaskService's own counts over the same window and scope.
    expect($result->rows)->toHaveCount(7)
        ->and($result->totals['tasks'])->toBe($task->totals['tasks'])
        ->and($result->totals['tasks'])->toBe(24)
        ->and($result->totals['completed'])->toBe(3)
        ->and($result->totals['open'])->toBe(20);

    // Three tasks are seeded completed and all three carry a start date, so all three are
    // measurable: 9, 4 and 12 days from start to completion.
    expect($result->totals['measured'])->toBe(3)
        ->and($result->totals['average_days'])->toBe(8)
        ->and($result->totals['longest_days'])->toBe(12);

    $heatGap = slicebRow($result, 'project', 'Heat Gap — SEO Retainer');
    expect($heatGap['tasks'])->toBe(4)
        ->and($heatGap['completed'])->toBe(1)
        ->and($heatGap['measured'])->toBe(1)
        ->and($heatGap['average_days'])->toBe(12)
        ->and($heatGap['longest_days'])->toBe(12);

    // A project that finished nothing is a row with nothing measured — NOT an average of zero
    // days dressed up as a fact.
    $seo = slicebRow($result, 'project', 'Buffalo Modular — SEO');
    expect($seo['completed'])->toBe(0)
        ->and($seo['measured'])->toBe(0)
        ->and($seo['average_days'])->toBe(0);
})->group('phase10', 'reports');

it('leaves a project with no work in the window out of Completion entirely', function () {
    // A one-day window on a day nothing is dated: the report is empty rather than seven rows
    // of noughts with a nought-day average beside each one.
    $quiet = Carbon::today()->addDays(200)->toDateString();

    $result = slicebBuild(ReportKey::Completion, $this->admin, ['from' => $quiet, 'to' => $quiet]);

    expect($result->rows)->toBe([])
        ->and($result->empty)->toBe('No work was started or finished in this window.');
})->group('phase10', 'reports');

/*
|--------------------------------------------------------------------------
| Maintenance and SEO — the retainer cuts, and what a "period" row is
|--------------------------------------------------------------------------
*/

it('cuts the maintenance retainers by project, and groups work no template generated as Ad hoc', function () {
    $result = slicebBuild(ReportKey::Maintenance, $this->admin, slicebWindow());

    // DemoSeeder has three website_maintenance projects. RecurringTaskSeeder seeds TEMPLATES
    // and generates nothing (its own docblock says so), so every seeded maintenance task was
    // made by hand and carries no period — which is Ad hoc, not an invented month.
    expect(array_column($result->rows, 'project'))->toBe([
        'APH — Website Maintenance',
        'Buffalo Modular — Website Maintenance',
        'abc.com — Monthly Maintenance',
    ]);

    expect(array_unique(array_column($result->rows, 'period')))->toBe(['Ad hoc']);

    expect($result->totals['tasks'])->toBe(9)
        ->and($result->totals['completed'])->toBe(1)
        ->and($result->totals['overdue'])->toBe(2);

    // Neither SEO retainer is in it, and nor is the build.
    expect(json_encode($result))->not->toContain('Buffalo Modular — SEO')
        ->and(json_encode($result))->not->toContain('Website Development');
})->group('phase10', 'reports');

it('gives a generated retainer instance its own period row, named the way the engine names it', function () {
    // The proof of what a period IS: run the engine, and the row appears under the period key
    // it stamped on the task — `RecurrenceRule::labelForPeriod()`, the same words task detail
    // prints — rather than under a month this report worked out for itself.
    $template = RecurringTask::query()
        ->whereHas('project', fn ($project) => $project->where('name', 'abc.com — Monthly Maintenance'))
        ->firstOrFail();

    app(RecurringTaskEngine::class)->generate($template, Carbon::today(), force: true);

    $result = slicebBuild(ReportKey::Maintenance, $this->admin, slicebWindow());

    $rows = array_values(array_filter(
        $result->rows,
        static fn (array $row): bool => $row['project'] === 'abc.com — Monthly Maintenance',
    ));

    // Two rows for that retainer now: the generated period, and the ad-hoc work beside it.
    expect(array_column($rows, 'period'))->toBe([
        Carbon::today()->startOfMonth()->format('F Y'),
        'Ad hoc',
    ]);

    expect($rows[0]['tasks'])->toBe(1)
        ->and($rows[0]['completed'])->toBe(0);

    expect($result->totals['tasks'])->toBe(10);
})->group('phase10', 'reports');

it('cuts the SEO retainers the same way, and agrees with the Time report about the hours', function () {
    $result = slicebBuild(ReportKey::Seo, $this->admin, slicebWindow());
    $time = slicebBuild(ReportKey::Time, $this->admin, slicebWindow());

    expect(array_column($result->rows, 'project'))
        ->toBe(['Buffalo Modular — SEO', 'Heat Gap — SEO Retainer']);

    expect($result->totals['tasks'])->toBe(9)
        ->and($result->totals['completed'])->toBe(1)
        ->and($result->totals['overdue'])->toBe(4);

    // WorkSeeder's tracked minutes move with the calendar, so the assertion is the identity
    // rather than a literal: the SEO report's hours are the Time report's hours for the same
    // two projects, because both are `TimeEntry::scopeCounted()` over the same window.
    $fromTime = 0;

    foreach ($time->rows as $row) {
        if (in_array($row['project'], ['Buffalo Modular — SEO', 'Heat Gap — SEO Retainer'], true)) {
            $fromTime += (int) $row['tracked'];
        }
    }

    expect($result->totals['tracked'])->toBe($fromTime)
        ->and($result->totals['tracked'])->toBeGreaterThan(0);
})->group('phase10', 'reports');

/*
|--------------------------------------------------------------------------
| Website Project
|--------------------------------------------------------------------------
*/

it('stands each website build up with its client, status and deadline', function () {
    $result = slicebBuild(ReportKey::WebsiteProject, $this->admin, slicebWindow());

    // One build in the seed. The three maintenance retainers, the two SEO retainers and the
    // internal project are not builds and are absent.
    expect($result->rows)->toHaveCount(1);

    $build = $result->rows[0];
    $project = Project::where('name', 'Buffalo Modular — Website Development')->firstOrFail();

    expect($build['project'])->toBe('Buffalo Modular — Website Development')
        ->and($build['client'])->toBe('Buffalo Modular Homes')
        ->and($build['domain'])->toBe('buffalomodular.com')
        ->and($build['status'])->toBe('Active')
        ->and($build['deadline'])->toBe($project->deadline?->toDateString())
        ->and($build['tasks'])->toBe(5)
        ->and($build['completed'])->toBe(1)
        ->and($build['overdue'])->toBe(1);

    expect(json_encode($result))->not->toContain('Monthly Maintenance');
})->group('phase10', 'reports');

/*
|--------------------------------------------------------------------------
| Performance — PROJECT performance, and nothing that is a person
|--------------------------------------------------------------------------
*/

it('compares each project with its estimate, and rates the window\'s own work', function () {
    $result = slicebBuild(ReportKey::Performance, $this->admin, slicebWindow());

    expect($result->rows)->toHaveCount(7);

    $heatGap = slicebRow($result, 'project', 'Heat Gap — SEO Retainer');
    expect($heatGap['tasks'])->toBe(4)
        ->and($heatGap['completed'])->toBe(1)
        ->and($heatGap['completed_share'])->toBe(25)
        ->and($heatGap['overdue'])->toBe(2)
        ->and($heatGap['overdue_share'])->toBe(50)
        // TaskSeeder's estimates on those four tasks add up to ten hours.
        ->and($heatGap['estimated'])->toBe(600)
        ->and($heatGap['unestimated'])->toBe(0);

    // The shares are of the window's own tasks: 3 of 24 completed, 7 of 24 overdue — the same
    // two numbers the Task and Overdue reports state.
    expect($result->totals['tasks'])->toBe(24)
        ->and($result->totals['completed_share'])->toBe(13)
        ->and($result->totals['overdue_share'])->toBe(29)
        ->and($result->totals['estimated'])->toBe(4140)
        ->and($result->totals['unestimated'])->toBe(1);
})->group('phase10', 'reports');

it('has no per-person row, no per-person column and no employee filter in Performance', function () {
    // Part D §2 and Part H §1: a per-employee performance table is the one thing this phase
    // must not produce, whatever the column is called. Asserted three ways, because each of
    // them alone could be satisfied by an implementation that failed the other two.
    $result = slicebBuild(ReportKey::Performance, $this->admin, slicebWindow());

    expect(ReportKey::Performance->filters())->not->toContain(ReportFilter::Employee);
    expect(ReportKey::Performance->accepts(ReportFilter::Employee))->toBeFalse();

    foreach (slicebColumnKeys($result) as $key) {
        expect($key)->not->toContain('employee')
            ->and($key)->not->toContain('person')
            ->and($key)->not->toContain('assignee');
    }

    // Every row is a project the viewer can open, and no cell anywhere carries a person's name.
    $names = Project::query()->pluck('name')->all();
    $body = json_encode($result, JSON_THROW_ON_ERROR);

    foreach ($result->rows as $row) {
        expect($names)->toContain($row['project']);
    }

    foreach (['Shahadat Hossain', 'Faruk Ahmed', 'Tapu', 'Yaseen', 'Accountant'] as $person) {
        expect($body)->not->toContain($person);
    }

    // And a hand-typed employee id changes nothing, because `ReportFilters` dropped it.
    $forced = slicebBuild(ReportKey::Performance, $this->admin, slicebWindow() + ['employee' => 3]);
    expect($forced->rows)->toBe($result->rows);
})->group('phase10', 'reports');

/*
|--------------------------------------------------------------------------
| Scoping — a project outside the scope leaves no trace
|--------------------------------------------------------------------------
*/

it('gives Tapu only his own two SEO projects across the five project cuts', function () {
    $hidden = [
        'APH — Website Maintenance',
        'abc.com — Monthly Maintenance',
        'GoodTechies HQ — Internal',
        'Buffalo Modular — Website Development',
        'Buffalo Modular — Website Maintenance',
    ];

    foreach ([ReportKey::Completion, ReportKey::Maintenance, ReportKey::Seo, ReportKey::WebsiteProject, ReportKey::Performance] as $key) {
        $result = slicebBuild($key, $this->tapu, slicebWindow());
        $body = json_encode($result, JSON_THROW_ON_ERROR);

        foreach ($hidden as $name) {
            expect($body)->not->toContain($name);
        }

        foreach (Project::whereIn('name', $hidden)->pluck('id') as $id) {
            expect($body)->not->toContain('/admin/projects/'.$id.'"');
        }
    }

    // He is on neither maintenance retainer and on no build, so those two reports are empty
    // for him — and empty is an empty state, never a refusal.
    expect(slicebBuild(ReportKey::Maintenance, $this->tapu, slicebWindow())->rows)->toBe([]);
    expect(slicebBuild(ReportKey::WebsiteProject, $this->tapu, slicebWindow())->rows)->toBe([]);

    // The two SEO retainers are his, and the SEO report says so.
    expect(array_column(slicebBuild(ReportKey::Seo, $this->tapu, slicebWindow())->rows, 'project'))
        ->toBe(['Buffalo Modular — SEO', 'Heat Gap — SEO Retainer']);
})->group('phase10', 'reports');

it('drops the client column from Website Project for a viewer without clients.view_full', function () {
    // Part C §2, and the same rule the Project report keeps: the field is ABSENT, and the
    // domain stands in its place. Asserted on a build Tapu CAN see, so the test is about the
    // column and not about the row being missing.
    $project = Project::where('name', 'Buffalo Modular — Website Development')->firstOrFail();

    ProjectMember::create([
        'project_id' => $project->getKey(),
        'employee_id' => $this->tapu->employee->getKey(),
    ]);

    $result = slicebBuild(ReportKey::WebsiteProject, $this->tapu, slicebWindow());

    expect($result->rows)->toHaveCount(1);
    expect(slicebColumnKeys($result))->not->toContain('client');
    expect(slicebColumnKeys($result))->toContain('domain');
    expect($result->rows[0])->not->toHaveKey('client');
    expect(json_encode($result))->not->toContain('Buffalo Modular Homes');

    // The Admin, who may, still gets it.
    expect(slicebColumnKeys(slicebBuild(ReportKey::WebsiteProject, $this->admin, slicebWindow())))
        ->toContain('client');
})->group('phase10', 'reports');

it('returns an empty report, not an error, for a project or client id the viewer may not see', function () {
    // 10-26: a filter id is data, never an authorization decision. A 404 would confirm the row
    // exists (Part C §1).
    $aph = Project::where('name', 'APH — Website Maintenance')->firstOrFail();

    foreach ([ReportKey::Completion, ReportKey::Maintenance, ReportKey::Performance] as $key) {
        $result = slicebBuild($key, $this->tapu, slicebWindow() + ['project' => $aph->getKey()]);

        expect($result->rows)->toBe([]);
        expect(json_encode($result))->not->toContain('APH');
    }
})->group('phase10', 'reports');
