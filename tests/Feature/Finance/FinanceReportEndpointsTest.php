<?php

use App\Http\Requests\Finance\FinanceReportRequest;
use App\Models\Employee;
use App\Models\Income;
use App\Models\Project;
use App\Models\User;
use App\Services\FinanceService;
use App\Support\RoleName;
use Database\Factories\IncomeFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| GET /finance and GET /finance/report — the numbers, over HTTP
|--------------------------------------------------------------------------
|
| Master prompt Part D §13 and Phase 8. Two shared screens behind one gate:
|
|   "Screens (Accountant shell + Admin → Finance): Finance dashboard (this
|    month income / expense / net, by category) […] Monthly financial
|    report (by category, by project, trend)."
|
| and the phase's own security line, which is what the first block asserts:
|
|   "Employees/Remote get 403 on every finance route."
|
| The file is deliberately written against the SERVICE and against Part D's
| sentence, not against literals a future edit could change in both places
| at once. The one set of literals here is Part D's own acceptance example,
| which is the thing that must never change.
|
| Every constant and helper is prefixed FINANCE_REPORT_ / financeReport*,
| because Pest declares both globally across the whole suite (AGENTS.md).
|
*/

/** Part D §13's acceptance example, verbatim: the income lines and their total. */
const FINANCE_REPORT_SEPTEMBER = [
    'Maintenance' => '860.00',
    'SEO' => '800.00',
    'Website' => '1250.00',
];

const FINANCE_REPORT_SEPTEMBER_TOTAL = '2910.00';

/** The seeded month those figures belong to. */
const FINANCE_REPORT_MONTH = '2026-09';

/**
 * How many queries against the books one twelve-month report costs.
 *
 * Two for the chosen month's categories (`FinanceService::monthlyRollup()`, one `GROUP BY` per
 * side), one for the by-project cut, and **two for the trend however long it is** — each side
 * is one range scan grouped by month, not one call per month. A twelve-month trend built by
 * looping `monthlyRollup()` would be twenty-four round trips instead of two.
 */
const FINANCE_REPORT_QUERY_BUDGET = 5;

/** The tables that count as "the books" when queries are being counted. */
const FINANCE_REPORT_TABLES = ['income', 'expenses', 'finance_categories', 'projects'];

/**
 * Exactly the keys a project may wear on a finance screen — Part C, and the same discipline
 * `AccountantProjectResource` documents. Not the client, not a contact, not a price, not a
 * status, not a count of anything.
 */
const FINANCE_REPORT_PROJECT_KEYS = ['project_id', 'name', 'domain', 'total'];

/** Seeded client names and contacts that must not appear anywhere in either payload. */
const FINANCE_REPORT_FORBIDDEN_NAMES = [
    'Buffalo Modular Homes',
    'Heat Gap Heating & Plumbing',
    'APH St Albans',
    'ABC Ltd',
    'Karen Buffalo',
    'Dave Heatgap',
    'Priya Aph',
    'Sam Abc',
];

/** `/finance?month=…`, as the screens spell it. */
function financeReportDashboardUrl(string $month = FINANCE_REPORT_MONTH): string
{
    return '/finance?month='.$month;
}

/** `/finance/report?month=…&months=…`. */
function financeReportUrl(string $month = FINANCE_REPORT_MONTH, ?int $months = null): string
{
    $url = '/finance/report?month='.$month;

    return $months === null ? $url : $url.'&months='.$months;
}

/**
 * Run a request and hand back how many of its queries touched the books.
 *
 * Counted by table rather than in total, so the session, the user lookup and the permission
 * join do not make the budget a number that drifts every time middleware changes. What is
 * being asserted is *"the report does not ask the books one question per month"*, and that is
 * a claim about these five tables.
 *
 * @return array{0: TestResponse, 1: list<string>}
 */
function financeReportQueriesFor(User $user, string $url): array
{
    // The query LOG rather than `DB::listen`: a listener registered per call would still be
    // attached on the next one, and the first call's tally would quietly grow to include the
    // second request's queries. That is exactly the shape of bug this test exists to catch.
    DB::flushQueryLog();
    DB::enableQueryLog();

    $response = test()->actingAs($user)->get($url);

    $touchesTheBooks = function (string $sql): bool {
        foreach (FINANCE_REPORT_TABLES as $table) {
            if (str_contains($sql, '"'.$table.'"')) {
                return true;
            }
        }

        return false;
    };

    $queries = array_values(array_filter(array_column(DB::getQueryLog(), 'query'), $touchesTheBooks));

    DB::disableQueryLog();
    DB::flushQueryLog();

    return [$response, $queries];
}

