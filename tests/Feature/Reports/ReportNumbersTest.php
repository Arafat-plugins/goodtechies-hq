<?php

use App\Models\AttendanceRecord;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\ReportService;
use App\Support\ReportColumn;
use App\Support\ReportFilters;
use App\Support\ReportKey;
use App\Support\ReportResult;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Each report returns CORRECT NUMBERS against the seeded data
|--------------------------------------------------------------------------
|
| Part E Phase 10's Tests paragraph: "each report returns correct numbers
| against seeded data". Not "is not empty" — the figures below are the ones
| DemoSeeder, TaskSeeder, FinanceSeeder and PayrollSeeder actually put in
| the database, and the day a seed changes this file is supposed to fail
| and be read, not adjusted.
|
| Dates are relative to `Carbon::today()` because the seeders' are: a task
| is seeded due `today + 4`, so a fixed 2026-09-29 here would rot overnight.
| The WIDE window below is chosen to contain every dated seeded task, which
| makes the totals a statement about the seed rather than about the window.
|
| Everything is prefixed REPORTS_ / reports*, because Pest declares
| constants and functions globally (AGENTS.md).
|
*/

/** A window wide enough to hold every dated seeded task — start -40, due +30 are the extremes. */
function reportsWideWindow(): array
{
    return [
        'from' => Carbon::today()->subDays(45)->toDateString(),
        'to' => Carbon::today()->addDays(35)->toDateString(),
    ];
}

/**
 * Empty the two tables of recorded work.
 *
 * Every attendance and time figure in this file is one a human added up from the handful of
 * rows the test itself writes. Phase 10's `WorkSeeder` fills the seeded database with two real
 * months of attendance and tracked time — which every Phase 4 screen and three of the reports
 * needed, and which this file's arithmetic cannot survive: its windows are inside that period,
 * and one of its fixtures lands on a day the seed has already written (the unique index on
 * `(employee_id, date)` says so). The seeded rows are asserted in
 * `tests/Feature/Database/WorkSeederTest.php`; here they are cleared so the sums stay exact.
 */
function reportsClearRecordedWork(): void
{
    TimeEntry::query()->delete();
    AttendanceRecord::query()->delete();
}

function reportsBuild(ReportKey $key, User $viewer, array $input = []): ReportResult
{
    return app(ReportService::class)->build($key, $viewer, ReportFilters::for($key, $input, Carbon::today()));
}

/** One row of a result, found by the value of its first column. */
function reportsRow(ReportResult $result, string $key, string $value): array
{
    foreach ($result->rows as $row) {
        if ((string) $row[$key] === $value) {
            return $row;
        }
    }

    throw new RuntimeException("No row where {$key} = {$value}.");
}

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
});

/*
|--------------------------------------------------------------------------
| Task
|--------------------------------------------------------------------------
*/

it('counts the seeded tasks by status, and agrees with the tasks table', function () {
    $result = reportsBuild(ReportKey::Task, $this->admin, reportsWideWindow());

    // TaskSeeder writes 25 tasks. One of them ("Refresh the agency case-study deck") has
    // neither a start nor a due date, so it is in no dated window at all — 24 is the right
    // answer and 25 would mean the window predicate had been quietly widened.
    expect(Task::count())->toBe(25);
    expect($result->totals['tasks'])->toBe(24);

    expect(reportsRow($result, 'status', 'progress'))
        ->toBe(['status' => 'progress', 'tasks' => 6, 'overdue' => 3, 'share' => 25]);

    expect(reportsRow($result, 'status', 'cancelled')['tasks'])->toBe(1);
    expect(reportsRow($result, 'status', 'done')['tasks'])->toBe(3);

    // The per-status shares add up to the whole, give or take the rounding of eight rows.
    expect(array_sum(array_column($result->rows, 'tasks')))->toBe(24);
})->group('phase10', 'reports');

