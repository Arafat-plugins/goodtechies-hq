<?php

use App\Exceptions\PayrollStateException;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\PayrollItem;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Services\PayrollService;
use App\Support\PayrollStatus;
use App\Support\RoleName;
use App\Support\UserStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

/*
|--------------------------------------------------------------------------
| hq:create-payroll-draft — the auto draft on the 1st
|--------------------------------------------------------------------------
|
| Master prompt Part D §14:
|
|   "Draft auto-created on the 1st for all active employees from
|    employee_salaries (current base + allowances, with history)."
|
| The property that matters is idempotency, and it is NOT a flag on a row or a
| cache key — it is two unique indexes (payroll_periods.month, and
| payroll_items on (period, employee)). decision 7-5 made the same argument for
| hq:remind-meetings and every word of it transfers. So these tests run the
| command twice, three times, and against a month that has already moved on,
| and assert that the database looks the same afterwards.
|
| Every constant and helper here is prefixed PAYROLL_ / payrollDraft*, because
| Pest declares both globally across the whole suite (AGENTS.md).
|
*/

/** A month with no seeded period of its own. */
const PAYROLL_DRAFT_MONTH = '2026-11-01';

function payrollDraftRun(?string $month = null): int
{
    return Artisan::call(
        'hq:create-payroll-draft'.($month === null ? '' : ' --month='.$month),
    );
}

function payrollDraftPeriod(string $month = PAYROLL_DRAFT_MONTH): ?PayrollPeriod
{
    return PayrollPeriod::query()->forMonth($month)->first();
}

beforeEach(function () {
    $this->seed();

    $this->service = app(PayrollService::class);
    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();

    // Everybody the seeder gave a salary — five active employees.
    $this->activeCount = Employee::where('status', UserStatus::Active->value)->count();
});

/*
|--------------------------------------------------------------------------
| What one run does
|--------------------------------------------------------------------------
*/

it('creates one draft period and one line per active employee', function () {
    payrollDraftRun(PAYROLL_DRAFT_MONTH);

    $period = payrollDraftPeriod();

    expect($period)->not->toBeNull()
        ->and($period->status)->toBe(PayrollStatus::Draft)
        ->and($period->month->toDateString())->toBe(PAYROLL_DRAFT_MONTH)
        ->and($period->items()->count())->toBe($this->activeCount);
})->group('phase9');

it('copies each line from the salary in force on the first of the month', function () {
    // Tapu is on 900 from January and 1,100 from July (PayrollSeeder).
    $tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail()->employee;

    payrollDraftRun('2026-02-01');
    payrollDraftRun(PAYROLL_DRAFT_MONTH);

    expect(PayrollItem::whereRelation('period', 'month', '2026-02-01')
        ->where('employee_id', $tapu->getKey())->sole()->base_salary)->toBe('900.00')
        ->and(PayrollItem::whereRelation('period', 'month', PAYROLL_DRAFT_MONTH)
            ->where('employee_id', $tapu->getKey())->sole()->base_salary)->toBe('1100.00');
})->group('phase9');

it('leaves the leave impact and the extras at zero — it drafts, it does not calculate', function () {
    payrollDraftRun(PAYROLL_DRAFT_MONTH);

    foreach (payrollDraftPeriod()->items as $item) {
        expect($item->leave_impact)->toBe('0.00')
            ->and($item->bonus)->toBe('0.00')
            ->and($item->deduction)->toBe('0.00')
            ->and($item->advance)->toBe('0.00')
            ->and($item->admin_notes)->toBeNull();
    }
})->group('phase9');

it('writes no audit row — a scheduled draft is not a decision anybody made', function () {
    payrollDraftRun(PAYROLL_DRAFT_MONTH);

    expect(AuditLog::count())->toBe(0);
})->group('phase9');

it('defaults to the current month when no month is given', function () {
    Carbon::setTestNow('2027-03-14 09:00:00');

    payrollDraftRun();

    expect(payrollDraftPeriod('2027-03-01'))->not->toBeNull();

    Carbon::setTestNow();
})->group('phase9');

/*
|--------------------------------------------------------------------------
| Idempotency — the whole point
|--------------------------------------------------------------------------
*/

it('doubles nothing when it runs three times on the same day', function () {
    payrollDraftRun(PAYROLL_DRAFT_MONTH);

    $periods = PayrollPeriod::count();
    $items = PayrollItem::count();

    payrollDraftRun(PAYROLL_DRAFT_MONTH);
    payrollDraftRun(PAYROLL_DRAFT_MONTH);

    expect(PayrollPeriod::count())->toBe($periods)
        ->and(PayrollItem::count())->toBe($items)
        ->and(payrollDraftPeriod()->items()->count())->toBe($this->activeCount);
})->group('phase9');

