<?php

use App\Exceptions\FinanceStateException;
use App\Models\AuditLog;
use App\Models\Expense;
use App\Models\FinanceCategory;
use App\Models\Income;
use App\Models\Project;
use App\Models\User;
use App\Services\FinanceService;
use App\Support\AuditEvent;
use App\Support\FinanceCategoryKind;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| FinanceService — the one door into the company's books
|--------------------------------------------------------------------------
|
| Master prompt Part D §13, Part C §1 and Part C §4. Four subjects:
|
|   1. CRUD per role — ADMIN and ACCOUNTANT yes, EMPLOYEE and REMOTE no.
|   2. The audit trail, with OLD and NEW on every write, and the one rule a
|      hard delete has to satisfy: the deleted row is reconstructible from
|      its audit row.
|   3. The rules that live in the DATABASE as well as in PHP — the category
|      kind, the amount's sign, a category in use.
|   4. The optional project link, and the deliberate fact that a project the
|      Accountant cannot see through any ordinary route is still linkable.
|
| Constants and helpers are prefixed FINANCE_ / finance*, because Pest
| declares both globally across the whole suite (AGENTS.md).
|
*/

/** @return int the id of a seeded category, by side and name */
function financeCategoryId(FinanceCategoryKind $kind, string $name): int
{
    return (int) FinanceCategory::query()->ofKind($kind)->where('name', $name)->firstOrFail()->getKey();
}

/** @return AuditLog the newest audit row for this event */
function financeLatestAudit(AuditEvent $event): AuditLog
{
    return AuditLog::where('event', $event->value)->orderByDesc('id')->firstOrFail();
}

/**
 * An audit row's JSON comes back from `jsonb` in whatever order PostgreSQL stored it — jsonb
 * normalises object keys and does not keep the order they were written in. The keys and the
 * values are the fact under test; the order is not.
 *
 * @param  array<string, mixed>  $values
 * @return array<string, mixed>
 */
function financeByKey(array $values): array
{
    ksort($values);

    return $values;
}

/**
 * Prove the DATABASE refuses a statement, without poisoning the test's own transaction.
 *
 * A failed statement puts the PostgreSQL transaction into an aborted state in which every
 * subsequent query raises 25P02 — so a test that probes a constraint and then goes on to assert
 * anything else needs a savepoint to roll back to. `AuditLogAppendOnlyTest` does the same thing
 * for the same reason.
 */
function financeDatabaseRefuses(callable $statement, string $constraint): void
{
    DB::statement('SAVEPOINT finance_constraint_probe');

    try {
        $statement();

        DB::statement('RELEASE SAVEPOINT finance_constraint_probe');

        throw new RuntimeException("the database accepted a statement [{$constraint}] should have refused");
    } catch (QueryException $exception) {
        DB::statement('ROLLBACK TO SAVEPOINT finance_constraint_probe');

        expect($exception->getMessage())->toContain($constraint);
    }
}

beforeEach(function () {
    $this->seed();

    $this->service = app(FinanceService::class);

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();
    $this->employee = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->remote = User::where('email', 'tapu@goodtechies.test')->firstOrFail();

    $this->maintenance = financeCategoryId(FinanceCategoryKind::Income, 'Maintenance');
    $this->seo = financeCategoryId(FinanceCategoryKind::Income, 'SEO');
    $this->payroll = financeCategoryId(FinanceCategoryKind::Expense, 'Payroll');
    $this->hosting = financeCategoryId(FinanceCategoryKind::Expense, 'Hosting');
});

/*
|--------------------------------------------------------------------------
| CRUD per role
|--------------------------------------------------------------------------
*/

it('lets the admin and the accountant record, edit and delete income', function (string $user) {
    $actor = $this->{$user};

    $income = $this->service->recordIncome($actor, [
        'category_id' => $this->maintenance,
        'amount' => '410.00',
        'date' => '2026-10-02',
        'notes' => 'October retainer',
    ]);

    expect($income->exists)->toBeTrue()
        // Set from the actor in the INSERT, never from the caller's array.
        ->and((int) $income->recorded_by)->toBe($actor->id);

    $this->service->updateIncome($actor, $income, ['amount' => '440.00']);
    expect((string) $income->fresh()->amount)->toBe('440.00');

    $this->service->deleteIncome($actor, $income);
    expect(Income::whereKey($income->id)->exists())->toBeFalse();
})->with(['admin', 'accountant'])->group('phase8', 'finance');

