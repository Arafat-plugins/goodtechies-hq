<?php

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeSalary;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PayrollItem;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Services\PayrollService;
use App\Support\AuditEvent;
use App\Support\PayrollStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| The calculation — the leave-impact rule, and salary history
|--------------------------------------------------------------------------
|
| Master prompt Part D §14:
|
|   "Calculate applies leave-impact rules (leave_requests.unpaid_days in the
|    period × daily rate = leave_impact)"
|
| The daily rate is not defined by the plan. PayrollService::leaveImpactCents()
| decides it and argues it:
|
|   daily rate = (base_salary + allowance) ÷ working days in the month, on
|                this employee's own schedule
|
| with the whole division done in integer cents. The test that settles the
| argument is "docks a whole month of unpaid leave down to zero": the numerator
| of the rule counts WORKING days, so its divisor must too, or a person who
| worked no day of September would still be paid for eight of them.
|
| And the promise the history table exists for: recalculating September after a
| November raise still produces September's number.
|
| September 2026 starts on a Tuesday. On the seeded Sun–Thu week it has 22
| working days: 30 days less four Fridays and four Saturdays.
|
| Every constant and helper here is prefixed PAYROLL_ / payrollCalc*, because
| Pest declares both globally across the whole suite (AGENTS.md).
|
*/

const PAYROLL_CALC_MONTH = '2026-09-01';

/** Working days in September 2026 on a Sunday-to-Thursday week. */
const PAYROLL_CALC_WORKING_DAYS = 22;

/** An approved, unpaid leave request over a window, with its unpaid days stated. */
function payrollCalcUnpaidLeave(Employee $employee, string $from, string $to, int $unpaidDays): LeaveRequest
{
    $unpaidType = LeaveType::where('is_unpaid', true)->firstOrFail();

    return LeaveRequest::factory()
        ->forEmployee($employee)
        ->ofType($unpaidType)
        ->between($from, $to)
        ->approved()
        ->unpaid($unpaidDays)
        ->create();
}

beforeEach(function () {
    $this->seed();

    $this->service = app(PayrollService::class);

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();

    $this->period = PayrollPeriod::query()->forMonth(PAYROLL_CALC_MONTH)->firstOrFail();
    $this->yaseensItem = PayrollItem::where('employee_id', $this->yaseen->employee->getKey())->firstOrFail();
});

/*
|--------------------------------------------------------------------------
| The leave-impact rule
|--------------------------------------------------------------------------
*/

it('counts twenty-two payable working days in September on the seeded week', function () {
    expect($this->service->payableDaysIn($this->yaseen->employee, $this->period))
        ->toBe(PAYROLL_CALC_WORKING_DAYS);
})->group('phase9');

it('leaves the leave impact at zero when nobody took unpaid leave', function () {
    $this->service->calculate($this->accountant, $this->period);

    expect($this->yaseensItem->refresh()->leave_impact)->toBe('0.00')
        // Yaseen: 800 base + 100 allowance.
        ->and($this->yaseensItem->net_salary)->toBe('900.00');
})->group('phase9');

it('docks three unpaid days at the daily rate', function () {
    // Monday the 7th to Wednesday the 9th — three working days on a Sun–Thu week.
    payrollCalcUnpaidLeave($this->yaseen->employee, '2026-09-07', '2026-09-09', 3);

    $this->service->calculate($this->accountant, $this->period);

    // (800 + 100) × 3 ÷ 22 = 122.727… → 122.73, half up, in whole cents.
    expect($this->yaseensItem->refresh()->leave_impact)->toBe('122.73')
        ->and($this->yaseensItem->net_salary)->toBe('777.27');
})->group('phase9');

it('docks a whole month of unpaid leave down to zero — the rule that closes', function () {
    // Every day of September. The working-day predicate is what turns it into 22.
    payrollCalcUnpaidLeave($this->yaseen->employee, '2026-09-01', '2026-09-30', PAYROLL_CALC_WORKING_DAYS);

    $this->service->calculate($this->accountant, $this->period);

    expect($this->yaseensItem->refresh()->leave_impact)->toBe('900.00')
        ->and($this->yaseensItem->net_salary)->toBe('0.00');
})->group('phase9');

