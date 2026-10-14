<?php

namespace App\Services;

use App\Exceptions\FinanceStateException;
use App\Models\Expense;
use App\Models\FinanceCategory;
use App\Models\Income;
use App\Models\PayrollPeriod;
use App\Models\RecurringExpense;
use App\Models\User;
use App\Support\AuditEvent;
use App\Support\FinanceCategoryKind;
use App\Support\PayrollStatus;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Everything that happens to the company's books (master prompt Part D §13).
 *
 * One door in, like every other service here. The finance screens, the Admin dashboard's
 * operating-result row, `FinanceSeeder`, Phase 10's Finance report and Phase 12's migration
 * import all come through these methods, so the authorization, the category-kind rule and —
 * above all — **the audit trail** cannot be half-applied by a second path.
 *
 * ## Every write is audited, with old and new
 *
 * Part C §4 requires it for *"expense created · expense edited · finance record deleted"*, and
 * Part D §13 widens it to every Accountant edit. So each write here calls `AuditLogger` inside
 * the same transaction as the write itself: an audit row without its change, or a change
 * without its audit row, are both impossible rather than unlikely. The values on both sides are
 * the model's own `auditValues()` — the whole row, not a diff — because a diff is only readable
 * next to the row it was a diff of, and after a hard delete there is no row.
 *
 * This is the **existing** trail (`AuditLogger`, `audit_logs`), not a second one. `audit_logs`
 * is the one table `hq_app` cannot UPDATE, DELETE or TRUNCATE, which is what the next section
 * rests on.
 *
 * ## Deleting is a HARD delete, and the audit row is what makes that safe
 *
 * There is no `deleted_at` on `income` or `expenses`. The argument, in full:
 *
 *   - **A soft delete would put a `WHERE deleted_at IS NULL` in front of every total in the
 *     application.** Not one of them may ever be forgotten, because forgetting one means a
 *     month's figure silently includes a row somebody deleted — and it would agree with the
 *     ledger screen, which remembered. That is the failure this repo has already refused twice
 *     for the same reason: a second statement of a fact that somebody has to keep in step
 *     (decisions 7-3, and the overdue flag kept off `tasks`).
 *   - **A soft delete would weaken the guarantee, not strengthen it.** `deleted_at` lives on a
 *     table `hq_app` holds full DELETE and UPDATE on. `audit_logs` is the one table in this
 *     application it does not — Phase 0's migration revokes UPDATE, DELETE and TRUNCATE. So the
 *     record of a deleted income is strictly more durable in the audit log than it would be in
 *     the row itself.
 *   - **`ON DELETE RESTRICT` on the category stays meaningful.** A soft-deleted row still
 *     references its category, so the "category in use" answer would have to decide whether
 *     deleted rows count — and either answer is wrong on some screen.
 *
 * The rule that has to hold either way is that **a deleted record is fully reconstructible from
 * its audit row**, and it does: `Income::auditValues()` and `Expense::auditValues()` carry every
 * column including the id and both timestamps, plus the category's and the project's names, so
 * the row can be re-inserted verbatim even after the category has been renamed. The suite
 * proves it by re-inserting one (`FinanceServiceTest`).
 *
 * ## The locked-period block is live
 *
 * Part D §13 blocks a finance edit whose `date` falls in the month of a `payroll_periods` row
 * that is `LOCKED` or `PAID`. Phase 8 shipped `assertPeriodIsOpen()` **empty but already called
 * from every write path**, and Phase 9 filled in its body — so the block went live everywhere at
 * once, with nothing else in this file moving. That is what the seam was for.
 *
 * There are **four** call sites and they cover five directions, which is the part worth knowing
 * before touching any of them:
 *
 *   - `prepare()` checks the date **after** the write — a row created in a closed month, and a
 *     row moved INTO one;
 *   - `prepare()` checks the date **before** an edit — a row already in a closed month, and a
 *     row moved OUT of one. **Moving a record out of a locked month is the same act as changing
 *     one inside it**, and this is the check that is easy to leave out;
 *   - `deleteIncome()` and `deleteExpense()` — a row removed from a closed month.
 *
 * It throws `FinanceStateException`, which the three finance controllers already catch and turn
 * into a flash on the form. A 403 would say the Accountant may not record income, which is
 * false: they may, just not into that month. The sentence itself is `PayrollPeriod::
 * closedMonthMessage()`, next to the table that decides the rule, and it says whether the month
 * is *locked* (an Admin can reverse that) or *paid* (nobody can).
 */