beforeEach(function () {
    $this->seed();

    $this->service = app(FinanceService::class);

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();
    $this->employee = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->remote = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->manager = Employee::factory()->forRole(RoleName::MANAGER)->create()->user;
});

/*
|--------------------------------------------------------------------------
| Who gets in — the phase's security line, both ways
|--------------------------------------------------------------------------
*/

it('renders both screens for the two roles that hold the books', function (string $email, string $url, string $component) {
    $user = User::where('email', $email)->firstOrFail();

    test()->actingAs($user)
        ->get($url)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component($component));
})->with([
    'admin, dashboard' => ['shahadat@goodtechies.test', '/finance', 'Shared/Finance/Dashboard'],
    'admin, report' => ['shahadat@goodtechies.test', '/finance/report', 'Shared/Finance/Report'],
    'accountant, dashboard' => ['accountant@goodtechies.test', '/finance', 'Shared/Finance/Dashboard'],
    'accountant, report' => ['accountant@goodtechies.test', '/finance/report', 'Shared/Finance/Report'],
])->group('phase8', 'finance');

it('gives everybody else 403 on every finance route', function (string $who, string $url) {
    test()->actingAs($this->{$who})->get($url)->assertForbidden();
})->with([
    'employee, dashboard' => ['employee', '/finance'],
    'employee, report' => ['employee', '/finance/report'],
    'remote employee, dashboard' => ['remote', '/finance'],
    'remote employee, report' => ['remote', '/finance/report'],
    // The MANAGER row of the matrix is ❌ on both finance cells. Nobody holds the role today,
    // which is why it is built here rather than looked up — a role with no user is a role whose
    // refusal nobody would otherwise test.
    'manager, dashboard' => ['manager', '/finance'],
    'manager, report' => ['manager', '/finance/report'],
])->group('phase8', 'finance');

it('sends a signed-out visitor to log in rather than refusing them', function (string $url) {
    // A 403 for a guest would say "this exists and you may not have it" to somebody who has not
    // said who they are yet. The auth middleware answers first, and it redirects.
    test()->get($url)->assertRedirect('/login');
})->with(['/finance', '/finance/report'])->group('phase8', 'finance');

/*
|--------------------------------------------------------------------------
| The dashboard's figures ARE the service's
|--------------------------------------------------------------------------
*/

it('sends the dashboard exactly what monthlyRollup() computed, not a second opinion', function () {
    $expected = $this->service->monthlyRollup($this->admin, 2026, 9);

    test()->actingAs($this->admin)
        ->get(financeReportDashboardUrl())
        ->assertOk()
        // Asserted against the SERVICE rather than against literals: a change to the rollup's
        // shape or its arithmetic has to move this screen with it, and a test written against
        // copied numbers would keep passing while the two drifted apart.
        ->assertInertia(fn (Assert $page) => $page
            ->where('rollup', $expected)
            ->where('month.value', FINANCE_REPORT_MONTH)
            ->where('month.label', 'September 2026'));
})->group('phase8', 'finance');

it('puts Part D\'s September sentence on the screen — Maintenance 860, SEO 800, Website 1250, total 2910', function () {
    $props = test()->actingAs($this->accountant)
        ->get(financeReportDashboardUrl())
        ->assertOk()
        ->inertiaProps();

    $lines = collect($props['rollup']['income']['categories'])->pluck('total', 'name')->all();

    expect($lines)->toBe(FINANCE_REPORT_SEPTEMBER)
        ->and($props['rollup']['income']['total'])->toBe(FINANCE_REPORT_SEPTEMBER_TOTAL)
        // The other half of Part D §13's month, and the operating result it implies.
        ->and($props['rollup']['expenses']['total'])->toBe('2110.00')
        ->and($props['rollup']['net'])->toBe('800.00');
})->group('phase8', 'finance');

