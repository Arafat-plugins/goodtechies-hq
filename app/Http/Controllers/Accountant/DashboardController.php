<?php

namespace App\Http\Controllers\Accountant;

use App\Http\Controllers\Controller;
use App\Http\Resources\PayrollItemResource;
use App\Models\Employee;
use App\Models\Income;
use App\Models\LeaveRequest;
use App\Models\PayrollItem;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Services\FinanceService;
use App\Services\LeaveService;
use App\Services\PayrollService;
use App\Services\SettingsService;
use App\Support\LeaveStatus;
use App\Support\PayrollStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Accountant's landing screen — *"**Accountant:** finance-only summary"* (master prompt
 * Part D §3), made real in Phase 10.
 *
 * Until now this was Phase 0's stub: four `StatCard`s carrying an em-dash and a phase number,
 * an `AttentionList` with no source, and two panels reading "Arrives in Phase 5 / 9" for
 * phases that have both shipped. Part E's Phase 10 goal is *"all dashboard cards are real"*,
 * and on this surface that meant every card on the page.
 *
 * ## Finance-only, and what the two exceptions are
 *
 * Part D §3's sentence is one clause long and this file keeps to it: this month's money, and
 * what is still waiting on the reader. There is no task count here, no project list, no
 * attendance, no headcount and no meeting — the Accountant has ❌ on that whole column of
 * Part C §1's matrix, so none of it is fetched rather than fetched and hidden.
 *
 * The two non-finance blocks are the two Part C §1 explicitly grants: *"the ACCOUNTANT may
 * apply for own leave and view own payslip. The Accountant shell therefore carries My Leave
 * and My Payslip"*. Both are about the reader and nobody else.
 *
 * ## Nothing is summed here, and nothing agrees with `/finance` by coincidence
 *
 *   - **This month's income, expense and operating result** are `FinanceService::monthlyRollup()`
 *     — the same call `GET /finance` makes and the same call the Company dashboard's Row 3
 *     makes. Three screens, one answer to *"what is September"*, and the net arrives computed
 *     in integer cents rather than by subtracting two decimal strings cast to float.
 *   - **The payroll figure** is `SUM(payroll_items.net_salary)` for the month's period, summed
 *     by PostgreSQL through `withSum()` — the **same aggregate `/payroll` lists**, so the card
 *     and the workbench cannot differ by a rounding. It is `null`, never zero, when the month
 *     has no period yet: a zero would say the agency paid nobody.
 *   - **My payslip** goes through `PayrollService::itemsFor()` narrowed by
 *     `PayrollItem::belongsToEmployeeOf()`, which is exactly what `GET /payslip` runs — see that
 *     controller for why the narrowing is not a second copy of the privacy scope. The row is
 *     `PayrollItemResource`, the only way a payroll item leaves this server.
 *
 * Every block is gated, and a block the reader may not have is **absent from the payload**
 * rather than sent as zeroes (Part C §1) — the rule `Admin\DashboardController` follows for its
 * finance and attendance blocks, followed here for the same reason.
 */
class DashboardController extends Controller
{
    /**
     * How many payroll months the "Outstanding" panel shows.
     *
     * Five, like every other shortlist on the dashboards. Payroll is one period a month and only
     * the unfinished ones are listed, so five is already more months than should ever be open at
     * once — the panel is a prompt, not a ledger.
     */
    private const OUTSTANDING = 5;

    public function __construct(
        private readonly FinanceService $finance,
        private readonly PayrollService $payroll,
        private readonly LeaveService $leave,
        private readonly SettingsService $settings,
    ) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $asOf = Carbon::today();