class FinanceService
{
    /**
     * The attributes a create or an edit of an income row may set.
     *
     * `recorded_by` is not here: it is set from the actor in the INSERT and is never
     * transferable, so an edit form cannot reassign who entered a figure. `category_kind` is not
     * here either, and never will be — it is a constant the database maintains.
     *
     * @var list<string>
     */
    private const INCOME_FIELDS = ['category_id', 'project_id', 'amount', 'date', 'notes'];

    /**
     * The same, plus the billable flag (decision 8-17b: an expense may name a project too).
     *
     * @var list<string>
     */
    private const EXPENSE_FIELDS = ['category_id', 'project_id', 'billable', 'amount', 'date', 'notes'];

    /**
     * What a repeating expense's form may set. `created_by`, `occurrences` and `next_due_date`
     * are the service's to keep.
     *
     * @var list<string>
     */
    private const RECURRING_EXPENSE_FIELDS = ['category_id', 'project_id', 'billable', 'amount', 'frequency', 'start_date', 'ends_on', 'notes', 'active'];

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Income
    |--------------------------------------------------------------------------
    */

    /**
     * Record money in.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws AuthorizationException
     * @throws FinanceStateException
     */
    public function recordIncome(User $actor, array $attributes): Income
    {
        if (! Gate::forUser($actor)->allows('create', Income::class)) {
            throw new AuthorizationException('You are not allowed to record income.');
        }

        $values = $this->prepare($attributes, self::INCOME_FIELDS, FinanceCategoryKind::Income);

        return DB::transaction(function () use ($actor, $values): Income {
            $income = Income::create($values + ['recorded_by' => $actor->getKey()]);

            $this->audit->record(
                AuditEvent::IncomeCreated,
                $income,
                null,
                $income->fresh(['category', 'project'])->auditValues(),
                $actor,
            );

            return $income;
        });
    }

    /**
     * Change one.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws AuthorizationException
     * @throws FinanceStateException
     */
    public function updateIncome(User $actor, Income $income, array $attributes): Income
    {
        if (! Gate::forUser($actor)->allows('update', $income)) {
            throw new AuthorizationException('You are not allowed to edit income.');
        }

        $values = $this->prepare($attributes, self::INCOME_FIELDS, FinanceCategoryKind::Income, $income);

        return DB::transaction(function () use ($actor, $income, $values): Income {
            $old = $income->fresh(['category', 'project'])->auditValues();

            $income->fill($values)->save();

            $this->audit->record(
                AuditEvent::IncomeEdited,
                $income,
                $old,
                $income->fresh(['category', 'project'])->auditValues(),
                $actor,
            );

            return $income;
        });
    }

    /**
     * Remove one, for good. The audit row is what is left of it — see the class note.
     *
     * @throws AuthorizationException
     */
    public function deleteIncome(User $actor, Income $income): void
    {
        if (! Gate::forUser($actor)->allows('delete', $income)) {
            throw new AuthorizationException('You are not allowed to delete income.');
        }

        $this->assertPeriodIsOpen($income->date);

        $this->deleteRecord($actor, $income, $income->fresh(['category', 'project'])->auditValues());
    }

    /*
    |--------------------------------------------------------------------------
    | Expenses
    |--------------------------------------------------------------------------
    */

