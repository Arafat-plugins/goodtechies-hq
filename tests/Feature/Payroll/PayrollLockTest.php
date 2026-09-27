<?php

use App\Exceptions\FinanceStateException;
use App\Models\Expense;
use App\Models\FinanceCategory;
use App\Models\Income;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Services\FinanceService;
use App\Services\PayrollService;
use App\Support\FinanceCategoryKind;
use App\Support\PayrollStatus;

/*
|--------------------------------------------------------------------------
| The locked period — Phase 8's seam, filled
|--------------------------------------------------------------------------
|
| Master prompt Part D §13, word for word:
|
|   "a finance record is blocked when its `date` falls in the month of a
|    `payroll_periods` row whose status is LOCKED or PAID."
|
| FinanceService::assertPeriodIsOpen() was shipped empty in Phase 8 and called
| from every write path already (decision 8-14). Filling one body turned the
| block on everywhere at once, so this file tests it from the FINANCE side —
| through the service the Accountant actually uses — in all five directions:
|
|   1. create a record dated in a locked month
|   2. edit one that is already dated in one
|   3. move one INTO a locked month
|   4. move one OUT of a locked month
|   5. delete one dated in a locked month
|
| …on both sides of the ledger, and for both statuses that close a month. And
| the control that makes all of it meaningful: an OPEN month still writes.
|
| Every constant and helper here is prefixed PAYROLL_ / payrollLock*, because
| Pest declares both globally across the whole suite (AGENTS.md).
|
*/

/** The month PayrollSeeder and FinanceSeeder share — Part D §13's acceptance example. */
const PAYROLL_LOCK_CLOSED_MONTH = '2026-09-15';

/** A month with no payroll period at all. */
const PAYROLL_LOCK_OPEN_MONTH = '2026-11-12';

function payrollLockCategory(FinanceCategoryKind $kind, string $name): FinanceCategory
{
    return FinanceCategory::query()->ofKind($kind)->where('name', $name)->firstOrFail();
}

/** Walk the seeded September draft to a closing status through the real machine. */
function payrollLockClose(PayrollStatus $status): PayrollPeriod
{
    $admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();
    $payroll = app(PayrollService::class);

    $period = PayrollPeriod::query()->forMonth('2026-09-01')->firstOrFail();

    $payroll->calculate($accountant, $period);
    $payroll->review($admin, $period);
    $payroll->approve($admin, $period);
    $payroll->lock($admin, $period);

    if ($status === PayrollStatus::Paid) {
        $payroll->markPaid($admin, $period);
    }

    return $period->refresh();
}

beforeEach(function () {
    $this->seed();

    $this->finance = app(FinanceService::class);
    $this->payroll = app(PayrollService::class);

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();

    $this->incomeCategory = payrollLockCategory(FinanceCategoryKind::Income, 'SEO');
    $this->expenseCategory = payrollLockCategory(FinanceCategoryKind::Expense, 'Hosting');

    // Two records made while September is still open — the rows the five directions act on.
    $this->septemberIncome = $this->finance->recordIncome($this->accountant, [
        'category_id' => $this->incomeCategory->getKey(),
        'amount' => '400.00',
        'date' => PAYROLL_LOCK_CLOSED_MONTH,
        'notes' => 'A September invoice.',
    ]);

    $this->septemberExpense = $this->finance->recordExpense($this->accountant, [
        'category_id' => $this->expenseCategory->getKey(),
        'amount' => '95.00',
        'date' => PAYROLL_LOCK_CLOSED_MONTH,
        'notes' => 'September hosting.',
    ]);

    $this->novemberIncome = $this->finance->recordIncome($this->accountant, [
        'category_id' => $this->incomeCategory->getKey(),
        'amount' => '500.00',
        'date' => PAYROLL_LOCK_OPEN_MONTH,
        'notes' => 'A November invoice.',
    ]);
});

/*
|--------------------------------------------------------------------------
| The control — an open month, and the seeded DRAFT, both still write
|--------------------------------------------------------------------------
*/

it('lets the accountant write a month with no payroll period', function () {
    $income = $this->finance->recordIncome($this->accountant, [
        'category_id' => $this->incomeCategory->getKey(),
        'amount' => '250.00',
        'date' => '2026-12-04',
        'notes' => 'A December invoice.',
    ]);

    expect($income->exists)->toBeTrue();
})->group('phase9');

