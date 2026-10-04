<?php

namespace App\Http\Controllers\Shared;

use App\Exceptions\PayrollStateException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payroll\AdjustPayrollItemRequest;
use App\Http\Requests\Payroll\ReverseLockRequest;
use App\Http\Resources\PayrollItemResource;
use App\Http\Resources\PayrollPeriodResource;
use App\Models\PayrollItem;
use App\Models\PayrollPeriod;
use App\Services\PayrollService;
use App\Services\SettingsService;
use App\Support\PayrollStatus;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * **The payroll workbench**: the month list, the month itself, and every move the month can make
 * (master prompt Part D §14, Phase 9).
 *
 * ## Why this is SHARED and not one controller per shell
 *
 * The answer this repo has now reached six times — notifications, attendance, leave, messages,
 * meetings, finance: **whose pay this is belongs to the agency, not to the shell somebody is
 * looking at.** Part E's Phase 9 heading splits the verbs across two surfaces (*"Accountant →
 * Payroll: … Calculate, submit for review. Admin → Payroll: Review, Approve, Lock, Reverse
 * lock, Mark paid"*) and not the screens: an Admin reading September and the Accountant reading
 * September are the same nine rows, asked the same way, showing the same figures. Two copies of
 * these routes would be two places for *"may this person lock a month"* to be answered
 * differently, and both of them would be about money.
 *
 * `Pages/Shared/Payroll/{Index,Show}.vue` pick `AdminLayout` or `AccountantLayout` from
 * `auth.user.surface`, exactly as `Pages/Shared/Messages.vue` does, so the Accountant shell
 * still imports nothing from `Layouts/AdminLayout.vue` or `Pages/Admin/`.
 *
 * **`can:payroll.draft` on the route group** is Phase 9's security line. ADMIN and ACCOUNTANT
 * hold that key and nobody else does (`RolePermissionSeeder`), so an Employee, a Remote
 * employee and a Manager are refused **403** before a controller method runs — by the absence
 * of a key rather than by being named, which is also what would let a future senior bookkeeper
 * get these screens by being granted the key and nothing else.
 *
 * ## 403 for a period, 404 for an item — and the difference is the privacy rule
 *
 * A **period** is a month. Everybody through the gate above may see every month, so a refusal
 * on a period is an honest *you may not do this*: **403**. There is nothing to conceal, because
 * knowing that September has a payroll period tells you nothing about anybody's pay.
 *
 * An **item** is one person's salary, and that is Part B §3 rule 1's territory: *"a record they
 * may not see is omitted from lists and returns 404 by id"*. So no item is ever resolved by
 * route-model binding here. Every one goes through `PayrollService::findItemFor()`, whose
 * `ModelNotFoundException` **is** the privacy rule — the 404 is what the scoped query returns
 * rather than something this controller decides, and the audit row for the attempt is written
 * in the same method (decisions 9-8, 9-9). This class does not re-implement any of it and must
 * never grow a branch that could answer 403 for an item.
 *
 * ## Two kinds of refusal, and neither is a silent no-op
 *
 *   - **The person:** `AuthorizationException`, from `Gate::authorize()` here or from
 *     `PayrollService` — 403, with the service's own sentence.
 *   - **The month's state:** `PayrollStateException`, caught below and flashed back as an
 *     error. Every sentence names the status the period is actually in and what follows from
 *     it, because *"I pressed Approve and nothing happened"* is the bug report a quiet no-op
 *     produces and it cannot be debugged from.
 *
 * The screen only renders a control the payload says this viewer may press in this state
 * (`PayrollPeriodResource::permissions` + `available_transitions` + `allows_calculation`), so
 * in ordinary use neither refusal is reachable. They exist because a stale tab, a second
 * Admin's press half a second earlier, and a hand-made request are all real.
 */