    /**
     * Record money out.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws AuthorizationException
     * @throws FinanceStateException
     */
    public function recordExpense(User $actor, array $attributes): Expense
    {
        if (! Gate::forUser($actor)->allows('create', Expense::class)) {
            throw new AuthorizationException('You are not allowed to record expenses.');
        }

        $values = $this->prepare($attributes, self::EXPENSE_FIELDS, FinanceCategoryKind::Expense);

        return DB::transaction(function () use ($actor, $values): Expense {
            $expense = Expense::create($values + ['recorded_by' => $actor->getKey()]);

            $this->audit->record(
                AuditEvent::ExpenseCreated,
                $expense,
                null,
                $expense->fresh(['category', 'project'])->auditValues(),
                $actor,
            );

            return $expense;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws AuthorizationException
     * @throws FinanceStateException
     */
    public function updateExpense(User $actor, Expense $expense, array $attributes): Expense
    {
        if (! Gate::forUser($actor)->allows('update', $expense)) {
            throw new AuthorizationException('You are not allowed to edit expenses.');
        }

        $values = $this->prepare($attributes, self::EXPENSE_FIELDS, FinanceCategoryKind::Expense, $expense);

        return DB::transaction(function () use ($actor, $expense, $values): Expense {
            $old = $expense->fresh(['category', 'project'])->auditValues();

            $expense->fill($values)->save();

            $this->audit->record(
                AuditEvent::ExpenseEdited,
                $expense,
                $old,
                $expense->fresh(['category', 'project'])->auditValues(),
                $actor,
            );

            return $expense;
        });
    }

    /**
     * @throws AuthorizationException
     */
    public function deleteExpense(User $actor, Expense $expense): void
    {
        if (! Gate::forUser($actor)->allows('delete', $expense)) {
            throw new AuthorizationException('You are not allowed to delete expenses.');
        }

        $this->assertPeriodIsOpen($expense->date);

        $this->deleteRecord($actor, $expense, $expense->fresh('category')->auditValues());
    }

    /*
    |--------------------------------------------------------------------------
    | Repeating expenses
    |--------------------------------------------------------------------------
    */

    /**
     * Create or change a repeating expense (a VPS every month, a domain every year).
     *
     * Anyone who may record an expense may set one up: the template only ever writes the rows
     * that person could have entered by hand. Changing `start_date` or `frequency`, or switching
     * a paused template back on, realigns it (`realignRecurringExpense()`) to its first due date
     * that is today or later and after the last expense it recorded, so an edit never back-fills
     * past months and never re-records a date that has already been charged.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws AuthorizationException
     * @throws FinanceStateException
     */
    public function saveRecurringExpense(User $actor, array $attributes, ?RecurringExpense $existing = null): RecurringExpense
    {
        if (! Gate::forUser($actor)->allows('create', Expense::class)) {
            throw new AuthorizationException('You are not allowed to manage repeating expenses.');
        }

        $values = array_intersect_key($attributes, array_flip(self::RECURRING_EXPENSE_FIELDS));

        if (array_key_exists('category_id', $values)) {
            $this->assertCategoryKind((int) $values['category_id'], FinanceCategoryKind::Expense);
        }

        if (array_key_exists('amount', $values)) {
            $amount = (string) $values['amount'];

            if ($this->toCents($amount) <= 0) {
                throw FinanceStateException::amountNotPositive($amount);
            }
        }

        return DB::transaction(function () use ($actor, $values, $existing): RecurringExpense {
            $old = $existing?->fresh(['category', 'project'])?->auditValues();

            if ($existing === null) {
                $template = new RecurringExpense($values);
                $template->created_by = (int) $actor->getKey();
                $template->occurrences = 0;
                $template->next_due_date = $template->start_date->copy();
            } else {
                $template = $existing;
                $template->fill($values);

                $reactivated = $template->isDirty('active')
                    && (bool) $template->active
                    && ! (bool) $template->getOriginal('active');

                if ($template->isDirty('start_date') || $template->isDirty('frequency') || $reactivated) {
                    $this->realignRecurringExpense($template);
                }
            }

            $template->save();

            $this->audit->record(
                AuditEvent::RecurringExpenseSaved,
                $template,
                $old,
                $template->fresh(['category', 'project'])->auditValues(),
                $actor,
            );

            return $template;
        });
    }

    /**
     * Point a template whose schedule changed (new frequency or start date) or which was just
     * switched back on at its first due date that is both today or later and after the last
     * expense it already recorded — so the change never back-fills past months and never
     * re-records a date that was already charged. The old `occurrences` count is not reused:
     * it was counted on the old schedule.
     */
    private function realignRecurringExpense(RecurringExpense $template): void
    {
        $today = Carbon::today(config('app.timezone'))->startOfDay();

        $lastValue = Expense::query()
            ->where('recurring_expense_id', $template->getKey())
            ->max('date');

        $last = $lastValue === null ? null : Carbon::parse($lastValue)->startOfDay();

        $n = 0;

        for ($guard = 0; $guard < 1000; $guard++, $n++) {
            $due = $template->dueDateFor($n)->startOfDay();

            if ($due->gte($today) && ($last === null || $due->gt($last))) {
                break;
            }
        }

        $template->occurrences = $n;
        $template->next_due_date = $template->dueDateFor($n);
    }

    /**
     * Remove a repeating expense. The expenses it already recorded stay: the foreign key is
     * `ON DELETE SET NULL`, because money that was spent stays spent.
     *
     * @throws AuthorizationException
     */
    public function deleteRecurringExpense(User $actor, RecurringExpense $template): void
    {
        if (! Gate::forUser($actor)->allows('create', Expense::class)) {
            throw new AuthorizationException('You are not allowed to manage repeating expenses.');
        }

        $type = $template->getMorphClass();
        $id = (int) $template->getKey();
        $old = $template->fresh(['category', 'project'])->auditValues();

        DB::transaction(function () use ($actor, $template, $type, $id, $old): void {
            $this->audit->recordFor(
                AuditEvent::RecurringExpenseDeleted,
                $type,
                $id,
                $old,
                null,
                $actor,
            );

            $template->delete();
        });
    }

    /**
     * Record every repeating expense that has fallen due on or before `$today`, catching up on
     * any days the scheduler missed. Returns how many expense rows were created.
     *
     * **Safe to run twice.** Each row is `firstOrCreate`d on `(recurring_expense_id, date)`,
     * which the database also holds as a unique index, and the template's `next_due_date` only
     * moves forward — so a second run on the same day finds nothing due and records nothing.
     *
     * **A closed month is skipped, not retried.** If the due date falls in a payroll month that
     * is locked or paid, no row is written (the same rule every hand-entered expense obeys) and
     * the template moves on to its next date; the Accountant can enter that one by hand if an
     * Admin reopens the month.
     *
     * **No drift.** The next date is always `dueDateFor(occurrences)`, counted from the start.
     */
    public function generateDueRecurringExpenses(Carbon $today): int
    {
        $today = $today->copy()->startOfDay();
        $created = 0;

        $templates = RecurringExpense::query()
            ->with(['category', 'creator'])
            ->where('active', true)
            ->whereDate('next_due_date', '<=', $today->toDateString())
            ->orderBy('id')
            ->get();

        foreach ($templates as $template) {
            while (
                $template->next_due_date->lte($today)
                && ($template->ends_on === null || $template->next_due_date->lte($template->ends_on))
            ) {
                $due = $template->next_due_date->copy();

                DB::transaction(function () use ($template, $due, &$created): void {
                    // Lock the month's payroll row (if any) before reading its status, so a
                    // month locked concurrently is respected rather than written into.
                    DB::table('payroll_periods')
                        ->whereDate('month', PayrollPeriod::monthKey($due)->toDateString())
                        ->lockForUpdate()
                        ->first();

                    $open = true;

                    try {
                        $this->assertPeriodIsOpen($due);
                    } catch (FinanceStateException) {
                        $open = false;
                    }

                    if ($open) {
                        $notes = $template->notes !== null && trim($template->notes) !== ''
                            ? $template->notes
                            : 'Repeating: '.($template->category?->name ?? 'expense');

                        $expense = Expense::firstOrCreate(
                            [
                                'recurring_expense_id' => $template->getKey(),
                                'date' => $due->toDateString(),
                            ],
                            [
                                'category_id' => $template->category_id,
                                'project_id' => $template->project_id,
                                'billable' => (bool) $template->billable,
                                'amount' => (string) $template->amount,
                                'notes' => $notes,
                                'recorded_by' => $template->created_by,
                            ],
                        );

                        if ($expense->wasRecentlyCreated) {
                            $this->audit->record(
                                AuditEvent::ExpenseCreated,
                                $expense,
                                null,
                                $expense->fresh(['category', 'project'])->auditValues(),
                                $template->creator,
                            );

                            $created++;
                        }
                    }

                    $template->occurrences = (int) $template->occurrences + 1;
                    $template->next_due_date = $template->dueDateFor($template->occurrences);
                    $template->save();
                });
            }

            if ($template->ends_on !== null && $template->next_due_date->gt($template->ends_on)) {
                $template->active = false;
                $template->save();
            }
        }

        return $created;
    }

    /*
    |--------------------------------------------------------------------------
    | Categories
    |--------------------------------------------------------------------------
    */

    /**
     * One side of the ledger's category list, in picker order.
     *
     * @return Collection<int, FinanceCategory>
     */
    public function categories(FinanceCategoryKind $kind): Collection
    {
        return FinanceCategory::query()->ofKind($kind)->inOrder()->get();
    }

    /**
     * Add a category. `settings.manage` — see `FinanceCategoryPolicy` for why that key and not
     * `finance.manage`.
     *
     * @throws AuthorizationException
     */
    public function createCategory(
        User $actor,
        FinanceCategoryKind $kind,
        string $name,
        ?int $position = null,
    ): FinanceCategory {
        if (! Gate::forUser($actor)->allows('create', FinanceCategory::class)) {
            throw new AuthorizationException('You are not allowed to change the finance categories.');
        }

        return DB::transaction(function () use ($actor, $kind, $name, $position): FinanceCategory {
            $category = FinanceCategory::create([
                'kind' => $kind,
                'name' => trim($name),
                // At the end of its own side's list, not at the end of both. A new expense
                // category must not sort after the income ones.
                'position' => $position ?? ((int) FinanceCategory::query()->ofKind($kind)->max('position') + 1),
            ]);

            $this->audit->record(
                AuditEvent::ConfigurationChanged,
                $category,
                null,
                $category->auditValues(),
                $actor,
            );

            return $category;
        });
    }

    /**
     * Rename one, or move it in the list.
     *
     * **`kind` is refused, always** — including on a category nothing is filed under. Moving a
     * category to the other side of the ledger would move every record under it with it, which
     * is not an edit anybody means to make; the database refuses it too, through `ON UPDATE
     * RESTRICT` on the composite foreign key.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws AuthorizationException
     * @throws FinanceStateException
     */
    public function updateCategory(User $actor, FinanceCategory $category, array $attributes): FinanceCategory
    {
        if (! Gate::forUser($actor)->allows('update', $category)) {
            throw new AuthorizationException('You are not allowed to change the finance categories.');
        }

        if (array_key_exists('kind', $attributes)) {
            $asked = $attributes['kind'] instanceof FinanceCategoryKind
                ? $attributes['kind']
                : FinanceCategoryKind::tryFrom((string) $attributes['kind']);

            if ($asked !== $category->kind) {
                throw FinanceStateException::categoryKindIsImmutable($category);
            }
        }

        $values = array_intersect_key($attributes, array_flip(['name', 'position']));

        if (array_key_exists('name', $values)) {
            $values['name'] = trim((string) $values['name']);
        }

        return DB::transaction(function () use ($actor, $category, $values): FinanceCategory {
            $old = $category->auditValues();

            $category->fill($values)->save();

            $this->audit->record(
                AuditEvent::ConfigurationChanged,
                $category,
                $old,
                $category->auditValues(),
                $actor,
            );

            return $category;
        });
    }

    /**
     * Remove a category nothing is filed under.
     *
     * The count is read first so the refusal is a sentence rather than a foreign-key violation
     * on a settings screen. The **promise** is `ON DELETE RESTRICT`: this check is a race with
     * any writer recording income in the same millisecond, and the database is what wins it.
     *
     * @throws AuthorizationException
     * @throws FinanceStateException
     */
    public function deleteCategory(User $actor, FinanceCategory $category): void
    {
        if (! Gate::forUser($actor)->allows('delete', $category)) {
            throw new AuthorizationException('You are not allowed to change the finance categories.');
        }

        $inUse = $category->usageCount();

        if ($inUse > 0) {
            throw FinanceStateException::categoryInUse($category, $inUse);
        }

        DB::transaction(function () use ($actor, $category): void {
            $old = $category->auditValues();

            $category->delete();

            $this->audit->record(
                AuditEvent::ConfigurationChanged,
                $category,
                $old,
                null,
                $actor,
            );
        });
    }

    /*
    |--------------------------------------------------------------------------
    | The monthly rollup
    |--------------------------------------------------------------------------
    */

    /**
     * Totals per category for one calendar month, on both sides, and the net.
     *
     * **Computed, never stored.** Part D §13 says *"Monthly totals per category auto-computed"*,
     * and the reasoning is the one this repo has now applied three times: a stored total is a
     * second statement of a fact, it can only be brought up to date by something running, and
     * the day it disagrees with the rows the two screens reading it say different things about
     * money. `income` and `expenses` therefore carry no total column and this method is a
     * `GROUP BY`.
     *
     * Part D §13's acceptance example is the shape of the answer:
     *
     * > September 2026 — Maintenance $860, SEO $800, Website $1,250 → **$2,910**
     *
     * `FinanceSeeder` makes that true in the running application, not only in the suite.
     *
     * ## Two details worth the words
     *
     *   - **A category with no rows this month is absent from its list**, not present as zero.
     *     The rollup is a report of what happened; a screen that wants every category in the
     *     list asks for the categories. Postgres would have to be told to invent the zero rows,
     *     and inventing data to make a layout easier is how a report starts lying.
     *   - **The net is computed in integer cents**, not by subtracting two floats. The totals
     *     arrive from PostgreSQL as exact decimal strings and go back out as exact decimal
     *     strings; nothing in this method is ever a float.
     *
     * ## Salaries are an expense — once the payroll month is closed
     *
     * The month's payroll (SUM of `payroll_items.net_salary` for that month's period) is added to
     * the expense side as one extra line, *Salaries (payroll)*, `category_id` 0 and `source`
     * `payroll` (every ledger line carries `source` `ledger`). It counts **only when the month's
     * payroll period is in a closing status** (`PayrollStatus::closingValues()`: locked or paid),
     * and it counts the **net** salary — what actually leaves the bank. A Draft (or any other
     * open) month is reported under `payroll` as pending — status and figure — and is never
     * counted, because those figures can still change. The expense total and the net include
     * the line automatically through `sumCents()`.
     *
     * If an expense filed under a category named *Payroll* exists in a month whose payroll is
     * counted, `payroll.double_count_warning` is true: the same salaries may be in the month twice.
     *
     * @return array{
     *     month: string,
     *     label: string,
     *     income: array{categories: list<array{category_id: int, name: string, total: string, source: string}>, total: string},
     *     expenses: array{categories: list<array{category_id: int, name: string, total: string, source: string}>, total: string},
     *     net: string,
     *     payroll: array{status: string|null, total: string, counted: bool, manual_payroll_entries: string, double_count_warning: bool},
     * }
     *
     * @throws AuthorizationException
     */
    public function monthlyRollup(User $actor, int $year, int $month): array
    {
        if (! Gate::forUser($actor)->allows('viewAny', Income::class)) {
            throw new AuthorizationException('You are not allowed to see the company finances.');
        }

        $income = $this->totalsByCategory('income', $year, $month);
        $expenses = $this->totalsByCategory('expenses', $year, $month);

        $payroll = $this->payrollForMonth($year, $month);

        if ($payroll['counted']) {
            $expenses[] = [
                'category_id' => 0,
                'name' => 'Salaries (payroll)',
                'total' => $payroll['total'],
                'source' => 'payroll',
            ];
        }

        $incomeTotal = $this->sumCents($income);
        $expenseTotal = $this->sumCents($expenses);

        return [
            'month' => sprintf('%04d-%02d', $year, $month),
            'label' => Carbon::create($year, $month, 1)->format('F Y'),
            'income' => [
                'categories' => $income,
                'total' => $this->fromCents($incomeTotal),
            ],
            'expenses' => [
                'categories' => $expenses,
                'total' => $this->fromCents($expenseTotal),
            ],
            // Part D §13's "operating result": what came in less what went out. Negative when
            // the month cost more than it earned, which is a real answer and not an error.
            'net' => $this->fromCents($incomeTotal - $expenseTotal),
            'payroll' => $payroll,
        ];
    }

    /**
     * The month's payroll as the rollup sees it.
     *
     * `total` is SUM(`payroll_items.net_salary`) for the month's period ('0.00' when there is no
     * period or no items); `counted` is whether the period's status closes the month (locked or
     * paid). `manual_payroll_entries` is what was entered by hand that month under an expense
     * category named *Payroll*, and `double_count_warning` is true when both are present.
     *
     * @return array{status: string|null, total: string, counted: bool, manual_payroll_entries: string, double_count_warning: bool}
     */
    private function payrollForMonth(int $year, int $month): array
    {
        $start = Carbon::create($year, $month, 1)->startOfDay();
        $key = PayrollPeriod::monthKey($start);

        $period = DB::table('payroll_periods as period')
            ->leftJoin('payroll_items as item', 'item.payroll_period_id', '=', 'period.id')
            ->whereDate('period.month', $key->toDateString())
            ->groupBy('period.id', 'period.status')
            ->first([
                'period.status as status',
                DB::raw('COALESCE(SUM(item.net_salary), 0) as total'),
            ]);

        $status = $period === null ? null : (string) $period->status;
        $totalCents = $period === null ? 0 : $this->toCents((string) $period->total);
        $counted = $status !== null && in_array($status, PayrollStatus::closingValues(), true);

        $manual = DB::table('expenses as record')
            ->join('finance_categories as category', 'category.id', '=', 'record.category_id')
            ->where('category.name', 'Payroll')
            ->whereBetween('record.date', [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()])
            ->value(DB::raw('COALESCE(SUM(record.amount), 0)'));

        $manualCents = $this->toCents((string) ($manual ?? '0'));

        return [
            'status' => $status,
            'total' => $this->fromCents($totalCents),
            'counted' => $counted,
            'manual_payroll_entries' => $this->fromCents($manualCents),
            'double_count_warning' => $counted && $manualCents > 0,
        ];
    }

    /**
     * `SUM(amount) GROUP BY category` for one table and one month, in picker order.
     *
     * The table name is a constant from this class, never anything a caller supplied.
     *
     * @return list<array{category_id: int, name: string, total: string, source: string}>
     */
    private function totalsByCategory(string $table, int $year, int $month): array
    {
        $start = Carbon::create($year, $month, 1)->startOfDay();

        return DB::table($table.' as record')
            ->join('finance_categories as category', 'category.id', '=', 'record.category_id')
            ->whereBetween('record.date', [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()])
            ->groupBy('category.id', 'category.name', 'category.position')
            ->orderBy('category.position')
            ->orderBy('category.name')
            ->get([
                'category.id as category_id',
                'category.name as name',
                DB::raw('SUM(record.amount) as total'),
            ])
            ->map(fn (object $row): array => [
                'category_id' => (int) $row->category_id,
                'name' => (string) $row->name,
                'total' => $this->fromCents($this->toCents((string) $row->total)),
                'source' => 'ledger',
            ])
            ->all();
    }

    /**
     * @param  list<array{category_id: int, name: string, total: string, source?: string}>  $lines
     */
    private function sumCents(array $lines): int
    {
        return array_sum(array_map(fn (array $line): int => $this->toCents($line['total']), $lines));
    }

    /**
     * A decimal string to whole cents. `round()` before the cast because `(int) (8.6 * 100)` is
     * 859 on a binary float — which is the entire reason money is never a float here.
     */
    private function toCents(string $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    private function fromCents(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    /*
    |--------------------------------------------------------------------------
    | Shared
    |--------------------------------------------------------------------------
    */

    /**
     * Narrow a caller's array to the columns this side allows, and check the two rules the
     * database also holds.
     *
     * The category rule is checked **against the kind of the table being written**, so the
     * refusal says "that is an expense category" rather than letting the composite foreign key
     * say `SQLSTATE[23503]`.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $fields
     * @return array<string, mixed>
     *
     * @throws FinanceStateException
     */
    private function prepare(
        array $attributes,
        array $fields,
        FinanceCategoryKind $kind,
        Income|Expense|null $existing = null,
    ): array {
        $values = array_intersect_key($attributes, array_flip($fields));

        if (array_key_exists('category_id', $values)) {
            $this->assertCategoryKind((int) $values['category_id'], $kind);
        }

        if (array_key_exists('amount', $values)) {
            $amount = (string) $values['amount'];

            if ($this->toCents($amount) <= 0) {
                throw FinanceStateException::amountNotPositive($amount);
            }
        }

        // The seam, on the row's date as it will be after this write — and, on an edit, on the
        // date it had before it, because moving a record OUT of a locked month is the same act
        // as changing one inside it.
        $date = array_key_exists('date', $values) ? $values['date'] : $existing?->date;

        if ($date !== null) {
            $this->assertPeriodIsOpen($date);
        }

        if ($existing?->date !== null) {
            $this->assertPeriodIsOpen($existing->date);
        }

        return $values;
    }

    /**
     * @throws FinanceStateException
     */
    private function assertCategoryKind(int $categoryId, FinanceCategoryKind $kind): void
    {
        $category = FinanceCategory::query()->findOrFail($categoryId);

        if ($category->kind !== $kind) {
            throw FinanceStateException::categoryKindMismatch($kind, $category);
        }
    }

    /**
     * Delete a finance record and write the row that is all that remains of it.
     *
     * The audit call is inside the same transaction as the DELETE, so there is no state in which
     * the money is gone and the trail is not there. `recordFor()` rather than `record()`: by the
     * time the row is written the model has no key, so the target is named explicitly.
     *
     * @param  array<string, mixed>  $old
     */
    private function deleteRecord(User $actor, Model $record, array $old): void
    {
        $type = $record->getMorphClass();
        $id = (int) $record->getKey();

        DB::transaction(function () use ($actor, $record, $old, $type, $id): void {
            $record->delete();

            $this->audit->recordFor(
                AuditEvent::FinanceRecordDeleted,
                $type,
                $id,
                $old,
                // Nothing, because there is nothing: the row is gone and `old` is the whole of
                // what it was. An audit reader can re-insert it from that alone.
                null,
                $actor,
            );
        });
    }

    /**
     * **The Phase 8 seam, filled in Phase 9.**
     *
     * Part D §13, word for word:
     *
     * > a finance record is blocked when its `date` falls in the month of a `payroll_periods`
     * > row whose status is **`LOCKED` or `PAID`**.
     *
     * Phase 8 shipped this method empty, called from every write path, so that Phase 9 could
     * fill one body and have the block go live everywhere at once with no chance of a path being
     * missed (decision 8-14). That is what this is. **Nothing else in this file moved.**
     *
     * The call sites, unchanged since Phase 8, and the five directions they cover between them:
     *
     * | call site | direction it blocks |
     * | --- | --- |
     * | `prepare()`, on `$values['date']` | **creating** a record dated in a closed month, and **moving one INTO** one |
     * | `prepare()`, on `$existing->date` | **editing** a record already dated in a closed month, and **moving one OUT of** one |
     * | `deleteIncome()` / `deleteExpense()` | **deleting** one dated in a closed month |
     *
     * The second row is the one that is easy to miss and the reason the seam took the date
     * rather than the record: moving a figure out of a locked month is the same act as changing
     * one inside it, so an edit is checked against the date it *had* as well as the date it will
     * have. Both sides — income and expenses — go through the same two methods.
     *
     * ## Why this is a `FinanceStateException`
     *
     * It is a refusal about the state of a record, not about the person: the Accountant is
     * perfectly entitled to edit income, September is simply shut. `IncomeController`,
     * `ExpenseController` and `FinanceCategoryController` each catch this type and turn it into
     * a flash error on the form it came from, which is where this refusal belongs — a 403 would
     * say the Accountant may not record income, which is false and would send them to an Admin
     * rather than to the payroll screen.
     *
     * The **sentence** comes from `PayrollPeriod::closedMonthMessage()`, in payroll's own
     * territory, because the rule and its wording are Part D §13's and belong beside the table
     * that decides them. It names the month and whether it is locked (an Admin can reverse that)
     * or already paid (nobody can), because that is what the person refused needs to do next.
     *
     * ## One indexed query, on a unique key
     *
     * `payroll_periods.month` is UNIQUE and holds the first of the month, so this is a primary
     * lookup rather than a range scan — which matters, because this method runs on **every**
     * income and expense write in the application. It reads the table directly rather than
     * through Eloquent so that a model event, a global scope or an accessor added to
     * `PayrollPeriod` later cannot change what the finance ledger considers locked.
     *
     * @throws FinanceStateException
     */
    public function assertPeriodIsOpen(mixed $date): void
    {
        if ($date === null || $date === '') {
            return;
        }

        $month = PayrollPeriod::monthKey($date instanceof CarbonInterface ? $date : (string) $date);

        $status = DB::table('payroll_periods')
            ->whereDate('month', $month->toDateString())
            ->whereIn('status', PayrollStatus::closingValues())
            ->value('status');

        if ($status === null) {
            return;
        }

        throw new FinanceStateException(
            PayrollPeriod::closedMonthMessage($month, PayrollStatus::from((string) $status)),
        );
    }
}
