<?php

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeSalary;
use App\Models\User;
use App\Services\PayrollService;
use App\Support\AuditEvent;
use App\Support\RoleName;

/*
|--------------------------------------------------------------------------
| Salary settings — Admin only, effective-dated, audit-logged
|--------------------------------------------------------------------------
|
| Master prompt Part D §14 puts "salary settings per employee (base salary,
| allowances — audit-logged)" under Admin → Payroll, and `payroll.approve`
| is how that is spelled: `RolePermissionSeeder` grants it to ADMIN and to
| nobody else. So this file asserts, in order:
|
|   1. an Admin sets a salary and a NEW ROW appears with the right
|      `effective_from`, the old one untouched (decision 9-1);
|   2. the `salary.changed` audit row carries the old figures and the new;
|   3. 403 for the Accountant, a Manager, an Employee and a Remote employee
|      — a whole screen a role may not use is 403 and not 404 (Part B §3
|      rule 1 keeps the 404 for somebody's RECORD);
|   4. and the property the whole effective-dated design exists for: a
|      salary starting in November does not change what September computes.
|      That one is asserted THROUGH HTTP, because the defect it guards
|      against would be an UPDATE written in a controller, not in the
|      service the unit tests already cover.
|
| Every constant and helper here is prefixed SALARY_, because Pest declares
| a test file's constants and functions GLOBALLY across the whole suite
| (AGENTS.md) and a duplicate is a PHP warning, not an error.
|
*/

/** The seeded payroll month, and the day its figures are read as at. */
const SALARY_SEPTEMBER = '2026-09-01';

/** A raise that starts well after September — the one 9-1 is about. */
const SALARY_NOVEMBER = '2026-11-01';

/** A complete, valid body, so a refusal is the permission answering and not the validator. */
function salaryBody(string $base = '1500.00', string $allowance = '250.00', string $from = SALARY_NOVEMBER): array
{
    return [
        'base_salary' => $base,
        'allowance' => $allowance,
        'effective_from' => $from,
    ];
}

/** This user's row on the salary settings page. */
function salaryRowFor(array $props, User $user): array
{
    foreach ($props['employees'] as $row) {
        if ((int) $row['id'] === (int) $user->employee->getKey()) {
            return $row;
        }
    }

    throw new RuntimeException($user->name.' is not on the salary settings page.');
}

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();
    $this->manager = Employee::factory()->forRole(RoleName::MANAGER)->create()->user;
});

/*
|--------------------------------------------------------------------------
| The screen
|--------------------------------------------------------------------------
*/

it('shows an Admin every active employee with the salary in force today', function () {
    $response = $this->actingAs($this->admin)
        ->get('/salaries')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Shared/Salaries/Index'));

    $row = salaryRowFor($response->inertiaProps(), $this->tapu);

    // Tapu has TWO seeded rows — 900.00 from January and 1100.00 from July. "Current" is the
    // later of the two, because `salaryFor()` reads the latest row at or before today and not
    // the first row it finds.
    expect($row['current']['base_salary'])->toBe('1100.00')
        ->and($row['current']['effective_from'])->toBe('2026-07-01')
        // And the history is there, newest first, so "when did this change" is answerable
        // without opening the audit log.
        ->and($row['history'])->toHaveCount(2)
        ->and($row['history'][0]['effective_from'])->toBe('2026-07-01')
        ->and($row['history'][1]['effective_from'])->toBe('2026-01-01')
        ->and($row['history'][1]['base_salary'])->toBe('900.00');
})->group('phase9');

/*
|--------------------------------------------------------------------------
| 1. A new row, and the old one untouched
|--------------------------------------------------------------------------
*/

it('writes a new salary row and leaves the old one exactly as it was', function () {
    $before = EmployeeSalary::query()
        ->where('employee_id', $this->yaseen->employee->getKey())
        ->orderByDesc('effective_from')
        ->firstOrFail();

    $beforeSnapshot = [
        'id' => (int) $before->getKey(),
        'base_salary' => $before->base_salary,
        'allowance' => $before->allowance,
        'effective_from' => $before->effective_from->toDateString(),
    ];

    $this->actingAs($this->admin)
        ->put('/salaries/'.$this->yaseen->employee->getKey(), salaryBody('1500.00', '250.00', SALARY_NOVEMBER))
        ->assertRedirect()
        ->assertSessionHas('success');

    $rows = EmployeeSalary::query()
        ->where('employee_id', $this->yaseen->employee->getKey())
        ->orderBy('effective_from')
        ->get();

    expect($rows)->toHaveCount(2);

    $new = $rows->last();

    expect($new->base_salary)->toBe('1500.00')
        ->and($new->allowance)->toBe('250.00')
        ->and($new->effective_from->toDateString())->toBe(SALARY_NOVEMBER)
        ->and((int) $new->set_by)->toBe((int) $this->admin->getKey());

    // The old row is byte-for-byte what it was. An UPDATE here is the defect 9-1 exists to
    // prevent, and it would be invisible until September was recomputed months later.
    $old = $before->fresh();

    expect((int) $old->getKey())->toBe($beforeSnapshot['id'])
        ->and($old->base_salary)->toBe($beforeSnapshot['base_salary'])
        ->and($old->allowance)->toBe($beforeSnapshot['allowance'])
        ->and($old->effective_from->toDateString())->toBe($beforeSnapshot['effective_from']);
})->group('phase9');

/*
|--------------------------------------------------------------------------
| 2. The audit row, with both sides of the change
|--------------------------------------------------------------------------
*/

