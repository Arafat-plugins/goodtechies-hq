<?php

use App\Exceptions\PayrollStateException;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeBonus;
use App\Models\PayrollItem;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Services\BonusService;
use App\Services\FinanceService;
use App\Services\PayrollService;
use App\Services\SettingsService;
use App\Support\AuditEvent;
use App\Support\BonusPayout;
use App\Support\BonusStatus;
use App\Support\Currency;
use App\Support\PayrollStatus;
use App\Support\RoleName;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Bonuses — records that feed payroll_items.bonus
|--------------------------------------------------------------------------
|
| Eid, annual, performance, special reward and custom bonuses. An approved
| bonus dated in a payroll month is linked to that month's period and its
| amount is summed into the employee's line. Pending and cancelled bonuses
| count for nothing; locked and paid months are never changed.
|
| Helpers are prefixed bonusTest*, because Pest declares them globally.
|
*/

const BONUS_TEST_MONTH = '2026-09-01';

function bonusTestUser(string $email): User
{
    return User::where('email', $email)->firstOrFail();
}

function bonusTestItemFor(string $name): PayrollItem
{
    return PayrollItem::query()
        ->whereHas('employee.user', fn ($query) => $query->where('name', $name))
        ->whereHas('period', fn ($query) => $query->forMonth(BONUS_TEST_MONTH))
        ->firstOrFail();
}

function bonusTestRow(int $itemId): object
{
    return DB::table('payroll_items')->where('id', $itemId)->first(['bonus', 'base_salary', 'net_salary']);
}

/** @param  array<string, mixed>  $overrides */
function bonusTestBody(array $employeeIds, array $overrides = []): array
{
    return [
        'employee_ids' => $employeeIds,
        'type' => 'eid',
        'title' => null,
        'amount' => '5000',
        'currency' => Currency::default()->value,
        'payment_date' => '2026-09-15',
        'reason' => 'Eid-ul-Adha',
        'status' => 'approved',
        ...$overrides,
    ];
}

function bonusTestWalkTo(PayrollStatus $status): PayrollPeriod
{
    $admin = bonusTestUser('shahadat@goodtechies.test');
    $accountant = bonusTestUser('accountant@goodtechies.test');
    $service = app(PayrollService::class);
    $period = PayrollPeriod::query()->forMonth(BONUS_TEST_MONTH)->firstOrFail();

    $service->calculate($accountant, $period);
    $service->review($admin, $period->refresh());
    $service->approve($admin, $period->refresh());
    $service->lock($admin, $period->refresh());

    if ($status === PayrollStatus::Paid) {
        $service->markPaid($admin, $period->refresh());
    }

    return $period->refresh();
}

beforeEach(function () {
    $this->seed();

    Carbon::setTestNow(Carbon::parse('2026-09-20 10:00:00', config('app.timezone')));

    $this->admin = bonusTestUser('shahadat@goodtechies.test');
    $this->accountant = bonusTestUser('accountant@goodtechies.test');
    $this->employee = bonusTestUser('yaseen@goodtechies.test');
    $this->manager = Employee::factory()->forRole(RoleName::MANAGER)->create()->user;

    $this->yaseenItem = bonusTestItemFor('Yaseen');
    $this->accountantItem = PayrollItem::query()
        ->where('employee_id', $this->accountant->employee->getKey())
        ->whereHas('period', fn ($query) => $query->forMonth(BONUS_TEST_MONTH))
        ->firstOrFail();
    $this->bonuses = app(BonusService::class);
    $this->payroll = app(PayrollService::class);
});

afterEach(function () {
    Carbon::setTestNow();
});

test('an admin creates an Eid bonus for two employees, approved now, and both lines carry it', function () {
    $ids = [(int) $this->yaseenItem->employee_id, (int) $this->accountantItem->employee_id];
    $before = bonusTestRow($this->yaseenItem->getKey());

    $this->actingAs($this->admin)
        ->post('/payroll/bonuses', bonusTestBody($ids))
        ->assertRedirect()
        ->assertSessionHas('success', 'Bonus saved for 2 employee(s).');

    expect(EmployeeBonus::query()->whereIn('employee_id', $ids)->where('status', 'approved')->count())->toBe(2);

    $after = bonusTestRow($this->yaseenItem->getKey());
    expect($after->bonus)->toBe('5000.00')
        ->and($after->base_salary)->toBe($before->base_salary)
        ->and(number_format((float) $before->net_salary + 5000, 2, '.', ''))->toBe($after->net_salary)
        ->and(bonusTestRow($this->accountantItem->getKey())->bonus)->toBe('5000.00');

    expect(AuditLog::query()->where('event', AuditEvent::BonusCreated->value)->count())->toBe(2);
});