it('defaults to the month we are in when the URL does not name one', function () {
    $this->travelTo('2026-09-15');

    test()->actingAs($this->admin)
        ->get('/finance')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('month.value', FINANCE_REPORT_MONTH));
})->group('phase8', 'finance');

/*
|--------------------------------------------------------------------------
| The month really is a window
|--------------------------------------------------------------------------
*/

it('leaves an August row out of September, and finds it in August', function () {
    IncomeFactory::new()
        ->inCategory('Website')
        ->of('9999.00')
        ->on('2026-08-31')
        ->recordedBy($this->accountant)
        ->create();

    $september = test()->actingAs($this->admin)->get(financeReportDashboardUrl())->inertiaProps();

    // Part D's number is untouched by a row dated the day before the month started.
    expect($september['rollup']['income']['total'])->toBe(FINANCE_REPORT_SEPTEMBER_TOTAL);

    $august = test()->actingAs($this->admin)->get(financeReportDashboardUrl('2026-08'))->inertiaProps();

    expect($august['rollup']['income']['total'])->toBe('9999.00')
        ->and($august['month']['label'])->toBe('August 2026');
})->group('phase8', 'finance');

it('refuses a month that is not a month, rather than quietly showing a different one', function () {
    test()->actingAs($this->admin)
        ->get('/finance?month=september')
        ->assertSessionHasErrors('month');
})->group('phase8', 'finance');

/*
|--------------------------------------------------------------------------
| By project — Part C's guard, and the row that is not a project
|--------------------------------------------------------------------------
*/

it('groups income by project and gives income with no project its own row', function () {
    $unlinked = IncomeFactory::new()
        ->inCategory('Other')
        ->of('77.00')
        ->on('2026-09-20')
        ->recordedBy($this->accountant)
        ->create();

    expect($unlinked->project_id)->toBeNull();

    $cut = test()->actingAs($this->admin)->get(financeReportUrl())->assertOk()->inertiaProps()['byProject'];

    $rows = collect($cut['rows']);
    $noProject = $rows->firstWhere('project_id', null);

    expect($noProject)->not->toBeNull()
        ->and($noProject['total'])->toBe('77.00')
        ->and($noProject['name'])->toBe('No project')
        ->and($noProject['domain'])->toBeNull()
        // Every seeded September income row is linked, so the six of them are five projects
        // (Buffalo Modular appears three times under three different projects) plus this one.
        ->and($rows->where('project_id', '!=', null))->not->toBeEmpty()
        // The cut adds up to the month's income, this row included.
        ->and($cut['total'])->toBe('2987.00');

    // The remainder reads last, rather than being an odd blank in the middle of a ranked list.
    expect($rows->last()['project_id'])->toBeNull();
})->group('phase8', 'finance');

it('shows a project by name and domain and by nothing else', function () {
    $rows = test()->actingAs($this->accountant)->get(financeReportUrl())->assertOk()->inertiaProps()['byProject']['rows'];

    expect($rows)->not->toBeEmpty();

    foreach ($rows as $row) {
        // The exact key set is the real assertion: a check for three field names passes the day
        // somebody adds a fourth.
        expect(array_keys($row))->toEqualCanonicalizing(FINANCE_REPORT_PROJECT_KEYS);
    }

    $seo = collect($rows)->firstWhere('name', 'Buffalo Modular — SEO');

    // Part C §2: the domain is what stands in place of the client's name, and it is how a human
    // tells four "— Website Maintenance" rows apart on an invoice.
    expect($seo)->not->toBeNull()
        ->and($seo['domain'])->toBe('buffalomodular.com');
})->group('phase8', 'finance');

it('names no seeded client and no seeded contact anywhere in either payload', function (string $url) {
    // The key-set assertion above catches a field somebody NAMED; this catches a client's name
    // arriving as the VALUE of an allowed key — `name` set to the client rather than the
    // project. The whole page object is searched, not only the by-project cut.
    $body = json_encode(
        test()->actingAs($this->accountant)->get($url)->assertOk()->inertiaPage(),
        JSON_THROW_ON_ERROR,
    );

    foreach (FINANCE_REPORT_FORBIDDEN_NAMES as $name) {
        expect($body)->not->toContain($name);
    }
})->with([financeReportUrl(), financeReportDashboardUrl()])->group('phase8', 'finance');

/*
|--------------------------------------------------------------------------
| The trend covers the months it claims, and no more
|--------------------------------------------------------------------------
*/