it('lets the accountant write a month whose payroll period is still open', function (string $status) {
    // Draft, calculated, reviewed and approved do NOT close the month. Part D §13 names two
    // statuses and only two, and the whole of the approval step is that the figures are settled
    // — not that the ledger is shut.
    $period = PayrollPeriod::query()->forMonth('2026-09-01')->firstOrFail();

    // The forward path, as far as this dataset asks for. Each step is the real service call,
    // so the period this test leaves behind is one the application could have produced.
    $steps = [
        'draft' => [],
        'calculated' => ['calculate'],
        'reviewed' => ['calculate', 'review'],
        'approved' => ['calculate', 'review', 'approve'],
    ][$status];

    foreach ($steps as $step) {
        $this->payroll->{$step}($step === 'calculate' ? $this->accountant : $this->admin, $period);
    }

    expect($period->refresh()->status)->toBe(PayrollStatus::from($status));

    $income = $this->finance->recordIncome($this->accountant, [
        'category_id' => $this->incomeCategory->getKey(),
        'amount' => '120.00',
        'date' => PAYROLL_LOCK_CLOSED_MONTH,
        'notes' => 'Still open.',
    ]);

    expect($income->exists)->toBeTrue();
})->with(['draft', 'calculated', 'reviewed', 'approved'])->group('phase9');

/*
|--------------------------------------------------------------------------
| The five directions, both sides, both closing statuses
|--------------------------------------------------------------------------
*/

it('1 — refuses a new income or expense dated in a closed month', function (string $status) {
    payrollLockClose(PayrollStatus::from($status));

    expect(fn () => $this->finance->recordIncome($this->accountant, [
        'category_id' => $this->incomeCategory->getKey(),
        'amount' => '100.00',
        'date' => PAYROLL_LOCK_CLOSED_MONTH,
    ]))->toThrow(FinanceStateException::class, 'September 2026 is a '.$status.' payroll period');

    expect(fn () => $this->finance->recordExpense($this->accountant, [
        'category_id' => $this->expenseCategory->getKey(),
        'amount' => '100.00',
        'date' => PAYROLL_LOCK_CLOSED_MONTH,
    ]))->toThrow(FinanceStateException::class, 'can no longer be changed');
})->with(['locked', 'paid'])->group('phase9');

it('2 — refuses an edit to a record already dated in a closed month', function (string $status) {
    payrollLockClose(PayrollStatus::from($status));

    expect(fn () => $this->finance->updateIncome($this->accountant, $this->septemberIncome, [
        'amount' => '450.00',
    ]))->toThrow(FinanceStateException::class);

    expect(fn () => $this->finance->updateExpense($this->accountant, $this->septemberExpense, [
        'amount' => '99.00',
    ]))->toThrow(FinanceStateException::class);

    expect($this->septemberIncome->refresh()->amount)->toBe('400.00')
        ->and($this->septemberExpense->refresh()->amount)->toBe('95.00');
})->with(['locked', 'paid'])->group('phase9');

it('3 — refuses moving an open month record INTO a closed month', function () {
    payrollLockClose(PayrollStatus::Locked);

    expect(fn () => $this->finance->updateIncome($this->accountant, $this->novemberIncome, [
        'date' => PAYROLL_LOCK_CLOSED_MONTH,
    ]))->toThrow(FinanceStateException::class, 'September 2026 is a locked payroll period');

    expect($this->novemberIncome->refresh()->date->toDateString())->toBe(PAYROLL_LOCK_OPEN_MONTH);
})->group('phase9');

it('4 — refuses moving a record OUT of a closed month', function () {
    payrollLockClose(PayrollStatus::Locked);

    // The date it is moving TO is open; the date it is moving FROM is not. Moving a figure out
    // of a locked month is the same act as changing one inside it (decision 8-14), which is why
    // the seam is checked on the date BEFORE the edit as well as the date after it.
    expect(fn () => $this->finance->updateIncome($this->accountant, $this->septemberIncome, [
        'date' => PAYROLL_LOCK_OPEN_MONTH,
    ]))->toThrow(FinanceStateException::class, 'September 2026 is a locked payroll period');

    expect($this->septemberIncome->refresh()->date->toDateString())->toBe(PAYROLL_LOCK_CLOSED_MONTH);
})->group('phase9');

