<?php

namespace App\Http\Controllers\Shared;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\FinanceReportRequest;
use App\Services\FinanceService;
use App\Services\SettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The company's books, read: the Finance dashboard and the Monthly financial report
 * (master prompt Part D §13, Phase 8).
 *
 * ## Why these two screens are SHARED and not one copy per shell
 *
 * Part E's Phase 8 heading says *"Screens (Accountant shell + Admin → Finance)"*, and this repo
 * has already answered that question four times — for Messages, Leave, Attendance and Meetings.
 * **Whose money it is belongs to the agency, not to the shell somebody is looking at.** An
 * Admin reading September and the Accountant reading September are looking at the same rows,
 * asked the same way, with the same answer; two controllers would be two places for "what is
 * September" to be answered differently, and the two things that would disagree are both about
 * money.
 *
 * So the routes live in `routes/shared.php` behind **`can:finance.view`**, and the pages pick
 * their layout from `auth.user.surface` exactly as `Pages/Shared/Messages.vue` does. The
 * permission is also how the phase's security line is spelled: *"Employees/Remote get 403 on
 * every finance route"* — they hold neither finance key, so the route gate refuses them before
 * a controller runs. No role is named in this file.
 *
 * ## Nothing here re-does the rollup
 *
 * `FinanceService::monthlyRollup()` is the one statement of *"totals per category for a month,
 * and the net in integer cents"*. Both screens call it; neither re-sums anything, and no total
 * on either page is computed in Vue.
 *
 * ## What this controller DOES own: the two cuts a single month cannot give
 *
 * The report asks for three cuts and the service answers one of them. The other two are here,
 * and both are written as **one query each**:
 *
 *   - **The trend** is a range-scanned `GROUP BY` per side over the whole window — *two*
 *     queries for twelve months, not twenty-four. Calling `monthlyRollup()` in a loop would be
 *     twenty-four round trips for a chart, and `FinanceService` is not this slice's to extend.
 *   - **By project** is one `LEFT JOIN` grouped by project, so income with no project is a real
 *     `GROUP BY` bucket (SQL folds every NULL into one group) rather than a second query.
 *
 * A twelve-month report is therefore **five** queries that touch the books: two for the chosen
 * month's categories, two for the trend, one for the projects.
 * `tests/Feature/Finance/FinanceReportEndpointsTest.php` asserts that number.
 *
 * ## Part C, on the by-project cut
 *
 * A project appears here as **name and domain and nothing else** — the same four-key discipline
 * `AccountantProjectResource` documents at length. Not the client, not the client's id, not a
 * contact, not the price, not a status, not a count of anything. The Accountant has ❌ on the
 * whole operational column of the matrix, and an Admin reading this page does not need a client
 * name to recognise which *Website Maintenance* row is which — Part C §2 already settles that
 * the domain is the identifier that travels.
 *
 * `AccountantProjectResource` is not reused for it because that payload's fourth key is
 * `finance` — the price, the contract and the profitability snapshot — and a report of what a
 * project BILLED has no business carrying what it is CONTRACTED for. Naming the two columns
 * here is additive in the same sense that class argues for: a field added to `project_finance`
 * next phase cannot arrive in this payload, because it would have to be typed into this file.
 */
class FinanceReportController extends Controller
{
    public function __construct(
        private readonly FinanceService $finance,
        private readonly SettingsService $settings,
    ) {}

    /**
     * `GET /finance` — *"this month income / expense / net, by category"* (Part D §13).
     *
     * The month is in the URL and defaults to the one we are in, so a month is a link and the
     * back button works.
     */
    public function dashboard(FinanceReportRequest $request): Response
    {
        $month = $request->monthStart();

        return Inertia::render('Shared/Finance/Dashboard', [
            'month' => $this->monthPayload($month),
            'rollup' => $this->finance->monthlyRollup($request->user(), (int) $month->year, (int) $month->month),
            // `settings.currency`, the one place it is configured — the same key the two
            // ledgers send, so a figure wears the same symbol on every finance screen.
            'currency' => (string) $this->settings->get('currency'),
            'reportHref' => '/finance/report?month='.$month->format('Y-m'),
        ]);
    }