test('a pending bonus is not counted until approved, and cancelling removes it with a reason', function () {
    $id = (int) $this->yaseenItem->employee_id;

    $this->actingAs($this->admin)->post('/payroll/bonuses', bonusTestBody([$id], ['status' => 'pending', 'amount' => '750']));
    $bonus = EmployeeBonus::query()->where('employee_id', $id)->firstOrFail();

    expect($bonus->status)->toBe(BonusStatus::Pending)
        ->and(bonusTestRow($this->yaseenItem->getKey())->bonus)->toBe('0.00');

    $this->actingAs($this->admin)->post("/payroll/bonuses/{$bonus->id}/approve")->assertSessionHas('success', 'Bonus approved.');
    expect(bonusTestRow($this->yaseenItem->getKey())->bonus)->toBe('750.00');

    $this->actingAs($this->admin)
        ->post("/payroll/bonuses/{$bonus->id}/cancel", ['reason' => ''])
        ->assertSessionHasErrors('reason');
    expect(bonusTestRow($this->yaseenItem->getKey())->bonus)->toBe('750.00');

    $this->actingAs($this->admin)
        ->post("/payroll/bonuses/{$bonus->id}/cancel", ['reason' => 'Entered twice'])
        ->assertSessionHas('success', 'Bonus cancelled.');

    $bonus->refresh();
    expect($bonus->status)->toBe(BonusStatus::Cancelled)
        ->and($bonus->payroll_period_id)->toBeNull()
        ->and($bonus->cancellation_reason)->toBe('Entered twice')
        ->and(bonusTestRow($this->yaseenItem->getKey())->bonus)->toBe('0.00');

    expect(fn () => $this->bonuses->cancel($this->admin, EmployeeBonus::create([
        'employee_id' => $id, 'type' => 'annual', 'amount' => '10', 'currency' => Currency::default()->value,
        'payment_date' => '2026-09-10', 'status' => 'approved',
    ]), '   '))->toThrow(PayrollStateException::class);
});

test('two bonuses in one month sum, and a bonus dated next month is not in this month', function () {
    $id = (int) $this->yaseenItem->employee_id;

    $this->actingAs($this->admin)->post('/payroll/bonuses', bonusTestBody([$id], ['amount' => '100.25']));
    $this->actingAs($this->admin)->post('/payroll/bonuses', bonusTestBody([$id], ['type' => 'performance', 'amount' => '200.50']));
    $this->actingAs($this->admin)->post('/payroll/bonuses', bonusTestBody([$id], ['type' => 'annual', 'amount' => '999', 'payment_date' => '2026-10-02']));

    expect(bonusTestRow($this->yaseenItem->getKey())->bonus)->toBe('300.75');

    $october = EmployeeBonus::query()->where('type', 'annual')->where('employee_id', $id)->firstOrFail();
    expect($october->payroll_period_id)->toBeNull();
});

test('calculating twice and syncing twice never counts a bonus twice', function () {
    $id = (int) $this->yaseenItem->employee_id;
    $this->actingAs($this->admin)->post('/payroll/bonuses', bonusTestBody([$id], ['amount' => '400']));

    $period = PayrollPeriod::query()->forMonth(BONUS_TEST_MONTH)->firstOrFail();

    $this->payroll->calculate($this->accountant, $period);
    $this->payroll->calculate($this->accountant, $period->refresh());
    $this->bonuses->syncPeriodFor(Carbon::parse(BONUS_TEST_MONTH));
    $this->bonuses->syncPeriodFor(Carbon::parse(BONUS_TEST_MONTH));

    expect(bonusTestRow($this->yaseenItem->getKey())->bonus)->toBe('400.00');
});