it('lets the admin and the accountant record, edit and delete expenses', function (string $user) {
    $actor = $this->{$user};

    $expense = $this->service->recordExpense($actor, [
        'category_id' => $this->hosting,
        'amount' => '95.00',
        'date' => '2026-10-02',
        'notes' => 'October hosting',
    ]);

    $this->service->updateExpense($actor, $expense, ['amount' => '99.00', 'notes' => 'October hosting — corrected']);
    expect((string) $expense->fresh()->amount)->toBe('99.00');

    $this->service->deleteExpense($actor, $expense);
    expect(Expense::whereKey($expense->id)->exists())->toBeFalse();
})->with(['admin', 'accountant'])->group('phase8', 'finance');

it('refuses the employee and the remote employee every finance write', function (string $user) {
    $actor = $this->{$user};

    $income = Income::factory()->recordedBy($this->accountant)->create(['category_id' => $this->maintenance]);
    $expense = Expense::factory()->recordedBy($this->accountant)->create(['category_id' => $this->hosting]);

    $attempts = [
        fn () => $this->service->recordIncome($actor, ['category_id' => $this->maintenance, 'amount' => '10.00', 'date' => '2026-10-01']),
        fn () => $this->service->updateIncome($actor, $income, ['amount' => '11.00']),
        fn () => $this->service->deleteIncome($actor, $income),
        fn () => $this->service->recordExpense($actor, ['category_id' => $this->hosting, 'amount' => '10.00', 'date' => '2026-10-01']),
        fn () => $this->service->updateExpense($actor, $expense, ['amount' => '11.00']),
        fn () => $this->service->deleteExpense($actor, $expense),
        fn () => $this->service->monthlyRollup($actor, 2026, 9),
    ];

    foreach ($attempts as $attempt) {
        expect($attempt)->toThrow(AuthorizationException::class);
    }

    // And nothing moved.
    expect((string) $income->fresh()->amount)->toBe((string) $income->amount)
        ->and(Expense::whereKey($expense->id)->exists())->toBeTrue();
})->with(['employee', 'remote'])->group('phase8', 'finance');

it('refuses a deactivated accountant, because every ability asks isActive first', function () {
    $this->accountant->employee->update(['status' => 'inactive']);
    $this->accountant->update(['status' => 'inactive']);

    expect(fn () => $this->service->recordIncome($this->accountant->fresh(), [
        'category_id' => $this->maintenance,
        'amount' => '10.00',
        'date' => '2026-10-01',
    ]))->toThrow(AuthorizationException::class);
})->group('phase8', 'finance');

/*
|--------------------------------------------------------------------------
| The audit trail — Part C §4
|--------------------------------------------------------------------------
*/

it('writes an expense created row carrying the new values and no old ones', function () {
    $expense = $this->service->recordExpense($this->accountant, [
        'category_id' => $this->hosting,
        'amount' => '95.00',
        'date' => '2026-10-02',
        'notes' => 'October hosting',
    ]);

    $row = financeLatestAudit(AuditEvent::ExpenseCreated);

    expect($row->actor_id)->toBe($this->accountant->id)
        ->and($row->target_id)->toBe($expense->id)
        ->and($row->old_value)->toBeNull()
        ->and($row->new_value['amount'])->toBe('95.00')
        ->and($row->new_value['category_name'])->toBe('Hosting')
        ->and($row->new_value['category_kind'])->toBe('expense')
        ->and($row->new_value['date'])->toBe('2026-10-02')
        ->and($row->new_value['notes'])->toBe('October hosting')
        ->and($row->new_value['recorded_by'])->toBe($this->accountant->id);
})->group('phase8', 'finance');