it('does nothing at all when the month already has a period', function () {
    // The seeded September draft. The command must not touch it — not its status, not its
    // lines, not even its updated_at.
    $september = PayrollPeriod::query()->forMonth('2026-09-01')->firstOrFail();
    $before = [
        'status' => $september->status,
        'items' => $september->items()->count(),
        'updated_at' => $september->updated_at->toIso8601String(),
    ];

    payrollDraftRun('2026-09-20');

    $september->refresh();

    expect($september->status)->toBe($before['status'])
        ->and($september->items()->count())->toBe($before['items'])
        ->and($september->updated_at->toIso8601String())->toBe($before['updated_at']);
})->group('phase9');

it('does not top up a month that has already been approved', function () {
    $september = PayrollPeriod::query()->forMonth('2026-09-01')->firstOrFail();
    $accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();

    $this->service->calculate($accountant, $september);
    $this->service->review($this->admin, $september);
    $this->service->approve($this->admin, $september);

    // Somebody joins on the 20th and gets a salary backdated to the 1st.
    $joiner = Employee::factory()->create();
    $this->service->setSalary($this->admin, $joiner, '700.00', '0.00', '2026-09-01');

    payrollDraftRun('2026-09-20');

    expect($september->refresh()->status)->toBe(PayrollStatus::Approved)
        ->and(PayrollItem::where('payroll_period_id', $september->getKey())
            ->where('employee_id', $joiner->getKey())->count())->toBe(0);
})->group('phase9');

/*
|--------------------------------------------------------------------------
| Who is on it, and who is not
|--------------------------------------------------------------------------
*/

it('leaves out an inactive employee', function () {
    $leaver = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $leaver->employee->update(['status' => UserStatus::Inactive->value]);

    payrollDraftRun(PAYROLL_DRAFT_MONTH);

    expect(payrollDraftPeriod()->items()->count())->toBe($this->activeCount - 1)
        ->and(PayrollItem::where('payroll_period_id', payrollDraftPeriod()->getKey())
            ->where('employee_id', $leaver->employee->getKey())->count())->toBe(0);
})->group('phase9');

it('skips somebody with no salary on record and names them', function () {
    $joiner = Employee::factory()->create();

    payrollDraftRun(PAYROLL_DRAFT_MONTH);

    expect(payrollDraftPeriod()->items()->count())->toBe($this->activeCount)
        ->and(PayrollItem::where('payroll_period_id', payrollDraftPeriod()->getKey())
            ->where('employee_id', $joiner->getKey())->count())->toBe(0)
        // A payroll that silently misses somebody is the failure this command exists to
        // prevent, so the names are in the output.
        ->and(Artisan::output())->toContain($joiner->user->name);
})->group('phase9');

it('skips somebody whose only salary starts after the month', function () {
    $joiner = Employee::factory()->create();
    $this->service->setSalary($this->admin, $joiner, '700.00', '0.00', '2026-12-01');

    payrollDraftRun(PAYROLL_DRAFT_MONTH);

    expect(PayrollItem::where('payroll_period_id', payrollDraftPeriod()->getKey())
        ->where('employee_id', $joiner->getKey())->count())->toBe(0);

    payrollDraftRun('2026-12-01');

    expect(PayrollItem::whereRelation('period', 'month', '2026-12-01')
        ->where('employee_id', $joiner->getKey())->sole()->base_salary)->toBe('700.00');
})->group('phase9');

it('includes the accountant, who is an employee too', function () {
    $accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();

    payrollDraftRun(PAYROLL_DRAFT_MONTH);

    expect(PayrollItem::where('payroll_period_id', payrollDraftPeriod()->getKey())
        ->where('employee_id', $accountant->employee->getKey())->count())->toBe(1)
        ->and($accountant->role())->toBe(RoleName::ACCOUNTANT);
})->group('phase9');

/*
|--------------------------------------------------------------------------
| Drafting by hand, which the command does not do
|--------------------------------------------------------------------------
*/

it('refuses a second draft of the same month when a person asks for one', function () {
    payrollDraftRun(PAYROLL_DRAFT_MONTH);

    // The command passes `onlyIfMissing` and stays quiet; a person gets a sentence.
    expect(fn () => $this->service->createDraft($this->admin, PAYROLL_DRAFT_MONTH))
        ->toThrow(PayrollStateException::class, 'already has a payroll period');
})->group('phase9');

it('lets the accountant draft a month by hand and refuses an ordinary employee', function () {
    $accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();
    $yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();

    expect($this->service->createDraft($accountant, '2027-01-01')->status)->toBe(PayrollStatus::Draft);

    expect(fn () => $this->service->createDraft($yaseen, '2027-02-01'))
        ->toThrow(AuthorizationException::class, 'not allowed to draft payroll');
})->group('phase9');

it('reads a month written any way round and keys it to the first', function (string $given) {
    payrollDraftRun($given);

    expect(payrollDraftPeriod()->month->toDateString())->toBe(PAYROLL_DRAFT_MONTH);
})->with([
    'the first' => '2026-11-01',
    'mid month' => '2026-11-17',
    'the last day' => '2026-11-30',
])->group('phase9');