it('takes the stored unpaid_days verbatim for a request that lies inside the month', function () {
    $request = payrollCalcUnpaidLeave($this->yaseen->employee, '2026-09-07', '2026-09-10', 4);

    expect($this->service->unpaidDaysIn($this->yaseen->employee, $this->period))
        ->toBe((int) $request->unpaid_days);
})->group('phase9');

it('splits a request that crosses a month boundary', function () {
    // 28 September (Monday) to 2 October (Friday). September's working part is the 28th, 29th
    // and 30th; the 1st of October is a Thursday and the 2nd a Friday, which is not a working
    // day at all.
    payrollCalcUnpaidLeave($this->yaseen->employee, '2026-09-28', '2026-10-02', 4);

    expect($this->service->unpaidDaysIn($this->yaseen->employee, $this->period))->toBe(3);

    $october = PayrollPeriod::factory()->forMonth('2026-10-01')->create();

    expect($this->service->unpaidDaysIn($this->yaseen->employee, $october))->toBe(1);
})->group('phase9');

it('ignores paid leave, pending requests and other people', function () {
    $paidType = LeaveType::where('is_unpaid', false)->firstOrFail();
    $unpaidType = LeaveType::where('is_unpaid', true)->firstOrFail();

    // Approved but paid: unpaid_days is 0, so it is not even selected.
    LeaveRequest::factory()->forEmployee($this->yaseen->employee)->ofType($paidType)
        ->between('2026-09-07', '2026-09-08')->approved()->create();

    // Unpaid but still pending: nobody has granted it.
    LeaveRequest::factory()->forEmployee($this->yaseen->employee)->ofType($unpaidType)
        ->between('2026-09-14', '2026-09-15')->unpaid(2)->create();

    // Somebody else's unpaid leave.
    payrollCalcUnpaidLeave($this->tapu->employee, '2026-09-07', '2026-09-09', 3);

    $this->service->calculate($this->accountant, $this->period);

    expect($this->yaseensItem->refresh()->leave_impact)->toBe('0.00');
})->group('phase9');

it('gives somebody with no schedule every day of the month on both sides of the division', function () {
    // The Accountant is `tracking_mode = none` and has no schedule row. LeaveService counts
    // every day in the window for them, so the rule still closes: a whole month unpaid docks a
    // whole month's pay, on 30 days rather than 22.
    $accountantEmployee = $this->accountant->employee;
    $item = PayrollItem::where('employee_id', $accountantEmployee->getKey())->firstOrFail();

    expect($this->service->payableDaysIn($accountantEmployee, $this->period))->toBe(30);

    payrollCalcUnpaidLeave($accountantEmployee, '2026-09-01', '2026-09-30', 30);

    $this->service->calculate($this->accountant, $this->period);

    // 1000 + 100 = 1100, all of it.
    expect($item->refresh()->leave_impact)->toBe('1100.00')
        ->and($item->net_salary)->toBe('0.00');
})->group('phase9');

/*
|--------------------------------------------------------------------------
| Idempotent, and current
|--------------------------------------------------------------------------
*/

it('gives the same answer when calculate is pressed twice', function () {
    payrollCalcUnpaidLeave($this->yaseen->employee, '2026-09-07', '2026-09-09', 3);

    $this->service->calculate($this->accountant, $this->period);
    $first = $this->yaseensItem->refresh()->leave_impact;

    $this->service->calculate($this->accountant, $this->period);

    expect($this->yaseensItem->refresh()->leave_impact)->toBe($first)->toBe('122.73');
})->group('phase9');