it('gives the Task report the same overdue number the Overdue report lists', function () {
    // The point of both going through TaskBucket: a count on one screen and the rows on
    // another cannot come from two predicates.
    $task = reportsBuild(ReportKey::Task, $this->admin, reportsWideWindow());
    $overdue = reportsBuild(ReportKey::Overdue, $this->admin);

    expect($task->totals['overdue'])->toBe(7)
        ->and(count($overdue->rows))->toBe(7);
})->group('phase10', 'reports');

it('narrows the Task report by client, project and employee', function () {
    $buffalo = Client::where('name', 'Buffalo Modular Homes')->firstOrFail();
    $seo = Project::where('name', 'Buffalo Modular — SEO')->firstOrFail();
    $tapu = Employee::where('employee_number', 'GT-003')->firstOrFail();

    // Buffalo has three projects carrying 5 + 5 + 4 dated tasks.
    expect(reportsBuild(ReportKey::Task, $this->admin, reportsWideWindow() + ['client' => $buffalo->getKey()])->totals['tasks'])
        ->toBe(14);

    expect(reportsBuild(ReportKey::Task, $this->admin, reportsWideWindow() + ['project' => $seo->getKey()])->totals['tasks'])
        ->toBe(5);

    expect(reportsBuild(ReportKey::Task, $this->admin, reportsWideWindow() + ['employee' => $tapu->getKey()])->totals['tasks'])
        ->toBe(9);
})->group('phase10', 'reports');

/*
|--------------------------------------------------------------------------
| Employee Work
|--------------------------------------------------------------------------
*/

it('lists everybody by name with their seeded counts, and ranks nobody', function () {
    $result = reportsBuild(ReportKey::EmployeeWork, $this->admin, reportsWideWindow());

    // Part H §1 and the contract's rule 3: by NAME. Tapu has nine tasks and Yaseen ten, so an
    // implementation that sorted by output would put them at the top and fail here.
    expect(array_column($result->rows, 'employee'))
        ->toBe(['Accountant', 'Faruk Ahmed', 'Shahadat Hossain', 'Tapu', 'Yaseen']);

    expect(reportsRow($result, 'employee', 'Tapu'))
        ->toBe(['employee' => 'Tapu', 'tasks' => 9, 'completed' => 1, 'open' => 8, 'overdue' => 4]);

    expect(reportsRow($result, 'employee', 'Yaseen')['tasks'])->toBe(10);
    expect(reportsRow($result, 'employee', 'Accountant')['tasks'])->toBe(0);

    // No chart, because a bar chart of people is a ranking drawn sideways.
    expect($result->charts)->toBe([]);

    // And no column that is a score, a rate or a percentage of a target.
    $keys = array_map(fn (ReportColumn $column): string => $column->key, $result->columns);
    expect($keys)->toBe(['employee', 'tasks', 'completed', 'open', 'overdue']);
})->group('phase10', 'reports');

/*
|--------------------------------------------------------------------------
| Project
|--------------------------------------------------------------------------
*/

it('gives every seeded project its standing, and the same totals the Task report has', function () {
    $result = reportsBuild(ReportKey::Project, $this->admin, reportsWideWindow());
    $task = reportsBuild(ReportKey::Task, $this->admin, reportsWideWindow());

    expect($result->rows)->toHaveCount(7);

    $seo = reportsRow($result, 'project', 'Buffalo Modular — SEO');
    expect($seo['client'])->toBe('Buffalo Modular Homes')
        ->and($seo['domain'])->toBe('buffalomodular.com')
        ->and($seo['status'])->toBe('Active')
        ->and($seo['tasks'])->toBe(5)
        ->and($seo['overdue'])->toBe(2);

    expect(reportsRow($result, 'project', 'APH — Website Maintenance')['status'])->toBe('On Hold');

    // Every task belongs to exactly one project, so the two reports have to agree on the total.
    expect($result->totals['tasks'])->toBe($task->totals['tasks'])
        ->and($result->totals['overdue'])->toBe($task->totals['overdue']);
})->group('phase10', 'reports');

