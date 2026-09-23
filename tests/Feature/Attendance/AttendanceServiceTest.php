<?php

use App\Exceptions\AttendanceStateException;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Schedule;
use App\Models\Setting;
use App\Services\AttendanceService;
use App\Support\AttendanceStatus;
use App\Support\RoleName;
use App\Support\TrackingMode;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| AttendanceService — the four predicates and the two clocks
|--------------------------------------------------------------------------
|
| Every status below is derived from the employee's OWN schedule. No test here
| hard-codes a working week: each one says which days its employee works and
| then asserts what the service makes of a date. That is the rule being tested
| as much as the answer.
|
| The fixed dates are real weekdays: 2026-09-21 is a Monday and 2026-09-25 a
| Friday. They are written down rather than computed so that a reader can check
| the arithmetic without running anything.
|
*/

const ATTENDANCE_MONDAY = '2026-09-21';
const ATTENDANCE_FRIDAY = '2026-09-25';

function officeEmployee(array $schedule = []): Employee
{
    $employee = Employee::factory()->forRole(RoleName::EMPLOYEE)->create();

    Schedule::create([
        'employee_id' => $employee->id,
        'working_days' => $schedule['working_days'] ?? ['sun', 'mon', 'tue', 'wed', 'thu'],
        'working_hours_per_day' => $schedule['working_hours_per_day'] ?? '8.00',
        'start_time' => array_key_exists('start_time', $schedule) ? $schedule['start_time'] : '09:00',
        'office_or_remote' => $schedule['office_or_remote'] ?? 'office',
    ]);

    return $employee->fresh(['schedule']);
}

function attendance(): AttendanceService
{
    return app(AttendanceService::class);
}

it('is a working day only when the employee\'s own schedule says so', function () {
    $monday = Carbon::parse(ATTENDANCE_MONDAY);
    $friday = Carbon::parse(ATTENDANCE_FRIDAY);

    $weekday = officeEmployee(['working_days' => ['sun', 'mon', 'tue', 'wed', 'thu']]);
    // The same two dates against a schedule that works Fridays and rests Mondays: the answers
    // swap, which is what "the working week is data, not a constant" means.
    $weekend = officeEmployee(['working_days' => ['fri', 'sat']]);

    expect(attendance()->isWorkingDay($weekday->schedule, $monday))->toBeTrue()
        ->and(attendance()->isWorkingDay($weekday->schedule, $friday))->toBeFalse()
        ->and(attendance()->isWorkingDay($weekend->schedule, $monday))->toBeFalse()
        ->and(attendance()->isWorkingDay($weekend->schedule, $friday))->toBeTrue();
});

it('treats an employee with no schedule as working no days', function () {
    expect(attendance()->isWorkingDay(null, Carbon::parse(ATTENDANCE_MONDAY)))->toBeFalse();
});

it('derives Off Day from the schedule and never stores it', function () {
    $employee = officeEmployee();

    $day = attendance()->dayFor($employee, Carbon::parse(ATTENDANCE_FRIDAY));

    expect($day->status)->toBe(AttendanceStatus::OffDay)
        ->and($day->scheduled)->toBeFalse()
        ->and($day->hasRecord())->toBeFalse()
        // The derived statuses have no column to live in: the CHECK constraint refuses them.
        ->and(AttendanceStatus::OffDay->isStorable())->toBeFalse()
        ->and(AttendanceStatus::Remote->isStorable())->toBeFalse();
});

it('clocks in on time as Present, inside the grace window as Present, and after it as Late', function () {
    $monday = Carbon::parse(ATTENDANCE_MONDAY);

    // Three employees rather than three clock-ins, because one employee can only clock in once
    // a day — which is itself asserted below.
    $early = officeEmployee();
    $grace = officeEmployee();
    $late = officeEmployee();

    // late_grace_minutes is 15 (SettingsSeeder). Read, never assumed: the deadline the service
    // computes is asserted against it rather than against the string "09:15".
    $deadline = attendance()->latestOnTime($early->schedule, $monday);

    expect($deadline?->format('H:i'))->toBe('09:15');

    expect(attendance()->clockIn($early, $monday->copy()->setTime(8, 58))->status)
        ->toBe(AttendanceStatus::Present)
        // Exactly on the deadline is still on time: "later than start + grace" is the rule.
        ->and(attendance()->clockIn($grace, $deadline->copy())->status)
        ->toBe(AttendanceStatus::Present)
        ->and(attendance()->clockIn($late, $deadline->copy()->addMinute())->status)
        ->toBe(AttendanceStatus::Late);
});

it('follows the late_grace_minutes setting rather than a literal', function () {
    Setting::query()->updateOrCreate(['key' => 'late_grace_minutes'], ['value' => 45]);

    $employee = officeEmployee();
    $monday = Carbon::parse(ATTENDANCE_MONDAY);

    // 09:40 is Late at the seeded 15 minutes and Present at 45. The setting moved; the rule
    // did not.
    expect(attendance()->clockIn($employee, $monday->copy()->setTime(9, 40))->status)
        ->toBe(AttendanceStatus::Present);
});