it('writes an expense edited row carrying BOTH the old and the new values', function () {
    $expense = $this->service->recordExpense($this->accountant, [
        'category_id' => $this->hosting,
        'amount' => '95.00',
        'date' => '2026-10-02',
        'notes' => 'October hosting',
    ]);

    $this->service->updateExpense($this->accountant, $expense, [
        'category_id' => $this->payroll,
        'amount' => '1400.00',
        'notes' => 'Misfiled — this was September salaries',
    ]);

    $row = financeLatestAudit(AuditEvent::ExpenseEdited);

    expect($row->old_value['amount'])->toBe('95.00')
        ->and($row->old_value['category_name'])->toBe('Hosting')
        ->and($row->old_value['notes'])->toBe('October hosting')
        ->and($row->new_value['amount'])->toBe('1400.00')
        ->and($row->new_value['category_name'])->toBe('Payroll')
        ->and($row->new_value['notes'])->toBe('Misfiled — this was September salaries')
        // The date was not in the edit, so it is the same on both sides — the row is the whole
        // record before and after, not a diff.
        ->and($row->old_value['date'])->toBe($row->new_value['date']);
})->group('phase8', 'finance');

it('writes an income created row and an income edited row', function () {
    $income = $this->service->recordIncome($this->accountant, [
        'category_id' => $this->seo,
        'amount' => '300.00',
        'date' => '2026-10-03',
    ]);

    expect(financeLatestAudit(AuditEvent::IncomeCreated)->new_value['amount'])->toBe('300.00');

    $this->service->updateIncome($this->accountant, $income, ['amount' => '350.00']);

    $row = financeLatestAudit(AuditEvent::IncomeEdited);

    expect($row->old_value['amount'])->toBe('300.00')
        ->and($row->new_value['amount'])->toBe('350.00');
})->group('phase8', 'finance');

it('rebuilds a deleted income row from its audit row alone', function () {
    // The rule the hard delete rests on: money that vanishes without a trail is the one thing
    // this table cannot allow. If this test can put the row back, so can a human.
    $project = Project::where('name', 'Buffalo Modular — SEO')->firstOrFail();

    $income = $this->service->recordIncome($this->accountant, [
        'category_id' => $this->seo,
        'project_id' => $project->id,
        'amount' => '500.00',
        'date' => '2026-10-04',
        'notes' => 'October SEO retainer',
    ]);

    $before = $income->fresh(['category', 'project'])->auditValues();

    $this->service->deleteIncome($this->accountant, $income);

    expect(Income::whereKey($before['id'])->exists())->toBeFalse();

    $row = financeLatestAudit(AuditEvent::FinanceRecordDeleted);

    expect($row->target_type)->toBe(Income::class)
        ->and($row->target_id)->toBe($before['id'])
        ->and($row->new_value)->toBeNull()
        ->and(financeByKey($row->old_value))->toBe(financeByKey($before))
        // The names travel beside the ids, so the row is readable after the category is renamed.
        ->and($row->old_value['category_name'])->toBe('SEO')
        ->and($row->old_value['project_name'])->toBe('Buffalo Modular — SEO');

    // And now actually put it back, from the audit row and nothing else.
    $old = $row->old_value;

    DB::table('income')->insert([
        'id' => $old['id'],
        'category_id' => $old['category_id'],
        'project_id' => $old['project_id'],
        'amount' => $old['amount'],
        'date' => $old['date'],
        'notes' => $old['notes'],
        'recorded_by' => $old['recorded_by'],
        'created_at' => $old['created_at'],
        'updated_at' => $old['updated_at'],
    ]);

    expect(financeByKey(Income::findOrFail($old['id'])->fresh(['category', 'project'])->auditValues()))
        ->toBe(financeByKey($before));
})->group('phase8', 'finance');

it('rebuilds a deleted expense row from its audit row alone', function () {
    $expense = $this->service->recordExpense($this->accountant, [
        'category_id' => $this->payroll,
        'amount' => '1400.00',
        'date' => '2026-10-28',
        'notes' => 'October salaries',
    ]);

    $before = $expense->fresh('category')->auditValues();

    $this->service->deleteExpense($this->accountant, $expense);

    $row = financeLatestAudit(AuditEvent::FinanceRecordDeleted);

    expect($row->target_type)->toBe(Expense::class)
        ->and(financeByKey($row->old_value))->toBe(financeByKey($before))
        ->and($row->new_value)->toBeNull();

    DB::table('expenses')->insert([
        'id' => $before['id'],
        'category_id' => $before['category_id'],
        'amount' => $before['amount'],
        'date' => $before['date'],
        'notes' => $before['notes'],
        'recorded_by' => $before['recorded_by'],
        'created_at' => $before['created_at'],
        'updated_at' => $before['updated_at'],
    ]);

    expect(financeByKey(Expense::findOrFail($before['id'])->fresh('category')->auditValues()))
        ->toBe(financeByKey($before));
})->group('phase8', 'finance');