        return Inertia::render('Accountant/Dashboard', [
            'greetingName' => Str::before(trim($user->name), ' '),
            'today' => now(config('app.timezone'))->toDateString(),
            'finance' => $this->financeThisMonth($request, $asOf),
            'outstanding' => $this->outstanding($request),
            'leave' => $this->myLeave($user?->employee),
            'payslip' => $this->myPayslip($request),
        ]);
    }

    /**
     * This month's four figures — *"What came in and what went out?"*, which Part D §3 names as
     * the question this screen answers.
     *
     * The first three are `FinanceService::monthlyRollup()`'s, unmodified. `operating_result` is
     * that rollup's own `net`: income less expenses, in integer cents. Expenses filed under the
     * *Payroll* category are in it — they are expenses somebody entered — and the payroll RUN is
     * not, because `payroll_items` is a different table the rollup never reads. The screen says so
     * in words, because a figure by that name which quietly omitted the largest cost would be
     * worse than no figure.
     *
     * `{}` for anybody without `finance.view`. Nobody who can reach this route lacks it today;
     * the gate is asked anyway, because that is where the answer lives if that stops being true.
     *
     * @return array<string, mixed>
     */
    private function financeThisMonth(Request $request, Carbon $asOf): array
    {
        $user = $request->user();

        if (! Gate::forUser($user)->allows('viewAny', Income::class)) {
            return [];
        }

        $rollup = $this->finance->monthlyRollup($user, (int) $asOf->year, (int) $asOf->month);
        $payroll = $this->payrollThisMonth($user, $asOf);

        return [
            'month' => $rollup['month'],
            'label' => $rollup['label'],
            'income' => $rollup['income']['total'],
            'expense' => $rollup['expenses']['total'],
            'payroll' => $payroll['total'],
            'payroll_note' => $payroll['note'],
            'payroll_href' => $payroll['href'],
            'operating_result' => $rollup['net'],
            'currency' => (string) $this->settings->get('currency'),
            'href' => '/finance?month='.$rollup['month'],
        ];
    }

    /**
     * The payroll card: this month's wage bill, its status, and how many people are on it.
     *
     * **`withSum()`, which is the aggregate `PayrollController::index()` lists the periods with**
     * — so the number on this card is the number on the workbench's row for the same month, by
     * construction rather than by two queries that happen to agree. `withCount()` is the line
     * count for the same reason.
     *
     * `null` when the month has **no period yet**, which is the ordinary state of every 1st before
     * `hq:create-payroll-draft` has run. The card prints an em-dash and `note` says which of the
     * two blanks it is in words — a zero here would claim the agency paid nobody this month, which
     * is the one wrong answer.
     *
     * The link is `Route::has()`-guarded rather than hard-coded, like the Admin card's: a
     * dashboard pointing at a route that does not exist is a 404 somebody finds by clicking.
     *
     * @return array{total: string|null, note: string, href: string|null}
     */
    private function payrollThisMonth(?User $user, Carbon $asOf): array
    {
        $href = Route::has('payroll.index') ? route('payroll.index', absolute: false) : null;

        if (! Gate::forUser($user)->allows('viewAny', PayrollPeriod::class)) {
            return ['total' => null, 'note' => 'You do not have access to payroll.', 'href' => null];
        }

        $period = PayrollPeriod::query()
            ->forMonth($asOf)
            ->withSum('items', 'net_salary')
            ->withCount('items')
            ->first();

        if ($period === null) {
            return [
                'total' => null,
                'note' => 'No payroll period for this month yet.',
                'href' => $href,
            ];
        }

        $lines = (int) $period->items_count;

        return [
            // The cast pins the scale, so an empty period reads "0.00" and not "0". PostgreSQL
            // did the addition; nothing here parses the string back into a number.
            'total' => number_format((float) ($period->items_sum_net_salary ?? 0), 2, '.', ''),
            'note' => sprintf(
                '%s · %s · %s',
                $period->label(),
                $period->status?->label() ?? 'Unknown',
                $lines === 1 ? '1 person' : $lines.' people',
            ),
            'href' => $href,
        ];
    }

    /**
     * "Outstanding" — the months still waiting on somebody, newest first.
     *
     * The panel had no source at all until now. Its one real source is the payroll machine:
     * `PayrollStatus::isOpenToAccountant()` is the single statement of *"may the Accountant still
     * change the figures on this period"* (Part D §14's *"read-only to Accountant afterwards"*),
     * so the rows here are exactly the months this reader can still act on. A period that is
     * approved, locked or paid is finished as far as this screen is concerned and is not listed.
     *
     * **Every row says what it is waiting for, in words** — *"Draft · 5 people · fill and
     * calculate"* — and links to that month's own screen. The medallion is the second encoding of
     * the sentence, never the only one (DESIGN.md §5.6).
     *
     * Unpaid invoices are not here and never will be: there is no invoicing in this build
     * (Part H §1), so a panel promising them would be promising a table that does not exist.
     * Unapproved expenses are not here either — an expense is recorded, not approved (Part D §13).
     * The panel's `note` on the page says both, rather than letting an empty panel imply that the
     * books are clear when two of its three imagined sources were never built.
     *
     * @return list<array{id: string, title: string, meta: string, href: string, tone: string}>
     */
    private function outstanding(Request $request): array
    {
        if (! Gate::forUser($request->user())->allows('viewAny', PayrollPeriod::class)) {
            return [];
        }

        $periods = PayrollPeriod::query()
            ->whereIn('status', array_map(
                fn (PayrollStatus $status): string => $status->value,
                array_values(array_filter(
                    PayrollStatus::cases(),
                    fn (PayrollStatus $status): bool => $status->isOpenToAccountant(),
                )),
            ))
            ->withCount('items')
            ->orderByDesc('month')
            ->limit(self::OUTSTANDING)
            ->get();

        return $periods->map(function (PayrollPeriod $period): array {
            $lines = (int) $period->items_count;

            return [
                'id' => 'payroll-'.$period->getKey(),
                'title' => $period->label(),
                'meta' => sprintf(
                    '%s · %s · %s',
                    $period->status?->label() ?? 'Unknown',
                    $lines === 1 ? '1 person' : $lines.' people',
                    $period->status === PayrollStatus::Draft
                        ? 'fill in the figures and calculate'
                        : 'waiting on the next step',
                ),
                'href' => '/payroll/'.$period->getKey(),
                // A draft nobody has calculated is the one that stops the month; the words above
                // say so too.
                'tone' => $period->status === PayrollStatus::Draft ? 'urgent' : 'default',
            ];
        })->values()->all();
    }

    /**
     * "My Leave" — Part C §1's first of the two rows this shell carries beyond finance.
     *
     * The same three numbers, from the same `LeaveService`, as the Employee dashboard's card:
     * days left of the type they have most of, how many of their own requests are waiting on a
     * decision, and whether one has been sent back for a correction. Everything here is about the
     * reader — no total across the team and no comparison with anybody (Part H §1).
     *
     * `null` for somebody with no employee record, who has no leave to have.
     *
     * @return array<string, mixed>|null
     */
    private function myLeave(?Employee $employee): ?array
    {
        if ($employee === null) {
            return null;
        }

        $byStatus = LeaveRequest::query()
            ->forEmployee($employee)
            ->groupBy('status')
            ->selectRaw('status, count(*) as total')
            ->pluck('total', 'status')
            ->all();

        return [
            'balances' => array_map(fn (array $row): array => [
                'name' => (string) $row['type']['name'],
                'days' => (int) $row['balance_days'],
            ], $this->leave->balancesFor($employee)),
            'pending' => (int) ($byStatus[LeaveStatus::Pending->value] ?? 0),
            'correction_requested' => (int) ($byStatus[LeaveStatus::CorrectionRequested->value] ?? 0),
            'href' => '/leave',
        ];
    }

    /**
     * "My Payslip" — Part C §1's second row, and **the reader's own line and nobody else's**.
     *
     * The narrowing matters more on this surface than anywhere: the Accountant holds
     * `payroll.view_others`, so `PayrollItem::scopeVisibleTo()` returns early for them and
     * `itemsFor()` is the whole company. That is right for the workbench and wrong for a card
     * called My Payslip, which is why this asks the model's own *"is this line mine?"* —
     * `belongsToEmployeeOf()`, the same predicate `PayslipController::index()` applies and the
     * same one `findItemFor()` asks before it writes an audit row. There is no
     * `where('employee_id', …)` in this file.
     *
     * The newest month, through `PayrollItemResource` — so the figure, the month's label and its
     * status are worded here exactly as they are on `/payslip`, and `admin_notes` cannot arrive
     * (the resource leaves the key absent for anybody who is not an ADMIN).
     *
     * `null` when they have no line yet, which is every month before the draft is created. The
     * card says so rather than printing a zero.
     *
     * @return array<string, mixed>|null
     */
    private function myPayslip(Request $request): ?array
    {
        $viewer = $request->user();

        if (! Gate::forUser($viewer)->allows('viewAny', PayrollItem::class)) {
            return null;
        }

        $item = $this->payroll->itemsFor($viewer)
            ->first(fn (PayrollItem $item): bool => $item->belongsToEmployeeOf($viewer));

        if ($item === null) {
            return null;
        }

        return [
            'item' => PayrollItemResource::make($item)->resolve($request),
            'currency' => (string) $this->settings->get('currency'),
            'href' => '/payslip/'.$item->getKey(),
            'index_href' => '/payslip',
        ];
    }
}
