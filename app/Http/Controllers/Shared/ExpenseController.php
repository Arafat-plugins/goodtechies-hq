<?php

namespace App\Http\Controllers\Shared;

use App\Exceptions\FinanceStateException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreExpenseRequest;
use App\Http\Resources\ExpenseResource;
use App\Http\Resources\FinanceCategoryResource;
use App\Http\Resources\IncomeResource;
use App\Models\Expense;
use App\Models\Project;
use App\Models\RecurringExpense;
use App\Services\FinanceService;
use App\Services\SettingsService;
use App\Support\Currency;
use App\Support\FinanceCategoryKind;
use Illuminate\Database\Eloquent\Builder;
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
 * Like income, an expense may name a project and, on this side only, be flagged billable to
 * the client (decision 8-17b, superseding 8-17). The project picker is the same list income's
 * form reads, from the same query and the same `IncomeResource::projectPayload()`.
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

        return $this->page($request, $expense->load(['category', 'project']), true);
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
            $this->money($expense->amount, (string) $expense->currency),
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
            $this->money($updated->amount, (string) $updated->currency),
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

        $amount = $this->money($expense->amount, (string) $expense->currency);
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

        $categories = $this->finance->categories(FinanceCategoryKind::Expense);
        $filters = $this->filters($request, $categories->map(fn ($category): int => (int) $category->getKey())->all());

        $query = Expense::query()
            ->with(['category', 'project'])
            ->inMonth($month->year, $month->month)
            ->orderByDesc('date')
            ->orderByDesc('id');

        $this->applyFilters($query, $filters);

        $records = $query->get();

        $rollup = $this->finance->monthlyRollup($user, $month->year, $month->month);

        $canCreate = $user !== null && Gate::forUser($user)->allows('create', Expense::class);
        $recurring = $canCreate ? $this->recurring() : [];

        return Inertia::render('Shared/Finance/Expenses', [
            'month' => $this->monthPayload($month),
            'records' => ExpenseResource::collection($records)->resolve($request),

            // The month's totals, one entry per currency: never added together.
            'totals_by_currency' => array_map(
                fn (array $entry): array => ['currency' => $entry['currency']] + $entry['expenses'],
                $rollup['by_currency'],
            ),

            'filters' => $filters,
            'currencies' => Currency::options(),
            'defaultCurrency' => Currency::default()->value,
            'categories' => FinanceCategoryResource::collection($categories)->resolve($request),

            // Always sent: the form's picker, the repeating-expenses card and the list's project
            // filter all use it.
            'projects' => $this->projects(),

            // The repeating expenses (VPS, domain, hosting), only to those who may manage them.
            'recurring' => $recurring,

            // How many of them are due and waiting to be recorded by hand.
            'dueRecurring' => $canCreate
                ? count(array_filter($recurring, fn (array $template): bool => $template['is_due']))
                : 0,

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
                'can_create' => $canCreate,
            ],
        ]);
    }

    /**
     * The expense form's project picker: `IncomeController::projects()`'s query and payload,
     * deliberately the same — every project, archived included, by name, not scoped by
     * `Project::visibleTo()` (decision 8-9).
     *
     * @return list<array{id: int, name: string, domain: string|null}>
     */
    private function projects(): array
    {
        return Project::query()
            ->orderBy('name')
            ->get()
            ->map(fn (Project $project): array => IncomeResource::projectPayload($project))
            ->all();
    }

    /**
     * Every repeating expense, running ones first, then by the next date due.
     *
     * @return list<array<string, mixed>>
     */
    private function recurring(): array
    {
        $today = Carbon::today(config('app.timezone'));

        return RecurringExpense::query()
            ->with(['category', 'project'])
            ->orderByDesc('active')
            ->orderBy('next_due_date')
            ->orderBy('id')
            ->get()
            ->map(fn (RecurringExpense $template): array => [
                'id' => (int) $template->getKey(),
                'category' => $template->category === null
                    ? null
                    : ['id' => (int) $template->category->getKey(), 'name' => (string) $template->category->name],
                'project' => $template->project === null
                    ? null
                    : IncomeResource::projectPayload($template->project),
                'billable' => (bool) $template->billable,
                'amount' => (string) $template->amount,
                'currency' => (string) $template->currency,
                'frequency' => $template->frequency,
                'frequency_label' => match ($template->frequency) {
                    'quarterly' => 'Quarterly',
                    'yearly' => 'Yearly',
                    default => 'Monthly',
                },
                'start_date' => $template->start_date->toDateString(),
                'ends_on' => $template->ends_on?->toDateString(),
                'next_due_date' => $template->next_due_date->toDateString(),
                'notes' => $template->notes,
                'active' => (bool) $template->active,
                'is_due' => (bool) $template->active
                    && $template->next_due_date->lte($today)
                    && ($template->ends_on === null || $template->next_due_date->lte($template->ends_on)),
            ])
            ->all();
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

    private function money(string $amount, string $currency): string
    {
        return $this->formatMoney($amount, $currency);
    }

    /**
     * The list filters from the query string: `category` (an id on this side of the ledger),
     * `currency` (BDT or USD), `project` (an id, or `none` for "not linked") and `q` (a search
     * of the notes). Anything that does not parse is ignored rather than refused — they are view
     * parameters, like the month.
     *
     * @param  list<int>  $categoryIds
     * @return array{category: int|null, currency: string|null, project: int|string|null, q: string}
     */
    private function filters(Request $request, array $categoryIds): array
    {
        $category = filter_var($request->query('category'), FILTER_VALIDATE_INT);
        $currency = strtoupper(trim((string) $request->query('currency', '')));
        $project = trim((string) $request->query('project', ''));
        $projectId = filter_var($project, FILTER_VALIDATE_INT);

        return [
            'category' => $category !== false && in_array($category, $categoryIds, true) ? $category : null,
            'currency' => in_array($currency, Currency::values(), true) ? $currency : null,
            'project' => $project === 'none' ? 'none' : ($projectId !== false && $projectId > 0 ? $projectId : null),
            'q' => mb_substr(trim((string) $request->query('q', '')), 0, 100),
        ];
    }

    /**
     * @param  Builder<Expense>  $query
     * @param  array{category: int|null, currency: string|null, project: int|string|null, q: string}  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if ($filters['category'] !== null) {
            $query->where('category_id', $filters['category']);
        }

        if ($filters['currency'] !== null) {
            $query->where('currency', $filters['currency']);
        }

        if ($filters['project'] === 'none') {
            $query->whereNull('project_id');
        } elseif ($filters['project'] !== null) {
            $query->where('project_id', $filters['project']);
        }

        if ($filters['q'] !== '') {
            $query->where('notes', 'ilike', '%'.addcslashes($filters['q'], '%_\\').'%');
        }
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
