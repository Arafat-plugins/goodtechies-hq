<?php

use App\Models\PayrollItem;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Services\FinanceService;
use App\Support\PayrollStatus;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Every dashboard carries its own cards, and the chart budget is three
|--------------------------------------------------------------------------
|
| Phase 10's test list, verbatim: "dashboard endpoint per role returns only
| its cards; charts ≤ 3 asserted".
|
| `ShellLandingTest` pins the exact prop KEYS of all three screens. What is
| here is the other half — that the keys are not the whole story:
|
|   - the Accountant's screen is finance-only, so it is asserted from the
|     inside as well (no task, project, attendance or headcount word
|     anywhere in its payload), and their My Payslip card is their own line
|     and not the company's wage bill;
|   - the money on the Accountant's screen and the money on the Company
|     dashboard's Row 3 are the same figures, because both are
|     `FinanceService::monthlyRollup()` and neither adds anything up;
|   - and the chart budget is counted in the page SOURCE, because a payload
|     cannot carry the number of charts drawn from it. `ReportResult`'s
|     constructor throws on a fourth chart; a dashboard has no constructor,
|     so this file is where Part D §3's "≤ 3 charts per screen" is enforced
|     for the three of them.
|
*/

const DASHBOARD_PAGES = [
    'Admin' => 'resources/js/Pages/Admin/Dashboard.vue',
    'Employee' => 'resources/js/Pages/Employee/Dashboard.vue',
    'Accountant' => 'resources/js/Pages/Accountant/Dashboard.vue',
];

/** The four chart wrappers. `@unovis/vue` is reached only through these (DESIGN.md §4.4). */
const DASHBOARD_CHART_COMPONENTS = ['AreaTrend', 'BarCompare', 'DonutBreakdown', 'Sparkline'];

beforeEach(function () {
    $this->seed();
});

/**
 * How many charts a dashboard page draws.
 *
 * Counted over the `<template>` only, and over element tags rather than the whole file: the
 * `import` line, a type import and a prose mention of a component in a comment are not a chart
 * on the screen, and counting them would make the budget unfixable by anything but silence.
 */
function dashboardChartCount(string $path): int
{
    $source = file_get_contents(base_path($path));
    $template = strstr($source, '<template>') ?: '';

    $count = 0;

    foreach (DASHBOARD_CHART_COMPONENTS as $component) {
        $count += preg_match_all('/<'.$component.'[\s>]/', $template);
    }

    return $count;
}

/**
 * Every scalar in a payload, flattened, so a word can be looked for at any depth.
 *
 * @return list<string>
 */
function dashboardPayloadWords(array $props): array
{
    return array_map(
        fn (mixed $value): string => is_scalar($value) ? mb_strtolower((string) $value) : '',
        array_values(Arr::dot($props)),
    );
}

/*
|--------------------------------------------------------------------------
| The chart budget
|--------------------------------------------------------------------------
*/

it('draws at most three charts on every dashboard', function (string $surface, string $path) {
    // Part D §3: "that is the 3-chart budget". The Company dashboard spends all three — Tasks by
    // status, Tasks by employee, Projects by type — and "Upcoming deadlines", the fourth thing
    // Row 2 names, is a LIST for exactly this reason.
    expect(dashboardChartCount($path))->toBeLessThanOrEqual(3);
})->with(array_map(
    fn (string $surface): array => [$surface, DASHBOARD_PAGES[$surface]],
    array_keys(DASHBOARD_PAGES),
))->group('phase10');

it('spends all three of the Company dashboard\'s charts and leaves none empty', function () {
    expect(dashboardChartCount(DASHBOARD_PAGES['Admin']))->toBe(3);

    // A chart bound to a literal empty array is a card that can never fill in. One sat on this
    // screen for six phases reading "Attendance, last 14 days" over `:data="[]"`.
    $template = strstr(file_get_contents(base_path(DASHBOARD_PAGES['Admin'])), '<template>');

    foreach (DASHBOARD_CHART_COMPONENTS as $component) {
        expect($template)->not->toContain('<'.$component.' :data="[]"');
    }
})->group('phase10');

/*
|--------------------------------------------------------------------------
| The Accountant screen is finance-only
|--------------------------------------------------------------------------
*/

it('gives the accountant a finance-only summary and nothing operational', function () {
    $accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();

    $props = $this->actingAs($accountant)->get('/accountant/dashboard')->assertOk()->inertiaPage()['props'];

    // Part C §1 gives the Accountant ❌ on the whole operational column, so none of it is
    // FETCHED — not fetched and hidden. A key nobody sent is a card nobody can draw.
    foreach (['stats', 'workStats', 'attention', 'taskStatuses', 'tasksByEmployee', 'projectsByType', 'upcomingDeadlines', 'upcomingMeetings', 'upcomingHolidays', 'taskStats', 'timer', 'schedule', 'recentActivity'] as $key) {
        expect($props)->not->toHaveKey($key);
    }

    // And from the inside: no task, project or client name reached the payload by another route.
    $words = dashboardPayloadWords($props);

    foreach (['buffalo modular', 'heat gap', 'seo retainer'] as $forbidden) {
        expect($words)->not->toContain($forbidden);
    }
})->group('phase10');