it('audit-logs a salary change with the old figures and the new', function () {
    expect(AuditLog::where('event', AuditEvent::SalaryChanged->value)->count())->toBe(0);

    $this->actingAs($this->admin)
        ->put('/salaries/'.$this->yaseen->employee->getKey(), salaryBody('1500.00', '250.00', SALARY_NOVEMBER))
        ->assertRedirect();

    $row = AuditLog::where('event', AuditEvent::SalaryChanged->value)->sole();

    expect((int) $row->actor_id)->toBe((int) $this->admin->getKey())
        // Part C §4: "salary changed" carries actor, old value and new value.
        ->and($row->old_value['base_salary'])->toBe('800.00')
        ->and($row->old_value['allowance'])->toBe('100.00')
        ->and($row->new_value['base_salary'])->toBe('1500.00')
        ->and($row->new_value['allowance'])->toBe('250.00')
        ->and($row->new_value['effective_from'])->toBe(SALARY_NOVEMBER)
        ->and((int) $row->new_value['employee_id'])->toBe((int) $this->yaseen->employee->getKey());
})->group('phase9');

it('writes exactly one audit row per change, and no second trail', function () {
    $this->actingAs($this->admin)
        ->put('/salaries/'.$this->yaseen->employee->getKey(), salaryBody())
        ->assertRedirect();

    expect(AuditLog::where('event', AuditEvent::SalaryChanged->value)->count())->toBe(1);
})->group('phase9');

/*
|--------------------------------------------------------------------------
| 3. 403 for everybody who is not an Admin
|--------------------------------------------------------------------------
*/

it('refuses the salary settings screen to every role but Admin', function (string $who) {
    $this->actingAs($this->{$who})->get('/salaries')->assertForbidden();
})->with([
    'accountant' => ['accountant'],
    'manager' => ['manager'],
    'employee' => ['yaseen'],
    'remote employee' => ['tapu'],
])->group('phase9');

it('refuses the salary write to every role but Admin', function (string $who) {
    $this->actingAs($this->{$who})
        ->put('/salaries/'.$this->yaseen->employee->getKey(), salaryBody())
        ->assertForbidden();

    // Nothing was written, and nothing was logged.
    expect(EmployeeSalary::where('employee_id', $this->yaseen->employee->getKey())->count())->toBe(1)
        ->and(AuditLog::where('event', AuditEvent::SalaryChanged->value)->count())->toBe(0);
})->with([
    'accountant' => ['accountant'],
    'manager' => ['manager'],
    'employee' => ['yaseen'],
    'remote employee' => ['tapu'],
])->group('phase9');

it('redirects a guest to login from the salary settings screen', function () {
    $this->get('/salaries')->assertRedirect('/login');
    $this->put('/salaries/1', salaryBody())->assertRedirect('/login');
})->group('phase9');

/*
|--------------------------------------------------------------------------
| 4. The property the whole design exists for (9-1)
|--------------------------------------------------------------------------
*/

it('does not let a November salary change what September computes', function () {
    $employee = $this->yaseen->employee;
    $payroll = app(PayrollService::class);

    $septemberBefore = $payroll->salaryFor($employee, SALARY_SEPTEMBER);

    expect($septemberBefore->base_salary)->toBe('800.00');

    $this->actingAs($this->admin)
        ->put('/salaries/'.$employee->getKey(), salaryBody('1500.00', '250.00', SALARY_NOVEMBER))
        ->assertRedirect();

    // September still reads September's row. This is the whole promise of effective-dated
    // rows: the November raise is simply not reachable by a query asked about September.
    $septemberAfter = $payroll->salaryFor($employee, SALARY_SEPTEMBER);

    expect($septemberAfter->base_salary)->toBe('800.00')
        ->and($septemberAfter->allowance)->toBe('100.00')
        ->and((int) $septemberAfter->getKey())->toBe((int) $septemberBefore->getKey());

    // November reads the new one.
    expect($payroll->salaryFor($employee, SALARY_NOVEMBER)->base_salary)->toBe('1500.00');

    // And over HTTP: the screen's "current" figure — read as at today, which is before
    // November — is still the old one, with the future raise sitting in the history beneath it.
    $props = $this->actingAs($this->admin)->get('/salaries')->assertOk()->inertiaProps();
    $row = salaryRowFor($props, $this->yaseen);

    expect($row['current']['base_salary'])->toBe('800.00')
        ->and($row['history'][0]['effective_from'])->toBe(SALARY_NOVEMBER)
        ->and($row['history'][0]['base_salary'])->toBe('1500.00');
})->group('phase9');

/*
|--------------------------------------------------------------------------
| Validation
|--------------------------------------------------------------------------
*/

it('refuses a salary without a start date, and one that is not money', function (array $body, string $field) {
    $this->actingAs($this->admin)
        ->put('/salaries/'.$this->yaseen->employee->getKey(), $body)
        ->assertSessionHasErrors($field);

    expect(EmployeeSalary::where('employee_id', $this->yaseen->employee->getKey())->count())->toBe(1);
})->with([
    'no start date' => [['base_salary' => '1500.00', 'allowance' => '0.00'], 'effective_from'],
    'zero base' => [['base_salary' => '0.00', 'allowance' => '0.00', 'effective_from' => SALARY_NOVEMBER], 'base_salary'],
    'three decimal places' => [['base_salary' => '1500.005', 'allowance' => '0.00', 'effective_from' => SALARY_NOVEMBER], 'base_salary'],
    'negative allowance' => [['base_salary' => '1500.00', 'allowance' => '-5.00', 'effective_from' => SALARY_NOVEMBER], 'allowance'],
])->group('phase9');