test('marking the month paid pays its approved bonuses, and a closed month refuses every bonus change', function () {
    $id = (int) $this->yaseenItem->employee_id;
    $this->actingAs($this->admin)->post('/payroll/bonuses', bonusTestBody([$id], ['amount' => '400']));
    $this->actingAs($this->admin)->post('/payroll/bonuses', bonusTestBody([$id], ['type' => 'annual', 'amount' => '50', 'status' => 'pending']));
    $approved = EmployeeBonus::query()->where('employee_id', $id)->where('type', 'eid')->firstOrFail();
    $pending = EmployeeBonus::query()->where('employee_id', $id)->where('type', 'annual')->firstOrFail();

    bonusTestWalkTo(PayrollStatus::Locked);
    $count = EmployeeBonus::query()->count();

    // Locked: create, edit, approve and cancel are each refused with a sentence.
    $this->actingAs($this->admin)
        ->post('/payroll/bonuses', bonusTestBody([(int) $this->accountantItem->employee_id], ['amount' => '10']))
        ->assertSessionHas('error', fn (string $message) => str_contains($message, 'The payroll for September 2026 is locked.'));
    $this->actingAs($this->admin)
        ->put("/payroll/bonuses/{$approved->id}", ['type' => 'eid', 'amount' => '999', 'currency' => Currency::default()->value, 'payment_date' => '2026-09-15'])
        ->assertSessionHas('error');
    $this->actingAs($this->admin)->post("/payroll/bonuses/{$pending->id}/approve")->assertSessionHas('error');
    $this->actingAs($this->admin)->post("/payroll/bonuses/{$approved->id}/cancel", ['reason' => 'x'])->assertSessionHas('error');

    expect(EmployeeBonus::query()->count())->toBe($count)
        ->and($approved->refresh()->amount)->toBe('400.00')
        ->and($approved->status)->toBe(BonusStatus::Approved)
        ->and($pending->refresh()->status)->toBe(BonusStatus::Pending)
        ->and(bonusTestRow($this->yaseenItem->getKey())->bonus)->toBe('400.00');

    $period = PayrollPeriod::query()->forMonth(BONUS_TEST_MONTH)->firstOrFail();
    $this->payroll->markPaid($this->admin, $period);

    expect($approved->refresh()->status)->toBe(BonusStatus::Paid)
        ->and($pending->refresh()->status)->toBe(BonusStatus::Pending)
        ->and(bonusTestRow($this->yaseenItem->getKey())->bonus)->toBe('400.00');

    $this->actingAs($this->admin)
        ->post('/payroll/bonuses', bonusTestBody([(int) $this->accountantItem->employee_id], ['amount' => '10']))
        ->assertSessionHas('error', fn (string $message) => str_contains($message, 'is paid.'));
    $this->actingAs($this->admin)->post("/payroll/bonuses/{$pending->id}/approve")->assertSessionHas('error');
    expect($pending->refresh()->status)->toBe(BonusStatus::Pending);
});

test('the same bonus posted twice for the same employee is created once and the flash names who was skipped', function () {
    $id = (int) $this->yaseenItem->employee_id;

    $this->actingAs($this->admin)->post('/payroll/bonuses', bonusTestBody([$id]));
    $this->actingAs($this->admin)
        ->post('/payroll/bonuses', bonusTestBody([$id]))
        ->assertSessionHas('success', 'Bonus saved for 0 employee(s). Skipped Yaseen: the same bonus already exists.');

    expect(EmployeeBonus::query()->where('employee_id', $id)->count())->toBe(1)
        ->and(bonusTestRow($this->yaseenItem->getKey())->bonus)->toBe('5000.00');
});

test('a custom bonus needs a title, the currency must be the payroll currency and the amount must be positive', function () {
    $id = (int) $this->yaseenItem->employee_id;
    $other = Currency::default() === Currency::USD ? 'BDT' : 'USD';

    $this->actingAs($this->admin)->post('/payroll/bonuses', bonusTestBody([$id], ['type' => 'custom', 'title' => '']))
        ->assertSessionHasErrors('title');
    $this->actingAs($this->admin)->post('/payroll/bonuses', bonusTestBody([$id], ['currency' => $other]))
        ->assertSessionHasErrors(['currency' => 'Bonuses are paid with payroll, in '.Currency::default()->value.'.']);
    $this->actingAs($this->admin)->post('/payroll/bonuses', bonusTestBody([$id], ['amount' => '0']))
        ->assertSessionHasErrors('amount');

    expect(EmployeeBonus::query()->where('employee_id', $id)->count())->toBe(0);
});