it('covers exactly the months the URL asks for, ending with the month on screen', function () {
    $trend = test()->actingAs($this->admin)->get(financeReportUrl(FINANCE_REPORT_MONTH, 6))->assertOk()
        ->inertiaProps()['trend'];

    expect($trend['months'])->toBe(6)
        ->and($trend['points'])->toHaveCount(6)
        ->and($trend['from'])->toBe('2026-04')
        ->and($trend['to'])->toBe(FINANCE_REPORT_MONTH);

    $months = array_column($trend['points'], 'month');

    expect($months)->toBe(['2026-04', '2026-05', '2026-06', '2026-07', '2026-08', '2026-09'])
        // The month before the window is not in it. A trend that quietly reached one month
        // further back would be a chart whose caption was wrong.
        ->and($months)->not->toContain('2026-03');
})->group('phase8', 'finance');

it('defaults to a year and gives every month in it a point, empty ones included', function () {
    $trend = test()->actingAs($this->admin)->get(financeReportUrl())->assertOk()->inertiaProps()['trend'];

    expect($trend['months'])->toBe(FinanceReportRequest::DEFAULT_TREND_MONTHS)
        ->and($trend['points'])->toHaveCount(FinanceReportRequest::DEFAULT_TREND_MONTHS)
        ->and($trend['from'])->toBe('2025-10');

    // Only September is seeded, so the other eleven are zeroes — and they are POINTS, not gaps.
    // Dropping them would draw a line straight from one recorded month to the next, as though
    // the months between had not happened.
    $august = collect($trend['points'])->firstWhere('month', '2026-08');

    expect($august['income'])->toBe('0.00')
        ->and($august['expense'])->toBe('0.00')
        ->and($august['net'])->toBe('0.00');

    $september = collect($trend['points'])->firstWhere('month', FINANCE_REPORT_MONTH);

    // The trend's September and the by-category September are the same month, computed twice
    // by two different queries. They have to agree.
    expect($september['income'])->toBe(FINANCE_REPORT_SEPTEMBER_TOTAL)
        ->and($september['expense'])->toBe('2110.00')
        ->and($september['net'])->toBe('800.00');
})->group('phase8', 'finance');

it('refuses a trend window that is too short to have a direction, or too long to read', function (string $months) {
    test()->actingAs($this->admin)
        ->get('/finance/report?month='.FINANCE_REPORT_MONTH.'&months='.$months)
        ->assertSessionHasErrors('months');
})->with(['2', '36'])->group('phase8', 'finance');

/*
|--------------------------------------------------------------------------
| The query budget
|--------------------------------------------------------------------------
*/

it('builds a twelve-month report in five queries against the books, not one per month', function () {
    [$response, $queries] = financeReportQueriesFor($this->admin, financeReportUrl(FINANCE_REPORT_MONTH, 12));

    $response->assertOk();

    expect($queries)->toHaveCount(
        FINANCE_REPORT_QUERY_BUDGET,
        'The report asked the books '.count($queries).' questions: '.implode(' | ', $queries),
    );
})->group('phase8', 'finance');

it('costs the same twenty-four months as it does three', function () {
    [, $short] = financeReportQueriesFor($this->admin, financeReportUrl(FINANCE_REPORT_MONTH, 3));
    [, $long] = financeReportQueriesFor($this->admin, financeReportUrl(FINANCE_REPORT_MONTH, 24));

    // The point of the range query, stated as a test: the trend's cost does not grow with its
    // length. A loop over `monthlyRollup()` would make this 8 against 50.
    expect(count($long))->toBe(count($short))
        ->and(count($long))->toBe(FINANCE_REPORT_QUERY_BUDGET);
})->group('phase8', 'finance');

/*
|--------------------------------------------------------------------------
| The Company dashboard's Row 3
|--------------------------------------------------------------------------
*/