it('5 — refuses deleting a record dated in a closed month', function (string $status) {
    payrollLockClose(PayrollStatus::from($status));

    expect(fn () => $this->finance->deleteIncome($this->admin, $this->septemberIncome))
        ->toThrow(FinanceStateException::class);

    expect(Income::find($this->septemberIncome->getKey()))->not->toBeNull();
})->with(['locked', 'paid'])->group('phase9');

it('5b — refuses deleting an expense dated in a closed month', function () {
    payrollLockClose(PayrollStatus::Locked);

    expect(fn () => $this->finance->deleteExpense($this->admin, $this->septemberExpense))
        ->toThrow(FinanceStateException::class);

    expect(Expense::find($this->septemberExpense->getKey()))->not->toBeNull();
})->group('phase9');

/*
|--------------------------------------------------------------------------
| The block is about the month, not about the person
|--------------------------------------------------------------------------
*/

it('blocks the admin exactly as it blocks the accountant', function () {
    payrollLockClose(PayrollStatus::Locked);

    // A lock is not a permission. An Admin who wants September open again reverses the lock —
    // which the refusal's own sentence tells them.
    expect(fn () => $this->finance->recordIncome($this->admin, [
        'category_id' => $this->incomeCategory->getKey(),
        'amount' => '100.00',
        'date' => PAYROLL_LOCK_CLOSED_MONTH,
    ]))->toThrow(FinanceStateException::class, 'An Admin can reverse the lock if this needs to change.');
})->group('phase9');

it('does not offer to reverse a lock on a month that has been paid', function () {
    payrollLockClose(PayrollStatus::Paid);

    try {
        $this->finance->recordIncome($this->admin, [
            'category_id' => $this->incomeCategory->getKey(),
            'amount' => '100.00',
            'date' => PAYROLL_LOCK_CLOSED_MONTH,
        ]);
    } catch (FinanceStateException $exception) {
        expect($exception->getMessage())->toContain('is a paid payroll period')
            ->and($exception->getMessage())->not->toContain('reverse the lock');

        return;
    }

    $this->fail('A paid month should have refused the write.');
})->group('phase9');

it('leaves every other month writable while September is closed', function () {
    payrollLockClose(PayrollStatus::Locked);

    $october = $this->finance->recordIncome($this->accountant, [
        'category_id' => $this->incomeCategory->getKey(),
        'amount' => '800.00',
        'date' => '2026-10-03',
        'notes' => 'October retainer.',
    ]);

    expect($october->exists)->toBeTrue();

    // And an existing open-month record is still editable and still deletable.
    $this->finance->updateIncome($this->accountant, $this->novemberIncome, ['amount' => '550.00']);

    expect($this->novemberIncome->refresh()->amount)->toBe('550.00');

    $this->finance->deleteIncome($this->admin, $this->novemberIncome);

    expect(Income::find($this->novemberIncome->getKey()))->toBeNull();
})->group('phase9');

/*
|--------------------------------------------------------------------------
| Reversing the lock re-opens the ledger
|--------------------------------------------------------------------------
*/

it('re-opens the month when an admin reverses the lock', function () {
    $period = payrollLockClose(PayrollStatus::Locked);

    expect(fn () => $this->finance->updateIncome($this->accountant, $this->septemberIncome, ['amount' => '450.00']))
        ->toThrow(FinanceStateException::class);

    $this->payroll->reverseLock($this->admin, $period, 'A September hosting invoice arrived late.');

    $this->finance->updateIncome($this->accountant, $this->septemberIncome, ['amount' => '450.00']);

    expect($this->septemberIncome->refresh()->amount)->toBe('450.00');

    // And locking it again shuts the ledger again.
    $this->payroll->lock($this->admin, $period->refresh());

    expect(fn () => $this->finance->updateIncome($this->accountant, $this->septemberIncome, ['amount' => '460.00']))
        ->toThrow(FinanceStateException::class);
})->group('phase9');