test('the accountant creates pending bonuses only and cannot approve; employees and managers are refused', function () {
    $id = (int) $this->yaseenItem->employee_id;

    $this->actingAs($this->accountant)->get('/payroll/bonuses')->assertOk();
    $this->actingAs($this->accountant)->post('/payroll/bonuses', bonusTestBody([$id]))->assertSessionHas('success');

    $bonus = EmployeeBonus::query()->where('employee_id', $id)->firstOrFail();
    expect($bonus->status)->toBe(BonusStatus::Pending)
        ->and(bonusTestRow($this->yaseenItem->getKey())->bonus)->toBe('0.00');

    $this->actingAs($this->accountant)->post("/payroll/bonuses/{$bonus->id}/approve")->assertForbidden();
    expect($bonus->refresh()->status)->toBe(BonusStatus::Pending);

    foreach ([$this->employee, $this->manager] as $user) {
        $this->actingAs($user)->get('/payroll/bonuses')->assertForbidden();
        $this->actingAs($user)->post('/payroll/bonuses', bonusTestBody([$id]))->assertForbidden();
    }
});

test('an employee sees their own bonus lines on their payslip and never another employee\'s', function () {
    $mine = (int) $this->yaseenItem->employee_id;
    $theirs = (int) $this->accountantItem->employee_id;

    $this->actingAs($this->admin)->post('/payroll/bonuses', bonusTestBody([$mine], ['amount' => '120']));
    $this->actingAs($this->admin)->post('/payroll/bonuses', bonusTestBody([$theirs], ['type' => 'reward', 'amount' => '777']));

    $page = $this->actingAs($this->employee)
        ->get('/payslip/'.$this->yaseenItem->getKey())
        ->assertOk()
        ->viewData('page');

    $json = json_encode($page['props']);
    expect($json)->toContain('Eid bonus')
        ->and($json)->not->toContain('Special reward')
        ->and($json)->not->toContain('777');

    $this->actingAs($this->employee)->get('/payslip/'.$this->accountantItem->getKey())->assertNotFound();
});

test('every seeded item with a bonus has approved or paid records that sum to it', function () {
    $items = PayrollItem::query()->where('bonus', '>', 0)->get();

    expect($items)->not->toBeEmpty();

    foreach ($items as $item) {
        expect(number_format((float) $item->linkedBonuses()->sum('amount'), 2, '.', ''))->toBe((string) $item->bonus);
    }
});

test('adjusting an item with a bonus key no longer changes its bonus', function () {
    $id = (int) $this->yaseenItem->employee_id;
    $this->actingAs($this->admin)->post('/payroll/bonuses', bonusTestBody([$id], ['amount' => '250']));

    $this->payroll->adjustItem($this->admin, $this->yaseenItem->refresh(), ['bonus' => '9999.00', 'deduction' => '10.00']);

    $row = bonusTestRow($this->yaseenItem->getKey());
    expect($row->bonus)->toBe('250.00');
    expect(DB::table('payroll_items')->where('id', $this->yaseenItem->getKey())->value('deduction'))->toBe('10.00');
});

test('the accountant cannot edit or cancel an approved bonus, but can still edit and cancel a pending one', function () {
    $id = (int) $this->yaseenItem->employee_id;
    $this->actingAs($this->admin)->post('/payroll/bonuses', bonusTestBody([$id], ['amount' => '400']));
    $this->actingAs($this->accountant)->post('/payroll/bonuses', bonusTestBody([$id], ['type' => 'annual', 'amount' => '50']));
    $approved = EmployeeBonus::query()->where('employee_id', $id)->where('type', 'eid')->firstOrFail();
    $pending = EmployeeBonus::query()->where('employee_id', $id)->where('type', 'annual')->firstOrFail();
    expect($approved->status)->toBe(BonusStatus::Approved)->and($pending->status)->toBe(BonusStatus::Pending);

    $this->actingAs($this->accountant)
        ->put("/payroll/bonuses/{$approved->id}", ['type' => 'eid', 'amount' => '999', 'currency' => Currency::default()->value, 'payment_date' => '2026-09-15'])
        ->assertForbidden();
    $this->actingAs($this->accountant)->post("/payroll/bonuses/{$approved->id}/cancel", ['reason' => 'x'])->assertForbidden();

    expect($approved->refresh()->amount)->toBe('400.00')
        ->and($approved->status)->toBe(BonusStatus::Approved)
        ->and(bonusTestRow($this->yaseenItem->getKey())->bonus)->toBe('400.00');

    $this->actingAs($this->accountant)
        ->put("/payroll/bonuses/{$pending->id}", ['type' => 'annual', 'amount' => '75', 'currency' => Currency::default()->value, 'payment_date' => '2026-09-15'])
        ->assertSessionHas('success');
    expect($pending->refresh()->amount)->toBe('75.00');

    $this->actingAs($this->accountant)->post("/payroll/bonuses/{$pending->id}/cancel", ['reason' => 'Wrong person'])
        ->assertSessionHas('success');
    expect($pending->refresh()->status)->toBe(BonusStatus::Cancelled);
});