it('cannot make an employee with no start time late', function () {
    $flexible = officeEmployee(['start_time' => null]);
    $monday = Carbon::parse(ATTENDANCE_MONDAY);

    expect(attendance()->latestOnTime($flexible->schedule, $monday))->toBeNull()
        ->and(attendance()->isLate($flexible->schedule, $monday->copy()->setTime(23, 30)))->toBeFalse()
        ->and(attendance()->clockIn($flexible, $monday->copy()->setTime(14, 0))->status)
        ->toBe(AttendanceStatus::Present);
});

it('refuses a second clock-in and names the time of the first', function () {
    $employee = officeEmployee();
    $monday = Carbon::parse(ATTENDANCE_MONDAY);

    attendance()->clockIn($employee, $monday->copy()->setTime(8, 58));

    expect(fn () => attendance()->clockIn($employee, $monday->copy()->setTime(9, 30)))
        ->toThrow(AttendanceStateException::class, 'already clocked in at 08:58')
        // And no second row: one person, one day, one record.
        ->and(AttendanceRecord::where('employee_id', $employee->id)->count())->toBe(1);
});

it('refuses a clock-in on a day the schedule says is off', function () {
    $employee = officeEmployee();

    expect(fn () => attendance()->clockIn($employee, Carbon::parse(ATTENDANCE_FRIDAY)->setTime(9, 0)))
        ->toThrow(AttendanceStateException::class, 'no working hours on Friday')
        ->and(AttendanceRecord::count())->toBe(0);
});

it('clocks out, records the minutes worked, and refuses a second clock-out', function () {
    $employee = officeEmployee();
    $monday = Carbon::parse(ATTENDANCE_MONDAY);

    attendance()->clockIn($employee, $monday->copy()->setTime(9, 0));
    $record = attendance()->clockOut($employee, $monday->copy()->setTime(17, 30));

    expect($record->workedMinutes())->toBe(510)
        ->and($record->isOpen())->toBeFalse()
        // Clocking out does not rewrite the morning: Present stays Present.
        ->and($record->status)->toBe(AttendanceStatus::Present);

    expect(fn () => attendance()->clockOut($employee, $monday->copy()->setTime(18, 0)))
        ->toThrow(AttendanceStateException::class, 'already clocked out at 17:30');
});

it('refuses a clock-out from somebody who never clocked in', function () {
    $employee = officeEmployee();

    expect(fn () => attendance()->clockOut($employee, Carbon::parse(ATTENDANCE_MONDAY)->setTime(17, 0)))
        ->toThrow(AttendanceStateException::class, 'not clocked in today');
});

it('leaves a short day alone while half_day_auto is off, and marks it Half day when it is on', function () {
    $monday = Carbon::parse(ATTENDANCE_MONDAY);

    // half_day_auto is seeded FALSE, which is the agency's default: Half day is an Admin's
    // word unless somebody opts in.
    $withSetting = officeEmployee(['working_hours_per_day' => '8.00']);
    attendance()->clockIn($withSetting, $monday->copy()->setTime(9, 0));

    expect(attendance()->clockOut($withSetting, $monday->copy()->setTime(12, 0))->status)
        ->toBe(AttendanceStatus::Present);

    Setting::query()->updateOrCreate(['key' => 'half_day_auto'], ['value' => true]);
    app()->forgetInstance(AttendanceService::class);
    app()->forgetScopedInstances();

    $auto = officeEmployee(['working_hours_per_day' => '8.00']);
    attendance()->clockIn($auto, $monday->copy()->setTime(9, 0));

    // Three hours against an eight-hour day: under half.
    expect(attendance()->clockOut($auto, $monday->copy()->setTime(12, 0))->status)
        ->toBe(AttendanceStatus::HalfDay);

    // And a full day is still a full day with the setting on.
    $full = officeEmployee(['working_hours_per_day' => '8.00']);
    attendance()->clockIn($full, $monday->copy()->setTime(9, 0));

    expect(attendance()->clockOut($full, $monday->copy()->setTime(17, 0))->status)
        ->toBe(AttendanceStatus::Present);
});

it('measures half a day against the employee\'s own hours, not a constant', function () {
    Setting::query()->updateOrCreate(['key' => 'half_day_auto'], ['value' => true]);
    app()->forgetScopedInstances();

    $monday = Carbon::parse(ATTENDANCE_MONDAY);
    // A five-hour day: two and a half hours is the line, so three hours is a full day here
    // and was a half day for the eight-hour employee above.
    $shortContract = officeEmployee(['working_hours_per_day' => '5.00']);

    attendance()->clockIn($shortContract, $monday->copy()->setTime(9, 0));

    expect(attendance()->clockOut($shortContract, $monday->copy()->setTime(12, 0))->status)
        ->toBe(AttendanceStatus::Present);
});

