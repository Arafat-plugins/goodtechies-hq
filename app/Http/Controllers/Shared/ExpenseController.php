<?php

namespace App\Http\Controllers\Shared;

use App\Exceptions\FinanceStateException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreExpenseRequest;
use App\Http\Resources\ExpenseResource;
use App\Http\Resources\FinanceCategoryResource;
use App\Models\Expense;
use App\Services\FinanceService;
use App\Services\SettingsService;
use App\Support\FinanceCategoryKind;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The expense ledger: a month of money out, and the form that writes it (master prompt Part D
 * §13, Phase 8).
 *
 * The mirror of `IncomeController`, and that class carries the whole argument: shared rather
 * than one controller per shell because the books belong to the agency and not to the shell;
 * `can:finance.view` on the group as the phase's *"Employees/Remote get 403 on every finance
 * route"*; one page component behind three GET routes with the form's state in the URL; the
 * month in `?month=YYYY-MM`; and the totals read from `FinanceService::monthlyRollup()` rather
 * than summed a second time in Vue.
 *
 * **The one difference is the project link, which does not exist here.** Part D §20 gives an
 * expense no project column: *Project Cost* is a category, not a reference (decision 8-17). So
 * there is no picker on this form, no project on the payload, and no query for one.
 */
class ExpenseController extends Controller
{
    public function __construct(
        private readonly FinanceService $finance,
        private readonly SettingsService $settings,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Expense::class);

        return $this->page($request, null, false);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('create', Expense::class);

        return $this->page($request, null, true);
    }

    public function edit(Request $request, Expense $expense): Response
    {
        Gate::authorize('update', $expense);

        return $this->page($request, $expense->load('category'), true);
    }

    public function store(StoreExpenseRequest $request): RedirectResponse
    {
        Gate::authorize('create', Expense::class);

        try {
            $expense = $this->finance->recordExpense($request->user(), $request->expenseAttributes());
        } catch (FinanceStateException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return $this->backToMonth($expense->date, sprintf(
            'Expense of %s recorded for %s.',
            $this->money($expense->amount),
            $expense->date->format('j F Y'),
        ));
    }

    public function update(StoreExpenseRequest $request, Expense $expense): RedirectResponse
    {
        Gate::authorize('update', $expense);

        try {
            $updated = $this->finance->updateExpense($request->user(), $expense, $request->expenseAttributes());
        } catch (FinanceStateException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return $this->backToMonth($updated->date, sprintf(
            'Expense of %s saved for %s.',
            $this->money($updated->amount),
            $updated->date->format('j F Y'),
        ));
    }

    /**
     * Remove one, for good — a hard delete (decision 8-5), with the whole old row written to
     * `audit_logs` inside the same transaction.
     */
    public function destroy(Request $request, Expense $expense): RedirectResponse
    {
        Gate::authorize('delete', $expense);

        $amount = $this->money($expense->amount);
        $date = $expense->date->copy();

        try {
            $this->finance->deleteExpense($request->user(), $expense);
        } catch (FinanceStateException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return $this->backToMonth($date, sprintf(
            'Expense of %s on %s was deleted. The audit log keeps the record.',
            $amount,
            $date->format('j F Y'),
        ));
    }

    /**
     * @param  Expense|null  $editing  the row the form is open on, or null to add
     * @param  bool  $formOpen  whether the form is open at all
     */
    private function page(Request $request, ?Expense $editing, bool $formOpen): Response
    {
        $month = $this->month($request, $editing?->date);
        $user = $request->user();

        $records = Expense::query()
            ->with('category')
            ->inMonth($month->year, $month->month)
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get();

        $rollup = $this->finance->monthlyRollup($user, $month->year, $month->month);

        return Inertia::render('Shared/Finance/Expenses', [
            'month' => $this->monthPayload($month),
            'records' => ExpenseResource::collection($records)->resolve($request),
            'totals' => $rollup['expenses'],
            'categories' => FinanceCategoryResource::collection(
                $this->finance->categories(FinanceCategoryKind::Expense)
            )->resolve($request),

            'form' => $formOpen
                ? [
                    'mode' => $editing === null ? 'create' : 'edit',
                    'record' => $editing === null
                        ? null
                        : (new ExpenseResource($editing))->resolve($request),
                ]
                : null,

            // Today in the agency's timezone, not the browser's: it is the date a new
            // record defaults to, and a laptop set to another zone would otherwise file a
            // payment into the wrong month for six hours a day.
            'today' => Carbon::today(config('app.timezone'))->toDateString(),

            'currency' => (string) $this->settings->get('currency'),

            'permissions' => [
                'can_create' => $user !== null && Gate::forUser($user)->allows('create', Expense::class),
            ],
        ]);
    }

    /**
     * `?month=YYYY-MM`, or the record's month, or this one. See `IncomeController::month()`.
     */
    private function month(Request $request, ?Carbon $fallback = null): Carbon
    {
        $value = trim((string) $request->query('month', ''));

        if ($value !== '') {
            try {
                return Carbon::createFromFormat('Y-m', $value)->startOfMonth();
            } catch (\Throwable) {
                // fall through
            }
        }

        return ($fallback?->copy() ?? Carbon::today(config('app.timezone')))->startOfMonth();
    }

    /**
     * @return array<string, string>
     */
    private function monthPayload(Carbon $month): array
    {
        return [
            'value' => $month->format('Y-m'),
            'label' => $month->isoFormat('MMMM YYYY'),
            'previous' => $month->copy()->subMonthNoOverflow()->format('Y-m'),
            'next' => $month->copy()->addMonthNoOverflow()->format('Y-m'),
        ];
    }

    private function backToMonth(Carbon $date, string $message): RedirectResponse
    {
        return redirect()
            ->route('finance.expenses.index', ['month' => $date->format('Y-m')])
            ->with('success', $message);
    }

    private function money(string $amount): string
    {
        return $this->formatMoney($amount, (string) $this->settings->get('currency'));
    }

    /**
     * The server's one money formatter, and it is deliberately the same SHAPE as the browser's
     * (`Components/Finance/finance.ts`): the currency's symbol, grouped thousands, always two
     * decimals. A flash that said "USD 123.45" beside a table that said "$123.45" would be two
     * renderings of the same figure on one screen, which is the thing this slice spent its whole
     * design on not doing.
     *
     * `ext-intl` is installed by `deploy/install.sh` and present in development, but it is not a
     * declared requirement in `composer.json`, so an unknown code or a build without the
     * extension falls back to the ISO code and the grouped number rather than fataling inside a
     * success message.
     */
    private function formatMoney(string $amount, string $currency): string
    {
        if (class_exists(\NumberFormatter::class)) {
            $formatted = (new \NumberFormatter('en_US', \NumberFormatter::CURRENCY))
                ->formatCurrency((float) $amount, $currency);

            if (is_string($formatted)) {
                return $formatted;
            }
        }

        return sprintf('%s %s', $currency, number_format((float) $amount, 2));
    }
}