test('once the admin approves the month, the accountant cannot create, edit or cancel its bonuses, but the admin can still add one', function () {
    $id = (int) $this->yaseenItem->employee_id;
    $this->actingAs($this->accountant)->post('/payroll/bonuses', bonusTestBody([$id], ['type' => 'annual', 'amount' => '50']));
    $pending = EmployeeBonus::query()->where('employee_id', $id)->where('type', 'annual')->firstOrFail();

    $period = PayrollPeriod::query()->forMonth(BONUS_TEST_MONTH)->firstOrFail();
    $this->payroll->calculate($this->accountant, $period);
    $this->payroll->review($this->admin, $period->refresh());
    $this->payroll->approve($this->admin, $period->refresh());
    expect($period->refresh()->status)->toBe(PayrollStatus::Approved);

    $count = EmployeeBonus::query()->count();
    $before = bonusTestRow($this->yaseenItem->getKey())->bonus;
    $message = 'The payroll for September 2026 is already approved. Only an Admin can change its bonuses now.';

    $this->actingAs($this->accountant)
        ->post('/payroll/bonuses', bonusTestBody([(int) $this->accountantItem->employee_id], ['amount' => '10']))
        ->assertSessionHas('error', $message);
    $this->actingAs($this->accountant)
        ->put("/payroll/bonuses/{$pending->id}", ['type' => 'annual', 'amount' => '999', 'currency' => Currency::default()->value, 'payment_date' => '2026-09-15'])
        ->assertSessionHas('error', $message);
    $this->actingAs($this->accountant)->post("/payroll/bonuses/{$pending->id}/cancel", ['reason' => 'x'])
        ->assertSessionHas('error', $message);

    expect(EmployeeBonus::query()->count())->toBe($count)
        ->and($pending->refresh()->amount)->toBe('50.00')
        ->and($pending->status)->toBe(BonusStatus::Pending)
        ->and(bonusTestRow($this->yaseenItem->getKey())->bonus)->toBe($before);

    $this->actingAs($this->admin)
        ->post('/payroll/bonuses', bonusTestBody([$id], ['amount' => '300']))
        ->assertSessionHas('success');

    expect(bonusTestRow($this->yaseenItem->getKey())->bonus)
        ->toBe(number_format((float) $before + 300, 2, '.', ''));
});

test('changing the currency setting never unlinks a bonus that is already linked', function () {
    $id = (int) $this->yaseenItem->employee_id;
    $this->actingAs($this->admin)->post('/payroll/bonuses', bonusTestBody([$id], ['amount' => '400']));
    $bonus = EmployeeBonus::query()->where('employee_id', $id)->firstOrFail();
    $periodId = $bonus->payroll_period_id;
    expect($periodId)->not->toBeNull()
        ->and(bonusTestRow($this->yaseenItem->getKey())->bonus)->toBe('400.00');

    $other = Currency::default() === Currency::USD ? 'BDT' : 'USD';
    app(SettingsService::class)->set('currency', $other, $this->admin);
    expect(Currency::default()->value)->toBe($other);

    $this->bonuses->syncPeriodFor(Carbon::parse(BONUS_TEST_MONTH));

    expect($bonus->refresh()->payroll_period_id)->toBe($periodId)
        ->and(bonusTestRow($this->yaseenItem->getKey())->bonus)->toBe('400.00');
});