it('gives a different answer once another unpaid day is approved', function () {
    payrollCalcUnpaidLeave($this->yaseen->employee, '2026-09-07', '2026-09-09', 3);

    $this->service->calculate($this->accountant, $this->period);

    expect($this->yaseensItem->refresh()->leave_impact)->toBe('122.73');

    // The week after — a second, non-overlapping request.
    payrollCalcUnpaidLeave($this->yaseen->employee, '2026-09-14', '2026-09-14', 1);

    $this->service->calculate($this->accountant, $this->period);

    // Four days now: (800 + 100) × 4 ÷ 22 = 163.636… → 163.64.
    expect($this->yaseensItem->refresh()->leave_impact)->toBe('163.64')
        ->and($this->yaseensItem->net_salary)->toBe('736.36');
})->group('phase9');

it('keeps the figures the accountant adjusted when calculate runs again', function () {
    $this->service->adjustItem($this->accountant, $this->yaseensItem, [
        'base_salary' => '850.00',
        'bonus' => '120.00',
    ]);

    $this->service->calculate($this->accountant, $this->period);

    // Calculate does not re-read `employee_salaries`; it would undo the adjustment.
    expect($this->yaseensItem->refresh()->base_salary)->toBe('850.00')
        ->and($this->yaseensItem->bonus)->toBe('120.00')
        ->and($this->yaseensItem->net_salary)->toBe('1070.00');
})->group('phase9');

/*
|--------------------------------------------------------------------------
| Salary history — the test that matters
|--------------------------------------------------------------------------
*/

it('still gives September its own number after a November raise', function () {
    $employee = $this->yaseen->employee;

    // September's figure, as drafted: 800 + 100.
    expect($this->yaseensItem->base_salary)->toBe('800.00');

    // A raise, effective in November.
    $this->service->setSalary($this->admin, $employee, '1400.00', '200.00', '2026-11-01');

    // The history answers both questions, from the same table.
    expect($this->service->salaryFor($employee, '2026-09-01')->base_salary)->toBe('800.00')
        ->and($this->service->salaryFor($employee, '2026-10-31')->base_salary)->toBe('800.00')
        ->and($this->service->salaryFor($employee, '2026-11-01')->base_salary)->toBe('1400.00');

    // And recalculating September produces September's number, not November's.
    $this->service->calculate($this->accountant, $this->period);

    expect($this->yaseensItem->refresh()->base_salary)->toBe('800.00')
        ->and($this->yaseensItem->net_salary)->toBe('900.00');

    // Re-drafting an untouched month reads the same history: December gets the raise,
    // and a re-drafted August does not.
    $december = $this->service->createDraft($this->admin, '2026-12-01');
    $august = $this->service->createDraft($this->admin, '2026-08-01');

    expect($december->items()->where('employee_id', $employee->getKey())->sole()->base_salary)->toBe('1400.00')
        ->and($august->items()->where('employee_id', $employee->getKey())->sole()->base_salary)->toBe('800.00');
})->group('phase9');

it('carries the seeded mid-year raise into September and not into February', function () {
    // PayrollSeeder puts Tapu on 900 from January and 1,100 from July — the seeded database
    // demonstrates the history rule rather than describing it.
    $tapu = $this->tapu->employee;

    expect($this->service->salaryFor($tapu, '2026-02-01')->base_salary)->toBe('900.00')
        ->and($this->service->salaryFor($tapu, '2026-09-01')->base_salary)->toBe('1100.00');

    $february = $this->service->createDraft($this->admin, '2026-02-01');

    expect($february->items()->where('employee_id', $tapu->getKey())->sole()->base_salary)->toBe('900.00')
        ->and(PayrollItem::where('payroll_period_id', $this->period->getKey())
            ->where('employee_id', $tapu->getKey())->sole()->base_salary)->toBe('1100.00');
})->group('phase9');

it('records a salary change with the previous figures beside the new ones', function () {
    $employee = $this->yaseen->employee;

    $this->service->setSalary($this->admin, $employee, '1400.00', '200.00', '2026-11-01');

    $row = AuditLog::where('event', AuditEvent::SalaryChanged->value)->sole();

    expect((int) $row->actor_id)->toBe((int) $this->admin->getKey())
        ->and($row->old_value['base_salary'])->toBe('800.00')
        ->and($row->new_value['base_salary'])->toBe('1400.00')
        ->and($row->new_value['allowance'])->toBe('200.00')
        ->and($row->new_value['effective_from'])->toBe('2026-11-01')
        ->and($row->new_value['employee_name'])->toBe($this->yaseen->name);
})->group('phase9');