it('leaves no audit row behind when the write itself fails', function () {
    $before = AuditLog::count();

    expect(fn () => $this->service->recordIncome($this->accountant, [
        'category_id' => $this->payroll,
        'amount' => '10.00',
        'date' => '2026-10-01',
    ]))->toThrow(FinanceStateException::class);

    expect(AuditLog::count())->toBe($before);
})->group('phase8', 'finance');

/*
|--------------------------------------------------------------------------
| The rules that are in the database as well as in PHP
|--------------------------------------------------------------------------
*/

it('refuses an expense category on an income record, in the service and in the database', function () {
    // The sentence a person reads.
    expect(fn () => $this->service->recordIncome($this->accountant, [
        'category_id' => $this->payroll,
        'amount' => '100.00',
        'date' => '2026-10-01',
    ]))->toThrow(FinanceStateException::class, 'Payroll');

    // And the promise underneath it, which holds for a seeder, an import or somebody at psql.
    financeDatabaseRefuses(fn () => DB::table('income')->insert([
        'category_id' => $this->payroll,
        'amount' => '100.00',
        'date' => '2026-10-01',
        'recorded_by' => $this->accountant->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]), 'income_category_id_foreign');
})->group('phase8', 'finance');

it('refuses an income category on an expense record, in the service and in the database', function () {
    expect(fn () => $this->service->recordExpense($this->accountant, [
        'category_id' => $this->seo,
        'amount' => '100.00',
        'date' => '2026-10-01',
    ]))->toThrow(FinanceStateException::class, 'SEO');

    financeDatabaseRefuses(fn () => DB::table('expenses')->insert([
        'category_id' => $this->seo,
        'amount' => '100.00',
        'date' => '2026-10-01',
        'recorded_by' => $this->accountant->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]), 'expenses_category_id_foreign');
})->group('phase8', 'finance');

it('refuses an edit that moves a record onto the other side of the ledger', function () {
    $income = $this->service->recordIncome($this->accountant, [
        'category_id' => $this->seo,
        'amount' => '100.00',
        'date' => '2026-10-01',
    ]);

    expect(fn () => $this->service->updateIncome($this->accountant, $income, ['category_id' => $this->payroll]))
        ->toThrow(FinanceStateException::class);

    expect((int) $income->fresh()->category_id)->toBe($this->seo);
})->group('phase8', 'finance');

it('refuses a zero or negative amount, in the service and in the database', function (string $amount) {
    expect(fn () => $this->service->recordIncome($this->accountant, [
        'category_id' => $this->maintenance,
        'amount' => $amount,
        'date' => '2026-10-01',
    ]))->toThrow(FinanceStateException::class);

    financeDatabaseRefuses(fn () => DB::table('income')->insert([
        'category_id' => $this->maintenance,
        'amount' => $amount,
        'date' => '2026-10-01',
        'recorded_by' => $this->accountant->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]), 'income_amount_is_positive');
})->with(['0.00', '-1.00', '-860.00'])->group('phase8', 'finance');

/*
|--------------------------------------------------------------------------
| Categories
|--------------------------------------------------------------------------
*/

it('seeds Part D §13 four income categories and nine expense categories', function () {
    expect($this->service->categories(FinanceCategoryKind::Income)->pluck('name')->all())
        ->toBe(['Maintenance', 'SEO', 'Website', 'Other'])
        ->and($this->service->categories(FinanceCategoryKind::Expense)->pluck('name')->all())
        ->toBe(['Payroll', 'Office', 'Hosting', 'Software', 'Marketing', 'Utilities', 'Operations', 'Project Cost', 'Other']);
})->group('phase8', 'finance');

it('refuses to delete a category that money is filed under, in the service and in the database', function () {
    $maintenance = FinanceCategory::findOrFail($this->maintenance);

    // The seeder filed September's three maintenance retainers under it.
    expect($maintenance->usageCount())->toBe(3);

    expect(fn () => $this->service->deleteCategory($this->admin, $maintenance))
        ->toThrow(FinanceStateException::class, 'Maintenance');

    financeDatabaseRefuses(
        fn () => DB::table('finance_categories')->where('id', $maintenance->id)->delete(),
        'income_category_id_foreign',
    );

    expect(FinanceCategory::whereKey($maintenance->id)->exists())->toBeTrue();
})->group('phase8', 'finance');