/*
|--------------------------------------------------------------------------
| Overdue
|--------------------------------------------------------------------------
*/

it('lists the late tasks most-overdue first with the right number of days', function () {
    $result = reportsBuild(ReportKey::Overdue, $this->admin);

    expect($result->rows)->toHaveCount(7);
    expect($result->rows[0]['task'])->toBe('Claim and verify the Google Business Profile');

    // Seeded due today − 10.
    expect($result->rows[0]['days_late'])->toBe(10)
        ->and($result->rows[0]['assignees'])->toBe('Tapu');

    $lateness = array_column($result->rows, 'days_late');
    expect($lateness)->toBe(collect($lateness)->sortDesc()->values()->all());

    // Two assignees travel on one row, not as two rows. Canonicalised, because
    // `ReportService::assigneeNames()` says in so many words that the names come back "in the
    // order the relation returns them" and `Task::assignees()` has no ORDER BY — so the order
    // is whatever Postgres feels like. It was stable until Phase 10's `WorkSeeder` started
    // writing to `tasks`, and this test then failed only when another file had run first, which
    // is a flake waiting to happen rather than anything about the cell. **The fix belongs in
    // the relation, not here**: see the report.
    expect(explode(', ', reportsRow($result, 'task', 'Investigate the slow gallery page load')['assignees']))
        ->toEqualCanonicalizing(['Yaseen', 'Faruk Ahmed']);

    expect($result->totals['task'])->toBe('7 overdue');
})->group('phase10', 'reports');

/*
|--------------------------------------------------------------------------
| Attendance — from the daily_work_summary view
|--------------------------------------------------------------------------
*/

it('counts recorded attendance days from the reporting view, and leaves unrecorded days out', function () {
    reportsClearRecordedWork();

    $yaseen = Employee::where('employee_number', 'GT-004')->firstOrFail();
    $monday = Carbon::today()->subDays(7);

    AttendanceRecord::factory()->forEmployee($yaseen)->on($monday)->create();
    AttendanceRecord::factory()->forEmployee($yaseen)->on($monday->copy()->addDay())->late()->create();
    AttendanceRecord::factory()->forEmployee($yaseen)->on($monday->copy()->addDays(2))->absent()->create();

    $result = reportsBuild(ReportKey::Attendance, $this->admin, [
        'from' => $monday->toDateString(),
        'to' => Carbon::today()->toDateString(),
    ]);

    // Only Yaseen has anything recorded, so only Yaseen is a row. The other four are ABSENT
    // rather than four rows of zeroes claiming they were neither in nor out.
    expect($result->rows)->toHaveCount(1);

    $row = reportsRow($result, 'employee', 'Yaseen');
    expect($row['days'])->toBe(3)
        ->and($row['present'])->toBe(1)
        ->and($row['late'])->toBe(1)
        ->and($row['absent'])->toBe(1)
        // The factory's plain day is 08:58 → 17:02 (484 minutes) and its late day is
        // 09:40 → 17:02 (442). The absent day has no clock at all and contributes nothing —
        // which is the view's own `clock_in is not null and clock_out is not null` rule, not a
        // second one written here.
        ->and($row['worked'])->toBe(926);

    expect($result->totals['days'])->toBe(3);
})->group('phase10', 'reports');

it('says so plainly when nothing was recorded, rather than printing a table of zeroes', function () {
    reportsClearRecordedWork();

    $result = reportsBuild(ReportKey::Attendance, $this->admin, reportsWideWindow());

    expect($result->rows)->toBe([])
        ->and($result->isEmpty())->toBeTrue()
        ->and($result->empty)->toBe('No attendance was recorded in this window.');

    // An empty report carries no footer and no chart: a "Total 0" under an empty table is a
    // figure about nothing.
    $encoded = json_decode(json_encode($result), true);
    expect($encoded['totals'])->toBeNull()
        ->and($encoded['charts'])->toBe([]);
})->group('phase10', 'reports');