it('puts this month\'s income, expense and operating result on the Admin dashboard', function () {
    $this->travelTo('2026-09-15');

    $rollup = $this->service->monthlyRollup($this->admin, 2026, 9);

    $finance = test()->actingAs($this->admin)->get('/admin/dashboard')->assertOk()->inertiaProps()['finance'];

    expect($finance['month'])->toBe(FINANCE_REPORT_MONTH)
        ->and($finance['label'])->toBe('September 2026')
        // Against the service, not against literals — Row 3 and `/finance` are one number.
        ->and($finance['income'])->toBe($rollup['income']['total'])
        ->and($finance['expense'])->toBe($rollup['expenses']['total'])
        ->and($finance['operating_result'])->toBe($rollup['net'])
        // And it links to the month it counted.
        ->and($finance['href'])->toBe(financeReportDashboardUrl());
})->group('phase8', 'finance');

it('computes the operating result as income less expense, in cents', function () {
    $this->travelTo('2026-09-15');

    $finance = test()->actingAs($this->admin)->get('/admin/dashboard')->assertOk()->inertiaProps()['finance'];

    $cents = fn (string $amount): int => (int) round(((float) $amount) * 100);

    expect($cents($finance['operating_result']))
        ->toBe($cents($finance['income']) - $cents($finance['expense']))
        ->and($finance['operating_result'])->toBe('800.00');
})->group('phase8', 'finance');

it('carries the month payroll total on the dashboard, from the payroll table and not the ledger', function () {
    $this->travelTo('2026-09-15');

    $finance = test()->actingAs($this->admin)->get('/admin/dashboard')->assertOk()->inertiaProps()['finance'];

    // **This test asserted `null` and `payroll_phase === 9` until Phase 9 made the card real**,
    // and it is kept rather than deleted because the shape it was defending still matters: the
    // card must render the real figure when there is one and the ABSENCE when there is not —
    // never `$0.00`, which reads as "we paid nobody this month".
    //
    // The number comes from `SUM(payroll_items.net_salary)`, summed by PostgreSQL over the
    // generated column. It is deliberately NOT in `operating_result`: that figure is income
    // less expenses out of the finance ledger, and `payroll_items` is a different table the
    // rollup has never read. An expense somebody filed under the *Payroll* category IS in it.
    $total = DB::table('payroll_items')
        ->join('payroll_periods', 'payroll_periods.id', '=', 'payroll_items.payroll_period_id')
        ->whereDate('payroll_periods.month', '2026-09-01')
        ->sum('payroll_items.net_salary');

    expect($finance)->toHaveKey('payroll')
        ->and($finance['payroll'])->not->toBeNull()
        ->and((float) $finance['payroll'])->toBe((float) $total)
        // The stale phase pointer is gone: the phase shipped, so a card saying it arrives later
        // would be the screen lying about itself.
        ->and(array_key_exists('payroll_phase', $finance))->toBeFalse();
})->group('phase8', 'finance');

it('renders the absence rather than a zero for a month with no payroll period', function () {
    // A month before the 1st-of-month draft has run. `$0.00` here would claim we paid nobody;
    // null is the honest answer and the card prints an em-dash over a sentence saying so.
    $this->travelTo('2026-11-15');

    $finance = test()->actingAs($this->admin)->get('/admin/dashboard')->assertOk()->inertiaProps()['finance'];

    expect($finance)->toHaveKey('payroll')
        ->and($finance['payroll'])->toBeNull();
})->group('phase9', 'finance');

/*
|--------------------------------------------------------------------------
| The two screens agree with each other
|--------------------------------------------------------------------------
*/

it('gives the report and the dashboard the same September', function () {
    $dashboard = test()->actingAs($this->admin)->get(financeReportDashboardUrl())->inertiaProps();
    $report = test()->actingAs($this->admin)->get(financeReportUrl())->inertiaProps();

    // One service call each, so this cannot be anything but true — which is exactly why it is
    // worth pinning: the day somebody gives one of the two screens its own query, this fails.
    expect($report['rollup'])->toBe($dashboard['rollup']);
})->group('phase8', 'finance');

it('does not leak a project into the dashboard, which has no project cut at all', function () {
    $props = test()->actingAs($this->accountant)->get(financeReportDashboardUrl())->inertiaProps();

    expect($props)->not->toHaveKey('byProject')
        ->and($props)->not->toHaveKey('trend');
})->group('phase8', 'finance');

it('still finds September through the seeded rows, so the demo is not a fixture', function () {
    expect(Income::query()->inMonth(2026, 9)->count())->toBe(6)
        ->and(Project::query()->whereNotNull('domain')->exists())->toBeTrue();
})->group('phase8', 'finance');