it('shows the accountant their own payslip and not the company wage bill', function () {
    $accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();

    $props = $this->actingAs($accountant)->get('/accountant/dashboard')->assertOk()->inertiaPage()['props'];

    $own = PayrollItem::query()
        ->where('employee_id', $accountant->employee->getKey())
        ->orderByDesc('id')
        ->firstOrFail();

    // The Accountant holds `payroll.view_others`, so "what may they see" is the whole company and
    // "which line is theirs" is a different question — `PayrollItem::belongsToEmployeeOf()`.
    // Getting that wrong would put somebody else's salary on a card called My Payslip.
    expect($props['payslip']['item']['id'])->toBe((int) $own->getKey())
        ->and($props['payslip']['item']['net_salary'])->toBe($own->net_salary)
        ->and($props['payslip']['href'])->toBe('/payslip/'.$own->getKey())
        // The company's wage bill IS on this screen, in the Payroll card — and it is a different,
        // larger number. If these two were ever equal the narrowing above had stopped working.
        ->and($props['finance']['payroll'])->not->toBe($own->net_salary);

    // Part D §14's "personal notes the Accountant never receives" cannot arrive here either:
    // the card is `PayrollItemResource`, which leaves the key absent for anybody but an ADMIN.
    expect($props['payslip']['item'])->not->toHaveKey('admin_notes');

    $this->actingAs($accountant)->get($props['payslip']['href'])->assertOk();
})->group('phase10');

it('gives the accountant the same September the Company dashboard and /finance give', function () {
    $accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();
    $admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();

    $theirs = $this->actingAs($accountant)->get('/accountant/dashboard')->inertiaPage()['props']['finance'];
    $company = $this->actingAs($admin)->get('/admin/dashboard')->inertiaPage()['props']['finance'];

    $asOf = Carbon::today();
    $rollup = app(FinanceService::class)->monthlyRollup($admin, (int) $asOf->year, (int) $asOf->month);

    // Three screens, one answer. Nothing on any of them sums anything: the figures are the
    // rollup's and the net is its own, computed in integer cents.
    expect($theirs['income'])->toBe($rollup['income']['total'])
        ->and($theirs['expense'])->toBe($rollup['expenses']['total'])
        ->and($theirs['operating_result'])->toBe($rollup['net'])
        ->and($theirs['income'])->toBe($company['income'])
        ->and($theirs['expense'])->toBe($company['expense'])
        ->and($theirs['operating_result'])->toBe($company['operating_result'])
        // And the same wage bill, although the two controllers reach it differently — one through
        // `PayrollItem::visibleTo()`, one through the period's own `withSum()`, which is the
        // aggregate `/payroll` lists.
        ->and($theirs['payroll'])->toBe($company['payroll']);
})->group('phase10');

it('lists only the payroll months the accountant can still act on', function () {
    $accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();

    $rows = $this->actingAs($accountant)->get('/accountant/dashboard')
        ->inertiaPage()['props']['outstanding'];

    expect($rows)->not->toBeEmpty();

    foreach ($rows as $row) {
        $period = PayrollPeriod::findOrFail((int) str_replace('payroll-', '', $row['id']));

        // `PayrollStatus::isOpenToAccountant()` is the single statement of Part D §14's
        // "read-only to Accountant afterwards". An approved, locked or paid month is finished as
        // far as this panel is concerned.
        expect($period->status->isOpenToAccountant())->toBeTrue()
            // The state is in the WORDS, not only in the medallion (DESIGN.md §5.6).
            ->and($row['meta'])->toStartWith($period->status->label())
            ->and($row['href'])->toBe('/payroll/'.$period->getKey());

        $this->actingAs($accountant)->get($row['href'])->assertOk();
    }
})->group('phase10');

it('drops a payroll month off the accountant\'s panel once it is approved', function () {
    $accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();

    $period = PayrollPeriod::query()->orderByDesc('month')->firstOrFail();

    $before = array_column($this->actingAs($accountant)->get('/accountant/dashboard')
        ->inertiaPage()['props']['outstanding'], 'id');

    expect($before)->toContain('payroll-'.$period->getKey());

    // Straight to approved through the machine's own door, which is where every status move goes.
    foreach ([PayrollStatus::Calculated, PayrollStatus::Reviewed, PayrollStatus::Approved] as $to) {
        $period->applyTransition($to);
        $period->save();
    }

    $after = array_column($this->actingAs($accountant)->get('/accountant/dashboard')
        ->inertiaPage()['props']['outstanding'], 'id');

    expect($after)->not->toContain('payroll-'.$period->getKey());
})->group('phase10');