it('deletes an unused category and audits it as a configuration change', function () {
    $other = FinanceCategory::query()->ofKind(FinanceCategoryKind::Income)->where('name', 'Other')->firstOrFail();

    expect($other->isInUse())->toBeFalse();

    $this->service->deleteCategory($this->admin, $other);

    $row = financeLatestAudit(AuditEvent::ConfigurationChanged);

    expect(FinanceCategory::whereKey($other->id)->exists())->toBeFalse()
        ->and($row->old_value['name'])->toBe('Other')
        ->and($row->old_value['kind'])->toBe('income')
        ->and($row->new_value)->toBeNull();
})->group('phase8', 'finance');

it('lets the admin add and rename a category, with old and new in the audit row', function () {
    $category = $this->service->createCategory($this->admin, FinanceCategoryKind::Expense, 'Travel');

    expect(financeLatestAudit(AuditEvent::ConfigurationChanged)->new_value['name'])->toBe('Travel');

    $this->service->updateCategory($this->admin, $category, ['name' => 'Travel & subsistence']);

    $row = financeLatestAudit(AuditEvent::ConfigurationChanged);

    expect($row->old_value['name'])->toBe('Travel')
        ->and($row->new_value['name'])->toBe('Travel & subsistence')
        ->and($category->fresh()->kind)->toBe(FinanceCategoryKind::Expense);
})->group('phase8', 'finance');

it('refuses to move a category to the other side of the ledger, even an unused one', function () {
    $category = $this->service->createCategory($this->admin, FinanceCategoryKind::Expense, 'Travel');

    expect(fn () => $this->service->updateCategory($this->admin, $category, ['kind' => FinanceCategoryKind::Income]))
        ->toThrow(FinanceStateException::class);

    // And a category in USE cannot be flipped by any writer at all: ON UPDATE RESTRICT.
    financeDatabaseRefuses(
        fn () => DB::table('finance_categories')->where('id', $this->maintenance)->update(['kind' => 'expense']),
        'income_category_id_foreign',
    );
})->group('phase8', 'finance');

it('reserves the category list for settings.manage, so the accountant reads it but does not edit it', function () {
    $category = FinanceCategory::query()->ofKind(FinanceCategoryKind::Income)->where('name', 'Other')->firstOrFail();

    // Part D §13's screen list: "Categories (seeded, Admin-editable)". The Accountant keeps the
    // books; the shape of the reporting is the Admin's. See FinanceCategoryPolicy.
    expect($this->service->categories(FinanceCategoryKind::Income))->toHaveCount(4)
        ->and(fn () => $this->service->createCategory($this->accountant, FinanceCategoryKind::Income, 'Consulting'))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => $this->service->updateCategory($this->accountant, $category, ['name' => 'Misc']))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => $this->service->deleteCategory($this->accountant, $category))
        ->toThrow(AuthorizationException::class);
})->group('phase8', 'finance');

/*
|--------------------------------------------------------------------------
| The project link
|--------------------------------------------------------------------------
*/

it('records income with no project at all', function () {
    // Part D §13 makes the link optional, so "no project" is an ordinary row and not a gap.
    $income = $this->service->recordIncome($this->accountant, [
        'category_id' => $this->maintenance,
        'amount' => '120.00',
        'date' => '2026-10-01',
        'notes' => 'One-off fix, no project opened',
    ]);

    expect($income->project_id)->toBeNull()
        ->and(financeLatestAudit(AuditEvent::IncomeCreated)->new_value['project_name'])->toBeNull();
})->group('phase8', 'finance');

it('takes no project_id on an expense, whatever the caller sends', function () {
    // Part D §20 gives expenses no project column; the service narrows the array to the columns
    // this side allows, so a caller that sends one is ignored rather than erroring on a column
    // that does not exist.
    $project = Project::where('name', 'Buffalo Modular — SEO')->firstOrFail();

    $expense = $this->service->recordExpense($this->accountant, [
        'category_id' => $this->payroll,
        'project_id' => $project->id,
        'amount' => '100.00',
        'date' => '2026-10-01',
    ]);

    expect($expense->exists)->toBeTrue()
        ->and(DB::getSchemaBuilder()->hasColumn('expenses', 'project_id'))->toBeFalse();
})->group('phase8', 'finance');
