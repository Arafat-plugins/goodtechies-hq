<?php

use App\Models\Expense;
use App\Models\PayrollItem;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Services\FinanceService;
use App\Support\FinanceCategoryKind;
use Database\Factories\ExpenseFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Salaries in the monthly rollup
|--------------------------------------------------------------------------
|
| A payroll month's net salaries count as an expense once the period is
| locked or paid; a Draft month is reported as pending and never counted.
| May 2027, far from the seeded books.
|
*/

/** A Draft period for May 2027 with two items: nets 1000.00 + 1500.00. */
function payrollRollupPeriod(): PayrollPeriod
{
    $period = PayrollPeriod::factory()->forMonth('2027-05-01')->create();

    PayrollItem::factory()->in($period)->of('1000.00', '0.00')->create();
    PayrollItem::factory()->in($period)->of('1500.00', '0.00')->create();

    return $period;
}

/**
 * Close the month by writing the status column directly — the model guard is bypassed on
 * purpose, in this test only; `locked_at` goes with it because the database checks the pair.
 */
function payrollRollupClose(PayrollPeriod $period): void
{
    DB::table('payroll_periods')->where('id', $period->getKey())->update([
        'status' => 'locked',
        'locked_at' => Carbon::now(),
    ]);
}

beforeEach(function () {
    $this->seed();

    $this->service = app(FinanceService::class);
    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();

    Expense::factory()->recordedBy($this->accountant)->of('100.00')->on('2027-05-10')->create([
        'category_id' => ExpenseFactory::category(FinanceCategoryKind::Expense, 'Hosting'),
    ]);
});

it('reports a Draft payroll month as pending and does not count it', function () {
    payrollRollupPeriod();

    $rollup = $this->service->monthlyRollup($this->admin, 2027, 5);

    expect($rollup['expenses']['total'])->toBe('100.00')
        ->and($rollup['net'])->toBe('-100.00')
        ->and(collect($rollup['expenses']['categories'])->pluck('source')->all())->toBe(['ledger'])
        ->and($rollup['payroll']['status'])->toBe('draft')
        ->and($rollup['payroll']['counted'])->toBeFalse()
        ->and($rollup['payroll']['total'])->toBe('2500.00')
        ->and($rollup['payroll']['double_count_warning'])->toBeFalse();
})->group('finance');

it('counts a closed payroll month as a Salaries expense line', function () {
    payrollRollupClose(payrollRollupPeriod());

    $rollup = $this->service->monthlyRollup($this->admin, 2027, 5);
    $line = collect($rollup['expenses']['categories'])->firstWhere('name', 'Salaries (payroll)');

    expect($line)->not->toBeNull()
        ->and($line['source'])->toBe('payroll')
        ->and($line['category_id'])->toBe(0)
        ->and($line['total'])->toBe('2500.00')
        ->and($rollup['expenses']['total'])->toBe('2600.00')
        ->and($rollup['net'])->toBe('-2600.00')
        ->and($rollup['payroll']['status'])->toBe('locked')
        ->and($rollup['payroll']['counted'])->toBeTrue()
        ->and($rollup['payroll']['double_count_warning'])->toBeFalse();
})->group('finance');

it('warns when a manual Payroll expense sits in a month whose payroll is counted', function () {
    $period = payrollRollupPeriod();

    // Recorded before the month closes, as it would have been in the ledger.
    Expense::factory()->recordedBy($this->accountant)->of('2500.00')->on('2027-05-28')->create([
        'category_id' => ExpenseFactory::category(FinanceCategoryKind::Expense, 'Payroll'),
    ]);

    payrollRollupClose($period);

    $rollup = $this->service->monthlyRollup($this->admin, 2027, 5);

    expect($rollup['payroll']['manual_payroll_entries'])->toBe('2500.00')
        ->and($rollup['payroll']['double_count_warning'])->toBeTrue()
        ->and($rollup['expenses']['total'])->toBe('5100.00');
})->group('finance');