class PayrollController extends Controller
{
    public function __construct(
        private readonly PayrollService $payroll,
        private readonly SettingsService $settings,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Reading
    |--------------------------------------------------------------------------
    */

    /**
     * **Every month, newest first** — what state it is in, how many people are on it, what it
     * comes to, and what this viewer may do to it next.
     *
     * `withCount` and `withSum` are two aggregates in the one query rather than a `SUM()` per
     * row: `net_salary` is a stored generated column, so summing it is a plain index-free scan
     * of a table with one row per employee per month, and the figure is **the database's** —
     * nothing in PHP and nothing in Vue adds a column of pay up (decision 8-10's rule, applied
     * to payroll).
     *
     * There is no pagination and no filter bar. A month is a row; the agency will have twelve a
     * year, and a control the controller ignores is a lie in the UI (decisions 0.5-16, 0.5-19).
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', PayrollPeriod::class);

        $user = $request->user();

        $periods = PayrollPeriod::query()
            ->withCount('items')
            ->withSum('items', 'net_salary')
            ->with('lockReverser')
            ->orderByDesc('month')
            ->get();

        $thisMonth = PayrollPeriod::monthKey(Carbon::today(config('app.timezone')));

        return Inertia::render('Shared/Payroll/Index', [
            'periods' => PayrollPeriodResource::collection($periods)->resolve($request),

            // The month a Create-draft control would be for, and whether it already has one.
            // Sent as a fact rather than worked out in Vue from a list of dates, because
            // "which month is it" is the agency's timezone's answer and not the browser's — a
            // laptop set to another zone would otherwise offer to draft October on the 30th of
            // September for six hours a day.
            'current_month' => [
                'value' => $thisMonth->format('Y-m'),
                'label' => $thisMonth->isoFormat('MMMM YYYY'),
                'has_period' => $periods->contains(
                    fn (PayrollPeriod $period): bool => PayrollPeriod::monthKey($period->month)->equalTo($thisMonth),
                ),
            ],

            'permissions' => [
                'can_create' => $user !== null && Gate::forUser($user)->allows('create', PayrollPeriod::class),
            ],

            'currency' => (string) $this->settings->get('currency'),
        ]);
    }

    /**
     * **One month, line by line.** The heart of the phase.
     *
     * The rows come through `PayrollService::itemQuery()` — the scoped query, not a raw
     * relation — so that the one statement of *who may see whose line* serves this screen as
     * well as My Payslip. For an Admin or the Accountant it returns every line, which is what
     * `payroll.view_others` means; the scope is asked anyway, so a role that lost the key would
     * lose the rows rather than keep them because this controller assumed the gate implied it.
     *
     * ## `leave` is a separate prop, and deliberately not a key on the item
     *
     * *"You were docked $90.91"* is the sentence that generates the support ticket, so the
     * screen has to be able to say **how many unpaid days, out of how many payable days**.
     * Those two numbers come from `PayrollService::unpaidDaysIn()` and `payableDaysIn()` — the
     * same two methods the calculation itself divides with, so the arithmetic on the screen is
     * the arithmetic that was performed and not a second implementation of it.
     *
     * They are sent as a **map keyed by item id** rather than folded into
     * `PayrollItemResource`, because that payload's key set is pinned to eleven keys by an
     * exact-key assertion plus a recursive forbidden-key walk (decisions 9-10, 9-11), and it is
     * also the payslip's payload. Widening a privacy-critical serializer to carry a display aid
     * for one screen is the wrong direction; a prop beside it costs nothing and cannot leak,
     * because it holds two day counts and no money.
     *
     * Two queries per employee is the honest cost of the explanation, and this is a page one
     * person opens a few times a month with one row per member of staff.
     */
    public function show(Request $request, PayrollPeriod $period): Response
    {
        Gate::authorize('view', $period);

        $items = $this->payroll->itemQuery($request->user(), $period)
            ->get()
            // A payroll sheet is read by name, not by employee id. PHP's sort is stable, so
            // two people with the same name keep the query's `employee_id` order.
            ->sortBy(fn (PayrollItem $item): string => mb_strtolower((string) ($item->employee?->user?->name ?? '')))
            ->values();

        $period->loadCount('items');
        $period->loadSum('items', 'net_salary');
        $period->load('lockReverser');

        return Inertia::render('Shared/Payroll/Show', [
            'period' => (new PayrollPeriodResource($period))->resolve($request),

            // **The top bar's last crumb.** `lib/breadcrumb.ts` names a record the URL only
            // identifies by an id by looking through the page props for exactly one shaped
            // `{ data: { name } }` — the shape `ClientResource` and `ProjectResource` wrap
            // themselves in — and falls back to `#1` when it finds none. A payroll period is
            // named by its month and `PayrollPeriodResource` is not wrapped, so the crumb is
            // handed over on its own rather than by reshaping a payload three screens read.
            // Deliberately the ONLY prop here in that shape: two would be ambiguous and the
            // helper would fall back to the id again.
            'crumb' => ['data' => ['name' => $period->label()]],

            'items' => PayrollItemResource::collection($items)->resolve($request),

            // What each line's leave impact is MADE OF. See the method note.
            'leave' => $items
                ->mapWithKeys(fn (PayrollItem $item): array => [
                    (string) $item->getKey() => $item->employee === null
                        ? ['unpaid_days' => 0, 'payable_days' => 0]
                        : [
                            'unpaid_days' => $this->payroll->unpaidDaysIn($item->employee, $period),
                            'payable_days' => $this->payroll->payableDaysIn($item->employee, $period),
                        ],
                ])
                ->all(),

            // The six statuses in order, with their labels and `StatusBadge` tones, so the
            // progress spine is drawn from the server's map and no Vue computed holds a second
            // copy of it (decision 2-37).
            'statuses' => array_map(
                fn (PayrollStatus $status): array => [
                    'value' => $status->value,
                    'label' => $status->label(),
                    'state' => $status->tone(),
                ],
                // Polish 002: Locked is no longer a step anybody presses, so the spine skips it.
                array_values(array_filter(
                    PayrollStatus::cases(),
                    fn (PayrollStatus $status): bool => $status !== PayrollStatus::Locked,
                )),
            ),

            'currency' => (string) $this->settings->get('currency'),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Writing — the figures
    |--------------------------------------------------------------------------
    */

    /**
     * Draft this month by hand.
     *
     * `hq:create-payroll-draft` does this on the 1st with no actor at all, which is the ordinary
     * path; this is the control for a month that missed it — a fresh install, a VPS that was
     * down on the 1st, or somebody looking at an empty list on the 12th and wondering whether
     * the screen is broken. `createDraft()` is passed `$onlyIfMissing = false` deliberately, so
     * a month that already has a period answers a **sentence** rather than silently doing
     * nothing (decision 9-6 and 9-7: a period is never topped up).
     *
     * The month is always *this* month in the agency's timezone. There is no date picker,
     * because drafting an arbitrary past month is a different act with different consequences —
     * it would reach into `employee_salaries` history for a month somebody may already have
     * balanced — and Part D §14 does not ask for one.
     */
    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', PayrollPeriod::class);

        $month = Carbon::today(config('app.timezone'))->startOfMonth();

        try {
            $period = $this->payroll->createDraft($request->user(), $month);
        } catch (PayrollStateException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $missing = $this->payroll->employeesWithoutSalaryAt($month);

        $message = sprintf(
            '%s is drafted with %s.',
            $period->label(),
            $this->lines((int) $period->items()->count()),
        );

        // Somebody with no salary on record is SKIPPED, not drafted at zero — a zero payslip is
        // a statement, a missing line is a question (PayrollService::draftMissingItems()). The
        // question has to reach the person who can answer it, so it is named here rather than
        // left for them to notice a line missing.
        if ($missing !== []) {
            $message .= sprintf(
                ' %s has no salary on record and is not on it — set a salary, then draft the missing lines.',
                $this->andList($missing),
            );
        }

        return redirect()
            ->route('payroll.show', $period)
            ->with($missing === [] ? 'success' : 'error', $message);
    }

    /**
     * **Change one line**: the five figures the Accountant owns, and — for an Admin — the
     * personal notes beside them.
     *
     * ## The order of the three checks is the point of this method
     *
     *   1. The item is resolved through the scope, so somebody else's line is **404**.
     *   2. It is checked to be **on this period**, so `/payroll/9/items/{a line of October}`
     *      is 404 rather than an edit to a month the URL does not name. Two people working two
     *      months in two tabs is the ordinary case, and an id pasted from the wrong one must
     *      not quietly write to the right-looking screen.
     *   3. If notes were sent, `annotate` is asked **before anything is written**. So an
     *      Accountant who hand-posts `admin_notes` gets a 403 and changes nothing at all —
     *      rather than a 403 that lands after their five figures have already been saved.
     *
     * Then the figures, then the notes. `adjustItem()` refreshes the model after the write,
     * because `net_salary` is computed by PostgreSQL and the in-memory copy is stale the moment
     * a component changes (decision 9-3).
     */
    public function updateItem(
        AdjustPayrollItemRequest $request,
        PayrollPeriod $period,
        int $item,
    ): RedirectResponse {
        $record = $this->findItem($request, $period, $item);

        // Asked here and not only inside `annotate()`, so that nothing is written when the
        // answer is no. ADMIN only — Part D §14's "the column the Accountant never receives".
        if ($request->changesNotes() && ! Gate::forUser($request->user())->allows('annotate', $record)) {
            abort(403, 'Only an Admin can write personal notes on a payroll item.');
        }

        try {
            if ($request->changesFigures()) {
                $record = $this->payroll->adjustItem($request->user(), $record, $request->figures());
            }

            if ($request->changesNotes()) {
                $record = $this->payroll->annotate($request->user(), $record, $request->notes());
            }
        } catch (PayrollStateException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        // **No figure in this sentence, deliberately.** The row behind the dialog has already
        // re-rendered with the new net, formatted by the one function in the browser that
        // formats money — and the server's own formatter is a private copy in each of the two
        // finance controllers, which decision 8-31 already records as wanting one home. A third
        // copy here, printing `$1,150.00` in a flash over a table that printed it too, is
        // exactly the defect 8-31 is about. The confirmation names the line and says who
        // computed the net; the screen shows what it came to.
        return back()->with('success', sprintf(
            '%s — saved. The net on that line was recalculated by the database from the figures beside it.',
            $record->employee?->user?->name ?? 'This line',
        ));
    }

    /*
    |--------------------------------------------------------------------------
    | Writing — the state machine
    |--------------------------------------------------------------------------
    */

    /**
     * **Calculate.** Recompute every line's leave impact from approved unpaid leave, and move a
     * draft on.
     *
     * Pressing it twice gives the same answer — it computes from `leave_requests` rather than
     * accumulating onto what is there — so it is never disabled after a run. The flash says so
     * in words instead, because a greyed-out Calculate would make the one safe, repeatable
     * button on this screen look like a spent one.
     */
    public function calculate(Request $request, PayrollPeriod $period): RedirectResponse
    {
        return $this->transition($request, $period, 'calculate', function () use ($request, $period): string {
            $before = $period->status;
            $after = $this->payroll->calculate($request->user(), $period);

            return sprintf(
                '%s: leave impact recalculated across %s.%s Pressing Calculate again gives the same answer.',
                $after->label(),
                $this->lines((int) $after->items()->count()),
                $before === PayrollStatus::Draft ? ' The month is now Calculated.' : '',
            );
        });
    }

    /** Submit the calculated month for approval. Part D §14's *"Admin reviews"*. */
    public function review(Request $request, PayrollPeriod $period): RedirectResponse
    {
        return $this->transition($request, $period, 'review', function () use ($request, $period): string {
            $after = $this->payroll->review($request->user(), $period);

            return sprintf('%s is marked Reviewed. The Accountant can still correct a figure until it is approved.', $after->label());
        });
    }

    /**
     * Approve the month — and write the `payroll.approved` audit row Part C §4 names, which the
     * service does inside the same transaction as the move.
     */
    public function approve(Request $request, PayrollPeriod $period): RedirectResponse
    {
        return $this->transition($request, $period, 'approve', function () use ($request, $period): string {
            $after = $this->payroll->approve($request->user(), $period);

            return sprintf('%s is approved. Its figures are now read-only to the Accountant.', $after->label());
        });
    }

    /**
     * **Close the month.** The widest-blast-radius verb in the application: from here every
     * income and expense dated in it is refused — created, edited, moved in, moved out or
     * deleted (Part D §13, `FinanceService::assertPeriodIsOpen()`).
     */
    public function lock(Request $request, PayrollPeriod $period): RedirectResponse
    {
        return $this->transition($request, $period, 'lock', function () use ($request, $period): string {
            $after = $this->payroll->lock($request->user(), $period);

            return sprintf(
                '%s is locked. Income and expenses dated in it can no longer be recorded, edited, moved or deleted until an Admin reverses the lock.',
                $after->label(),
            );
        });
    }

    /**
     * **Reverse the lock. ADMIN only, and the reason travels.**
     *
     * The request has already refused a blank reason with a 422 on the field; the service
     * refuses one again, and the database refuses a reversal with no reason as a row. The
     * reason is written onto the period *and* into `audit_logs` with the actor — the row is the
     * most recent reversal, the log is every reversal there has ever been, in the one table
     * `hq_app` can neither UPDATE nor DELETE.
     */
    public function reverseLock(ReverseLockRequest $request, PayrollPeriod $period): RedirectResponse
    {
        return $this->transition($request, $period, 'reverseLock', function () use ($request, $period): string {
            $after = $this->payroll->reverseLock($request->user(), $period, $request->reason());

            return sprintf(
                'The lock on %s is reversed and the month is back to Approved. Income and expenses dated in it can be changed again. Your reason is on the period and in the audit log.',
                $after->label(),
            );
        });
    }

    /**
     * **Mark the month paid.** Terminal: `PayrollStatus::TRANSITIONS['paid']` is empty, and a
     * paid month closes the finance ledger with no way back (decision 9-17, which is a question
     * for the client precisely because the consequence is this large).
     */
    public function markPaid(Request $request, PayrollPeriod $period): RedirectResponse
    {
        return $this->transition($request, $period, 'markPaid', function () use ($request, $period): string {
            $after = $this->payroll->markPaid($request->user(), $period);

            return sprintf(
                '%s is marked paid. The payslips are released, and the month is closed to income and expenses for good.',
                $after->label(),
            );
        });
    }

    /*
    |--------------------------------------------------------------------------
    | The shared shape
    |--------------------------------------------------------------------------
    */

    /**
     * One transition: ask the policy, run it, flash the sentence — and turn a state refusal
     * into a flash rather than into a status code.
     *
     * The policy is asked **here as well as** inside `PayrollService`, and that is not
     * belt-and-braces theatre: it is what makes the refusal a 403 on the route rather than an
     * exception surfacing from three frames down, and it keeps this controller's answer to
     * *"who may lock"* the same object the payload's `permissions` block was built from.
     *
     * @param  callable(): string  $run  performs the move and returns the sentence for it
     */
    private function transition(
        Request $request,
        PayrollPeriod $period,
        string $ability,
        callable $run,
    ): RedirectResponse {
        Gate::authorize($ability, $period);

        try {
            $message = $run();
        } catch (PayrollStateException $exception) {
            // The month is not in a state for this. A sentence naming the status it IS in, on
            // the screen it was pressed from — never a silent no-op and never a 403, because
            // the person is perfectly entitled to press it.
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', $message);
    }

    /**
     * One item by id, **for this viewer, on this period**.
     *
     * Both halves answer 404 and neither answers 403 — see the class note. The lookup itself is
     * `PayrollService::findItemFor()`, which is also where the `restricted.access_attempt`
     * audit row is written; this method does not reimplement any of that and must not.
     */
    private function findItem(Request $request, PayrollPeriod $period, int $id): PayrollItem
    {
        try {
            $item = $this->payroll->findItemFor($request->user(), $id);
        } catch (ModelNotFoundException) {
            abort(404);
        }

        if ((int) $item->payroll_period_id !== (int) $period->getKey()) {
            abort(404);
        }

        return $item;
    }

    /** "9 lines" / "1 line" — the count in a sentence, pluralised once. */
    private function lines(int $count): string
    {
        return $count.($count === 1 ? ' line' : ' lines');
    }

    /**
     * "Tapu", "Tapu and Yaseen", "Tapu, Yaseen and Faruk".
     *
     * @param  list<string>  $names
     */
    private function andList(array $names): string
    {
        if (count($names) === 1) {
            return $names[0];
        }

        $last = array_pop($names);

        return implode(', ', $names).' and '.$last;
    }
}