it('derives Remote for a remote-timer employee and never asks them to clock in', function () {
    $tapu = Employee::factory()->forRole(RoleName::REMOTE_EMPLOYEE)->create();
    Schedule::create([
        'employee_id' => $tapu->id,
        'working_days' => ['sun', 'mon', 'tue', 'wed', 'thu'],
        'working_hours_per_day' => '5.00',
        'start_time' => null,
        'office_or_remote' => 'remote',
    ]);
    $tapu = $tapu->fresh(['schedule']);

    $working = attendance()->dayFor($tapu, Carbon::parse(ATTENDANCE_MONDAY));
    $off = attendance()->dayFor($tapu, Carbon::parse(ATTENDANCE_FRIDAY));

    expect($tapu->tracking_mode)->toBe(TrackingMode::RemoteTimer)
        ->and($working->status)->toBe(AttendanceStatus::Remote)
        // The day off is the day off, remote or not: the schedule is everybody's calendar.
        ->and($off->status)->toBe(AttendanceStatus::OffDay)
        // The seam, now wired: 0 is a MEASUREMENT of a day with no timer entries on it, where
        // null used to mean "this half of the phase has not looked". Null is still what a
        // non-timer employee's day carries, which `dayFor()` decides and not this method.
        ->and($working->trackedMinutes)->toBe(0)
        ->and($working->toArray()['tracked_minutes'])->toBe(0);

    expect(attendance()->clocks($tapu))->toBeFalse()
        ->and(fn () => attendance()->clockIn($tapu, Carbon::parse(ATTENDANCE_MONDAY)->setTime(9, 0)))
        ->toThrow(AttendanceStateException::class, 'not tracked by clocking in');
});

it('says a scheduled day with no record yet has no status, rather than guessing Absent', function () {
    $employee = officeEmployee();

    $day = attendance()->dayFor($employee, Carbon::parse(ATTENDANCE_MONDAY));

    expect($day->status)->toBeNull()
        ->and($day->scheduled)->toBeTrue()
        ->and($day->toArray()['status_label'])->toBeNull();
});

it('lets a record outrank every derivation', function () {
    $employee = officeEmployee();
    $friday = Carbon::parse(ATTENDANCE_FRIDAY);

    // A day the schedule calls off, with a row on it — somebody came in on a Friday and an
    // Admin recorded it. The row wins; Off Day does not overwrite what happened.
    $record = AttendanceRecord::factory()->forEmployee($employee)->on($friday)->create();

    $day = attendance()->dayFor($employee, $friday, $record);

    expect($day->status)->toBe(AttendanceStatus::Present)
        ->and($day->scheduled)->toBeFalse();
});

it('builds a whole month in order, with one row per day and no query per cell', function () {
    $employee = officeEmployee();
    $september = Carbon::parse('2026-09-15');

    AttendanceRecord::factory()->forEmployee($employee)->on(Carbon::parse(ATTENDANCE_MONDAY))->create();

    $days = attendance()->month($employee, $september);

    expect($days)->toHaveCount(30)
        ->and($days->first()->date->toDateString())->toBe('2026-09-01')
        ->and($days->last()->date->toDateString())->toBe('2026-09-30')
        ->and($days->firstWhere(fn ($day) => $day->date->toDateString() === ATTENDANCE_MONDAY)->status)
        ->toBe(AttendanceStatus::Present)
        ->and($days->firstWhere(fn ($day) => $day->date->toDateString() === ATTENDANCE_FRIDAY)->status)
        ->toBe(AttendanceStatus::OffDay);
});

it('carries the whole clock-in moment, so a screen can count up from it', function () {
    // `worked_minutes` is null until a clock-out — it is a difference and one end has not
    // happened yet. Without the full timestamp the widget could say when somebody arrived and
    // not how long they have been here, which is the thing they opened it to find out.
    $employee = officeEmployee();

    $this->travelTo(Carbon::parse(ATTENDANCE_MONDAY.' 09:04:00'));
    $record = attendance()->clockIn($employee);

    // `dayFor()` takes the record rather than looking one up — its callers batch-load a month
    // or a roster in one query, and a lookup per day would be a query per cell.
    $open = attendance()->dayFor($employee, Carbon::parse(ATTENDANCE_MONDAY), $record)->toArray();

    expect($open['clock_in'])->toBe('09:04')
        ->and($open['clock_in_at'])->not->toBeNull()
        ->and(Carbon::parse($open['clock_in_at'])->format('H:i'))->toBe('09:04')
        // Still open, so the server has no figure and the browser counts.
        ->and($open['worked_minutes'])->toBeNull();

    $this->travelTo(Carbon::parse(ATTENDANCE_MONDAY.' 17:34:00'));
    $closed = attendance()->clockOut($employee->fresh());

    $closed = attendance()->dayFor($employee, Carbon::parse(ATTENDANCE_MONDAY), $closed)->toArray();

    // Closed: the server's number takes over and the browser stops guessing.
    expect($closed['worked_minutes'])->toBe(8 * 60 + 30)
        ->and($closed['clock_in_at'])->not->toBeNull();

    $this->travelBack();
})->group('phase4');

it('leaves clock_in_at null on a day nobody clocked', function () {
    $day = attendance()
        ->dayFor(officeEmployee(), Carbon::parse(ATTENDANCE_MONDAY))
        ->toArray();

    expect($day['clock_in'])->toBeNull()
        ->and($day['clock_in_at'])->toBeNull()
        ->and($day['worked_minutes'])->toBeNull();
})->group('phase4');
