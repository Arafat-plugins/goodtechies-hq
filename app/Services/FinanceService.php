<?php

namespace App\Services;

use App\Exceptions\FinanceStateException;
use App\Models\Expense;
use App\Models\FinanceCategory;
use App\Models\Income;
use App\Models\PayrollPeriod;
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
     * The same, minus the project link Part D §20 does not give an expense.
     *
     * @var list<string>
     */
    private const EXPENSE_FIELDS = ['category_id', 'amount', 'date', 'notes'];

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
                $expense->fresh('category')->auditValues(),
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
            $old = $expense->fresh('category')->auditValues();

            $expense->fill($values)->save();

            $this->audit->record(
                AuditEvent::ExpenseEdited,
                $expense,
                $old,
                $expense->fresh('category')->auditValues(),
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
     * @return array{
     *     month: string,
     *     label: string,
     *     income: array{categories: list<array{category_id: int, name: string, total: string}>, total: string},
     *     expenses: array{categories: list<array{category_id: int, name: string, total: string}>, total: string},
     *     net: string,
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
        ];
    }

    /**
     * `SUM(amount) GROUP BY category` for one table and one month, in picker order.
     *
     * The table name is a constant from this class, never anything a caller supplied.
     *
     * @return list<array{category_id: int, name: string, total: string}>
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
            ])
            ->all();
    }

    /**
     * @param  list<array{category_id: int, name: string, total: string}>  $lines
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
    private function assertPeriodIsOpen(mixed $date): void
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