it('records the first salary an employee ever had with no old value', function () {
    $fresh = Employee::factory()->create();

    $this->service->setSalary($this->admin, $fresh, '500.00', '0.00', '2026-01-01');

    $row = AuditLog::where('event', AuditEvent::SalaryChanged->value)->sole();

    expect($row->old_value)->toBeNull()
        ->and($row->new_value['base_salary'])->toBe('500.00');
})->group('phase9');

it('keeps one salary row per employee per date and corrects it in place', function () {
    $employee = $this->yaseen->employee;

    $this->service->setSalary($this->admin, $employee, '1000.00', '0.00', '2026-11-01');
    $this->service->setSalary($this->admin, $employee, '1100.00', '0.00', '2026-11-01');

    expect(EmployeeSalary::where('employee_id', $employee->getKey())
        ->whereDate('effective_from', '2026-11-01')->count())->toBe(1)
        ->and($this->service->salaryFor($employee, '2026-11-01')->base_salary)->toBe('1100.00');

    // Both decisions are still in the audit log; the table holds one truth per date and the
    // log holds every act.
    expect(AuditLog::where('event', AuditEvent::SalaryChanged->value)->count())->toBe(2);
})->group('phase9');

it('lets only a holder of payroll.approve set a salary', function () {
    foreach ([$this->accountant, $this->yaseen, $this->tapu] as $actor) {
        expect(fn () => $this->service->setSalary($actor, $this->yaseen->employee, '9000.00'))
            ->toThrow(AuthorizationException::class, 'not allowed to set salaries');
    }

    expect($this->service->salaryFor($this->yaseen->employee, '2026-09-01')->base_salary)->toBe('800.00');
})->group('phase9');

it('refuses a negative salary at the database', function () {
    expect(fn () => EmployeeSalary::factory()
        ->forEmployee($this->yaseen->employee)
        ->of('-100.00')
        ->effectiveFrom('2027-01-01')
        ->setBy($this->admin)
        ->create())
        ->toThrow(QueryException::class, 'employee_salaries_amounts_are_not_negative');
})->group('phase9');

// One failing statement per test: a constraint violation aborts the surrounding transaction,
// so a second assertion after it would fail on "current transaction is aborted" rather than on
// what it was about.
it('refuses a second period for a month', function () {
    expect(fn () => PayrollPeriod::factory()->forMonth(PAYROLL_CALC_MONTH)->create())
        ->toThrow(QueryException::class, 'payroll_periods_month_unique');
})->group('phase9');

it('refuses a second line for a person in the same period', function () {
    expect(fn () => PayrollItem::factory()
        ->in($this->period)
        ->forEmployee($this->yaseen->employee)
        ->create())
        ->toThrow(QueryException::class, 'payroll_items_one_per_employee_per_period');
})->group('phase9');

it('refuses a period whose month is not the first of a month', function () {
    expect(fn () => PayrollPeriod::query()->create([
        'month' => '2026-11-15',
        'status' => PayrollStatus::Draft,
    ]))->toThrow(QueryException::class, 'payroll_periods_month_is_a_first');
})->group('phase9');

it('refuses a status the enum does not have', function () {
    expect(fn () => DB::table('payroll_periods')->insert([
        'month' => '2027-01-01',
        'status' => 'settled',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class, 'payroll_periods_status_is_known');
})->group('phase9');

it('refuses to let anybody write the generated net directly', function () {
    expect(fn () => DB::table('payroll_items')
        ->where('id', $this->yaseensItem->getKey())
        ->update(['net_salary' => '9999.00']))
        ->toThrow(QueryException::class);
})->group('phase9');