/*
|--------------------------------------------------------------------------
| Time
|--------------------------------------------------------------------------
*/

it('adds tracked minutes per project and keeps time awaiting a decision out of the total', function () {
    reportsClearRecordedWork();

    $tapu = Employee::where('employee_number', 'GT-003')->firstOrFail();
    $seoTask = Task::where('title', 'Optimize Home Model pages')->firstOrFail();
    $heatTask = Task::where('title', 'Local pack audit for emergency plumber terms')->firstOrFail();
    $day = Carbon::today()->subDays(3);

    // Two approved hours on the SEO project…
    TimeEntry::factory()->forEmployee($tapu)->onTask($seoTask)->on($day)->create(['duration_seconds' => 3600]);
    TimeEntry::factory()->forEmployee($tapu)->onTask($seoTask)->on($day)->create(['duration_seconds' => 3600]);
    // …half an hour on Heat Gap…
    TimeEntry::factory()->forEmployee($tapu)->onTask($heatTask)->on($day)->create(['duration_seconds' => 1800]);
    // …and forty-five minutes nobody has ruled on yet.
    TimeEntry::factory()->forEmployee($tapu)->onTask($heatTask)->on($day)
        ->create(['duration_seconds' => 2700, 'approved_at' => null]);

    $result = reportsBuild(ReportKey::Time, $this->admin, [
        'from' => $day->toDateString(),
        'to' => Carbon::today()->toDateString(),
    ]);

    expect(reportsRow($result, 'project', 'Buffalo Modular — SEO'))
        ->toBe(['project' => 'Buffalo Modular — SEO', 'entries' => 2, 'tracked' => 120, 'pending' => 0]);

    expect(reportsRow($result, 'project', 'Heat Gap — SEO Retainer'))
        ->toBe(['project' => 'Heat Gap — SEO Retainer', 'entries' => 2, 'tracked' => 30, 'pending' => 45]);

    // The pending 45 minutes is beside the total, never inside it — `scopeCounted()` is
    // `approved_at is not null` and nothing here re-types it.
    expect($result->totals['tracked'])->toBe(150)
        ->and($result->totals['pending'])->toBe(45);

    // Biggest first, and the donut only carries the tracked side.
    expect($result->rows[0]['project'])->toBe('Buffalo Modular — SEO');
    expect($result->charts[0]->series)->toBe([
        ['label' => 'Buffalo Modular — SEO', 'value' => 120],
        ['label' => 'Heat Gap — SEO Retainer', 'value' => 30],
    ]);
})->group('phase10', 'reports');

/*
|--------------------------------------------------------------------------
| Payroll
|--------------------------------------------------------------------------
*/

it('adds up September\'s seeded payroll draft as decimal strings', function () {
    $result = reportsBuild(ReportKey::Payroll, $this->admin, ['from' => '2026-09-01', 'to' => '2026-09-30']);

    expect($result->rows)->toHaveCount(1);

    // PayrollSeeder: 2200 + 2000 + 1200 + 900 + 1100 = 7400, one line per active employee.
    expect($result->rows[0])->toBe([
        'month' => 'September 2026',
        'status' => 'Draft',
        'employees' => 5,
        'gross' => '7400.00',
        'deductions' => '0.00',
        'net' => '7400.00',
    ]);

    expect($result->totals['net'])->toBe('7400.00');

    // Money is a STRING all the way out — never a float, never rounded in PHP.
    expect($result->rows[0]['net'])->toBeString();

    // `admin_notes` is the column the Accountant never receives (Part D §14). It has no place
    // in a report either, at any depth.
    expect(json_encode($result))->not->toContain('admin_notes');
})->group('phase10', 'reports');

it('finds no payroll period outside the window', function () {
    $result = reportsBuild(ReportKey::Payroll, $this->admin, ['from' => '2026-11-01', 'to' => '2026-11-30']);

    expect($result->rows)->toBe([])
        ->and($result->empty)->toBe('No payroll period falls in this window.');
})->group('phase10', 'reports');
