<?php

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Schedule;
use App\Models\User;
use App\Services\AttendanceService;
use App\Support\AttendanceStatus;
use App\Support\RoleName;
use App\Support\UserStatus;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| hq:mark-absent — the 23:55 sweep
|--------------------------------------------------------------------------
|
| 2026-09-14 is a Monday and 2026-09-18 a Friday, and both are in the past —
| the sweep refuses a day that has not happened. The seeded schedules work
| Sunday to Thursday, so the Friday is the off day, but no test below assumes
| that: each one says what its employee's schedule is.
|
*/

const SWEEP_MONDAY = '2026-09-14';
const SWEEP_FRIDAY = '2026-09-18';

function scheduled(Employee $employee, array $days, ?string $start = '09:00'): Employee
{
    Schedule::updateOrCreate(['employee_id' => $employee->id], [
        'working_days' => $days,
        'working_hours_per_day' => '8.00',
        'start_time' => $start,
        'office_or_remote' => 'office',
    ]);

    return $employee->fresh(['schedule']);
}

it('marks a missed working day and leaves the Friday alone', function () {
    $employee = scheduled(
        Employee::factory()->forRole(RoleName::EMPLOYEE)->create(),
        ['sun', 'mon', 'tue', 'wed', 'thu'],
    );

    $this->artisan('hq:mark-absent', ['--as-of' => SWEEP_MONDAY])
        ->expectsOutputToContain('1 marked absent')
        ->assertSuccessful();

    $this->artisan('hq:mark-absent', ['--as-of' => SWEEP_FRIDAY])
        ->expectsOutputToContain('0 marked absent')
        ->assertSuccessful();

    $rows = AttendanceRecord::where('employee_id', $employee->id)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->date->toDateString())->toBe(SWEEP_MONDAY)
        ->and($rows->first()->status)->toBe(AttendanceStatus::Absent)
        // A swept row has no times and nobody's name on it. It is the absence of a day, not an
        // edited one.
        ->and($rows->first()->clock_in)->toBeNull()
        ->and($rows->first()->edited_by)->toBeNull();
});

it('marks a Friday for an employee whose own schedule works Fridays', function () {
    // The same date, the other schedule. This is the test that would fail if a working week
    // were hard-coded anywhere.
    $employee = scheduled(
        Employee::factory()->forRole(RoleName::EMPLOYEE)->create(),
        ['fri', 'sat'],
    );

    $this->artisan('hq:mark-absent', ['--as-of' => SWEEP_FRIDAY])->assertSuccessful();
    $this->artisan('hq:mark-absent', ['--as-of' => SWEEP_MONDAY])->assertSuccessful();

    expect(AttendanceRecord::where('employee_id', $employee->id)->pluck('date')
        ->map(fn ($date) => Carbon::parse($date)->toDateString())->all())
        ->toBe([SWEEP_FRIDAY]);
});

it('never marks Tapu absent, because his work is tracked by the timer', function () {
    $this->seed();

    $tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail()->employee;

    // A Monday: his schedule says he works, and he has no attendance record because he never
    // clocks in. The sweep must still leave him alone.
    $this->artisan('hq:mark-absent', ['--as-of' => SWEEP_MONDAY])->assertSuccessful();

    expect(AttendanceRecord::where('employee_id', $tapu->id)->exists())->toBeFalse();

    // And the roster says what he IS, rather than nothing.
    $day = app(AttendanceService::class)->dayFor($tapu->fresh(['schedule']), Carbon::parse(SWEEP_MONDAY));

    expect($day->status)->toBe(AttendanceStatus::Remote);
});

it('leaves the Accountant alone: no schedule, no office clock, no row', function () {
    $this->seed();

    $accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail()->employee;

    $this->artisan('hq:mark-absent', ['--as-of' => SWEEP_MONDAY])->assertSuccessful();

    expect($accountant->schedule)->toBeNull()
        ->and(AttendanceRecord::where('employee_id', $accountant->id)->exists())->toBeFalse();
});

it('leaves a day that already has a record alone, whatever wrote it', function () {
    $employee = scheduled(
        Employee::factory()->forRole(RoleName::EMPLOYEE)->create(),
        ['sun', 'mon', 'tue', 'wed', 'thu'],
    );

    app(AttendanceService::class)->clockIn($employee, Carbon::parse(SWEEP_MONDAY)->setTime(8, 58));

    $this->artisan('hq:mark-absent', ['--as-of' => SWEEP_MONDAY])
        ->expectsOutputToContain('0 marked absent')
        ->assertSuccessful();

    $rows = AttendanceRecord::where('employee_id', $employee->id)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->status)->toBe(AttendanceStatus::Present);
});

it('is safe to run twice: the unique index, not the check, is the guarantee', function () {
    $employee = scheduled(
        Employee::factory()->forRole(RoleName::EMPLOYEE)->create(),
        ['sun', 'mon', 'tue', 'wed', 'thu'],
    );

    $this->artisan('hq:mark-absent', ['--as-of' => SWEEP_MONDAY])->assertSuccessful();
    $this->artisan('hq:mark-absent', ['--as-of' => SWEEP_MONDAY])
        ->expectsOutputToContain('0 marked absent')
        ->assertSuccessful();

    expect(AttendanceRecord::where('employee_id', $employee->id)->count())->toBe(1);

    // And the database refuses a second row even when the service is bypassed entirely.
    expect(fn () => AttendanceRecord::factory()->forEmployee($employee)->on(Carbon::parse(SWEEP_MONDAY))->create())
        ->toThrow(QueryException::class);
});

it('skips a deactivated employee', function () {
    $employee = scheduled(
        Employee::factory()->forRole(RoleName::EMPLOYEE)->create(['status' => UserStatus::Inactive]),
        ['sun', 'mon', 'tue', 'wed', 'thu'],
    );

    $this->artisan('hq:mark-absent', ['--as-of' => SWEEP_MONDAY])
        ->expectsOutputToContain('0 office employees considered')
        ->assertSuccessful();

    expect(AttendanceRecord::where('employee_id', $employee->id)->exists())->toBeFalse();
});

it('refuses to sweep a day that has not happened yet', function () {
    scheduled(
        Employee::factory()->forRole(RoleName::EMPLOYEE)->create(),
        ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'],
    );

    $this->artisan('hq:mark-absent', ['--as-of' => Carbon::tomorrow()->toDateString()])
        ->assertFailed();

    expect(AttendanceRecord::count())->toBe(0);
});

it('leaves the Phase 5 skip in one place', function () {
    // The seam: markAbsent() asks coveredByLeaveOrHoliday() for every candidate, so Phase 5
    // fills that one method in and this sweep starts skipping approved leave and holidays with
    // no other change. Proved by faking the answer rather than by reading the file.
    $employee = scheduled(
        Employee::factory()->forRole(RoleName::EMPLOYEE)->create(),
        ['sun', 'mon', 'tue', 'wed', 'thu'],
    );

    $this->mock(AttendanceService::class, function ($mock) use ($employee): void {
        $mock->shouldReceive('markAbsent')
            ->once()
            ->andReturnUsing(fn (Employee $candidate, $date): bool => $candidate->is($employee) ? false : true);
    });

    $this->artisan('hq:mark-absent', ['--as-of' => SWEEP_MONDAY])
        ->expectsOutputToContain('0 marked absent')
        ->assertSuccessful();

    expect(AttendanceRecord::count())->toBe(0);
});