/*
| Bonuses paid separately — paid now, outside payroll, never on a payroll item.
*/

test('with the month paid, the admin pays a separate bonus today and no payroll item changes', function () {
    bonusTestWalkTo(PayrollStatus::Paid);
    $period = PayrollPeriod::query()->forMonth(BONUS_TEST_MONTH)->firstOrFail();
    $before = DB::table('payroll_items')->where('payroll_period_id', $period->getKey())->orderBy('id')->pluck('bonus', 'id')->all();
    $id = (int) $this->yaseenItem->employee_id;

    $this->actingAs($this->admin)
        ->post('/payroll/bonuses', bonusTestBody([$id], ['type' => 'custom', 'title' => 'Wedding gift', 'amount' => '20000', 'payout' => 'separate', 'status' => 'paid']))
        ->assertRedirect()
        ->assertSessionHas('success', 'Bonus saved for 1 employee(s).');

    $bonus = EmployeeBonus::query()->where('employee_id', $id)->where('title', 'Wedding gift')->firstOrFail();

    expect($bonus->status)->toBe(BonusStatus::Paid)
        ->and($bonus->payout)->toBe(BonusPayout::Separate)
        ->and($bonus->paid_at)->not->toBeNull()
        ->and($bonus->payroll_period_id)->toBeNull()
        ->and(DB::table('payroll_items')->where('payroll_period_id', $period->getKey())->orderBy('id')->pluck('bonus', 'id')->all())->toBe($before);
});

test('an approved separate bonus is marked paid; a bonus paid with salary is refused, and the accountant gets 403', function () {
    $id = (int) $this->yaseenItem->employee_id;
    $this->actingAs($this->admin)->post('/payroll/bonuses', bonusTestBody([$id], ['amount' => '700', 'payout' => 'separate', 'status' => 'approved']));
    $this->actingAs($this->admin)->post('/payroll/bonuses', bonusTestBody([$id], ['amount' => '400']));
    $separate = EmployeeBonus::query()->where('employee_id', $id)->where('payout', 'separate')->firstOrFail();
    $withSalary = EmployeeBonus::query()->where('employee_id', $id)->where('payout', 'with_payroll')->firstOrFail();

    expect($separate->status)->toBe(BonusStatus::Approved)
        ->and($separate->payroll_period_id)->toBeNull()
        ->and(bonusTestRow($this->yaseenItem->getKey())->bonus)->toBe('400.00');

    $this->actingAs($this->accountant)->post("/payroll/bonuses/{$separate->id}/paid")->assertForbidden();
    expect($separate->refresh()->status)->toBe(BonusStatus::Approved);

    $this->actingAs($this->admin)
        ->post("/payroll/bonuses/{$separate->id}/paid")
        ->assertRedirect()
        ->assertSessionHas('success', 'Bonus marked paid.');

    expect($separate->refresh()->status)->toBe(BonusStatus::Paid)
        ->and($separate->paid_at)->not->toBeNull()
        ->and(AuditLog::query()->where('event', AuditEvent::BonusPaid->value)->count())->toBe(1);

    $this->actingAs($this->admin)
        ->post("/payroll/bonuses/{$withSalary->id}/paid")
        ->assertSessionHas('error', 'Only an approved bonus paid separately can be marked paid here. Bonuses paid with salary are paid when the payroll month is marked paid.');

    expect($withSalary->refresh()->status)->toBe(BonusStatus::Approved)
        ->and(bonusTestRow($this->yaseenItem->getKey())->bonus)->toBe('400.00');
});

test('the accountant creates a separate bonus only as pending, even in a closed month', function () {
    bonusTestWalkTo(PayrollStatus::Locked);
    $id = (int) $this->yaseenItem->employee_id;

    $this->actingAs($this->accountant)
        ->post('/payroll/bonuses', bonusTestBody([$id], ['amount' => '111', 'payout' => 'separate', 'status' => 'paid']))
        ->assertSessionHas('success');
    $this->actingAs($this->accountant)
        ->post('/payroll/bonuses', bonusTestBody([$id], ['amount' => '222', 'payout' => 'separate', 'status' => 'approved']))
        ->assertSessionHas('success');

    $created = EmployeeBonus::query()->where('employee_id', $id)->where('payout', 'separate')->get();

    expect($created)->toHaveCount(2)
        ->and($created->every(fn (EmployeeBonus $bonus): bool => $bonus->status === BonusStatus::Pending
            && $bonus->paid_at === null
            && $bonus->payroll_period_id === null))->toBeTrue();
});