    /**
     * `GET /finance/report` — the Monthly financial report's three cuts: by category, by
     * project, trend (Part D §13).
     */
    public function report(FinanceReportRequest $request): Response
    {
        $month = $request->monthStart();
        $months = $request->trendMonths();

        return Inertia::render('Shared/Finance/Report', [
            'month' => $this->monthPayload($month),

            // Cut one. The same call, and therefore the same numbers, as the dashboard.
            'rollup' => $this->finance->monthlyRollup($request->user(), (int) $month->year, (int) $month->month),

            // Cut two.
            'byProject' => $this->byProject($month),

            // Cut three.
            'trend' => $this->trend($month, $months),

            'currency' => (string) $this->settings->get('currency'),
            'dashboardHref' => '/finance?month='.$month->format('Y-m'),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | By project — income only, and the screen says so
    |--------------------------------------------------------------------------
    */

    /**
     * Income for the month, grouped by the project it was billed against.
     *
     * **This cut is income-only, and that is a fact about the data model rather than a gap in
     * this screen.** Part D §20 gives `expenses` no `project_id` — the migration's own note
     * explains why *Project Cost* does not imply one — so there is no per-project cost to show.
     * Showing an empty expense column beside the income one would invite the reader to conclude
     * that projects cost nothing, which is the single most misleading thing this report could
     * say. The screen therefore says *"income only"* in words (decision 8-17's open question is
     * per-project cost, and inventing a link to answer it is out of scope).
     *
     * **One query.** A `LEFT JOIN` grouped by the project's id, name and domain: SQL folds
     * every `NULL` project into one group, so *"income with no project"* is a row the database
     * produced rather than a second query or a subtraction in PHP.
     *
     * Ordered by amount, largest first, because a ranked list is what a reader wants from this
     * cut; the unlinked row is moved to the end afterwards **in PHP, not in SQL**, so it reads
     * as the remainder it is without costing a sort key.
     *
     * @return array{
     *     rows: list<array{project_id: int|null, name: string, domain: string|null, total: string}>,
     *     total: string,
     * }
     */
    private function byProject(Carbon $month): array
    {
        $rows = DB::table('income as record')
            ->leftJoin('projects as project', 'project.id', '=', 'record.project_id')
            ->whereBetween('record.date', $this->range($month))
            ->groupBy('project.id', 'project.name', 'project.domain')
            ->orderByRaw('SUM(record.amount) DESC')
            ->orderBy('project.name')
            ->get([
                'project.id as project_id',
                'project.name as name',
                'project.domain as domain',
                DB::raw('SUM(record.amount) as total'),
            ])
            ->map(fn (object $row): array => [
                // Null for the unlinked bucket, and the screen labels it rather than
                // inventing a project. Part C: name and domain, and nothing else — no client,
                // no contact, no price, no status, no count.
                'project_id' => $row->project_id === null ? null : (int) $row->project_id,
                'name' => $row->name === null ? 'No project' : (string) $row->name,
                'domain' => $row->domain === null ? null : (string) $row->domain,
                'total' => $this->money((string) $row->total),
            ])
            ->all();

        // The remainder reads last.
        usort($rows, fn (array $a, array $b): int => ($a['project_id'] === null ? 1 : 0) <=> ($b['project_id'] === null ? 1 : 0));

        return [
            'rows' => array_values($rows),
            // Summed in cents off the rows already fetched, so this agrees with the income
            // total on the same page and costs nothing.
            'total' => $this->money((string) array_sum(array_map(
                fn (array $row): int => $this->cents($row['total']),
                $rows,
            )), true),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Trend — two queries, whatever N is
    |--------------------------------------------------------------------------
    */

    /**
     * Income, expense and net for each of the last `$months` months, ending with `$month`.
     *
     * **Two queries, not two per month.** Each side is one range scan over the whole window
     * with `GROUP BY` on the month, and the buckets are then zipped together in PHP. A
     * twelve-month trend built by calling `monthlyRollup()` twelve times would be twenty-four
     * round trips to draw one line — and `FinanceService` is not this slice's to extend, so the
     * range query is written here instead.
     *
     * **Every month in the window is a point, including the empty ones.** That is the opposite
     * of the rule the category lists follow, and deliberately: a category with no rows is a
     * thing that did not happen, while a month with no rows is a month that still happened —
     * dropping it would compress the x-axis and draw a line straight from August to October as
     * if September did not exist. A zero here is a measurement; a zero in a category list would
     * be an invention.
     *
     * **The net is integer cents**, never a subtraction of two floats, exactly as
     * `FinanceService::monthlyRollup()` computes it.
     *
     * @return array{
     *     months: int,
     *     from: string,
     *     to: string,
     *     points: list<array{month: string, label: string, short_label: string, income: string, expense: string, net: string}>,
     * }
     */
    private function trend(Carbon $month, int $months): array
    {
        $first = $month->copy()->subMonthsNoOverflow($months - 1)->startOfMonth();
        $window = [$first->toDateString(), $month->copy()->endOfMonth()->toDateString()];

        $income = $this->monthlyTotals('income', $window);
        $expenses = $this->monthlyTotals('expenses', $window);

        $points = [];

        for ($step = 0; $step < $months; $step++) {
            $point = $first->copy()->addMonthsNoOverflow($step);
            $key = $point->format('Y-m');

            $in = $income[$key] ?? 0;
            $out = $expenses[$key] ?? 0;

            $points[] = [
                'month' => $key,
                'label' => $point->isoFormat('MMMM YYYY'),
                // The x-axis tick. "Sep 26" fits at 360 px where "September 2026" does not.
                'short_label' => $point->isoFormat('MMM YY'),
                'income' => $this->money((string) $in, true),
                'expense' => $this->money((string) $out, true),
                'net' => $this->money((string) ($in - $out), true),
            ];
        }

        return [
            'months' => $months,
            'from' => $first->format('Y-m'),
            'to' => $month->format('Y-m'),
            'points' => $points,
        ];
    }

    /**
     * `SUM(amount) GROUP BY month` for one table over one window, as `YYYY-MM => cents`.
     *
     * The table name is a constant from this class, never anything a caller supplied — the same
     * rule `FinanceService::totalsByCategory()` states.
     *
     * `to_char()` is in the SELECT and not in the WHERE: the window is a plain `BETWEEN` on
     * `date`, so the `(date, category_id)` index still range-scans instead of the planner
     * having to evaluate a function on every row.
     *
     * @param  array{string, string}  $window
     * @return array<string, int>
     */
    private function monthlyTotals(string $table, array $window): array
    {
        return DB::table($table)
            ->whereBetween('date', $window)
            ->groupByRaw("to_char(date, 'YYYY-MM')")
            ->get([
                DB::raw("to_char(date, 'YYYY-MM') as bucket"),
                DB::raw('SUM(amount) as total'),
            ])
            ->mapWithKeys(fn (object $row): array => [
                (string) $row->bucket => $this->cents((string) $row->total),
            ])
            ->all();
    }

    /*
    |--------------------------------------------------------------------------
    | Shared
    |--------------------------------------------------------------------------
    */

    /**
     * The month, and the two months either side of it — so "previous" is a link the server
     * built rather than date arithmetic repeated in Vue.
     *
     * @return array{value: string, label: string, previous: string, next: string, is_current: bool}
     */
    private function monthPayload(Carbon $month): array
    {
        return [
            'value' => $month->format('Y-m'),
            'label' => $month->isoFormat('MMMM YYYY'),
            'previous' => $month->copy()->subMonthNoOverflow()->format('Y-m'),
            'next' => $month->copy()->addMonthNoOverflow()->format('Y-m'),
            'is_current' => $month->isSameMonth(Carbon::now(config('app.timezone'))),
        ];
    }

    /**
     * @return array{string, string}
     */
    private function range(Carbon $month): array
    {
        return [$month->copy()->startOfMonth()->toDateString(), $month->copy()->endOfMonth()->toDateString()];
    }

    /**
     * A decimal string to whole cents. `round()` before the cast, because `(int) (8.6 * 100)`
     * is 859 on a binary float — which is why nothing about money here is ever a float.
     */
    private function cents(string $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    /**
     * Cents (or a decimal string from PostgreSQL) back to the exact two-scale string every
     * other money value in this application travels as.
     */
    private function money(string $amount, bool $isCents = false): string
    {
        $cents = $isCents ? (int) $amount : $this->cents($amount);

        return number_format($cents / 100, 2, '.', '');
    }
}