test('syncing never links a separate bonus, and switching an approved bonus to separate takes it off the item', function () {
    $id = (int) $this->yaseenItem->employee_id;
    $this->actingAs($this->admin)->post('/payroll/bonuses', bonusTestBody([$id], ['amount' => '900', 'payout' => 'separate']));
    $separate = EmployeeBonus::query()->where('employee_id', $id)->where('payout', 'separate')->firstOrFail();

    $this->bonuses->syncPeriodFor(Carbon::parse(BONUS_TEST_MONTH));
    $this->bonuses->syncPeriodFor(Carbon::parse(BONUS_TEST_MONTH));

    expect($separate->refresh()->status)->toBe(BonusStatus::Approved)
        ->and($separate->payroll_period_id)->toBeNull()
        ->and(bonusTestRow($this->yaseenItem->getKey())->bonus)->toBe('0.00');

    $this->actingAs($this->admin)->post('/payroll/bonuses', bonusTestBody([$id], ['amount' => '400']));
    $bonus = EmployeeBonus::query()->where('employee_id', $id)->where('payout', 'with_payroll')->firstOrFail();
    expect($bonus->payroll_period_id)->not->toBeNull()
        ->and(bonusTestRow($this->yaseenItem->getKey())->bonus)->toBe('400.00');

    $this->actingAs($this->admin)
        ->put("/payroll/bonuses/{$bonus->id}", ['type' => 'eid', 'amount' => '400', 'currency' => Currency::default()->value, 'payment_date' => '2026-09-15', 'payout' => 'separate'])
        ->assertSessionHas('success', 'Bonus updated.');

    expect($bonus->refresh()->payout)->toBe(BonusPayout::Separate)
        ->and($bonus->payroll_period_id)->toBeNull()
        ->and(bonusTestRow($this->yaseenItem->getKey())->bonus)->toBe('0.00');
});

test('the finance rollup counts paid separate bonuses once as their own expense line', function () {
    $finance = app(FinanceService::class);
    $before = $finance->monthlyRollup($this->admin, 2026, 9);
    $id = (int) $this->yaseenItem->employee_id;
    $other = (int) $this->accountantItem->employee_id;

    $this->actingAs($this->admin)->post('/payroll/bonuses', bonusTestBody([$id, $other], ['amount' => '1000', 'payout' => 'separate', 'status' => 'paid']));
    $this->actingAs($this->admin)->post('/payroll/bonuses', bonusTestBody([$id], ['amount' => '70', 'payout' => 'separate', 'status' => 'approved']));
    $this->actingAs($this->admin)->post('/payroll/bonuses', bonusTestBody([$id], ['amount' => '30', 'payout' => 'separate', 'status' => 'pending']));

    $after = $finance->monthlyRollup($this->admin, 2026, 9);
    $default = collect($after['by_currency'])->firstWhere('currency', Currency::default()->value);
    $lines = collect($default['expenses']['categories'])->where('source', 'bonus')->values();

    expect($lines)->toHaveCount(1)
        ->and($lines[0]['name'])->toBe('Bonuses (paid separately)')
        ->and($lines[0]['total'])->toBe('2000.00')
        ->and($after['payroll'])->toBe($before['payroll'])
        ->and(collect($after['bdt']['expenses']['categories'])->where('source', 'bonus')->sum(fn (array $line): float => (float) $line['total']))->toBe(2000.0)
        ->and(collect($before['by_currency'])->flatMap(fn (array $entry) => $entry['expenses']['categories'])->where('source', 'bonus'))->toHaveCount(0);
});

test('a bonus paid with salary cannot be created as paid', function () {
    $this->actingAs($this->admin)
        ->post('/payroll/bonuses', bonusTestBody([(int) $this->yaseenItem->employee_id], ['status' => 'paid']))
        ->assertSessionHasErrors(['status' => 'Only a bonus paid separately can be marked paid when it is created.']);

    expect(EmployeeBonus::query()->where('employee_id', $this->yaseenItem->employee_id)->count())->toBe(0);
});
