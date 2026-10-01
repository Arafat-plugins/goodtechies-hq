<?php

use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AttendanceService;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Friday and Saturday off; clock-in and clock-out in the Audit Log
|--------------------------------------------------------------------------
*/

/** A Monday, a working day on every seeded schedule. */
const WEEKEND_DAY = '2026-09-14';

function WEEKEND_migration(): object
{
    return require base_path('database/migrations/2026_10_31_0002_friday_saturday_are_weekly_off.php');
}

beforeEach(function () {
    Carbon::setTestNow(WEEKEND_DAY.' 09:00:00');

    $this->seed();

    AttendanceRecord::query()->delete();

    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
});

afterEach(function () {
    Carbon::setTestNow();
});

it('drops Friday and Saturday from a seven-day schedule, in week order', function () {
    $schedule = $this->yaseen->employee->schedule;
    $schedule->working_days = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];
    $schedule->save();

    $schedule->working_days = WEEKEND_migration()->withoutWeekend($schedule->fresh()->working_days);
    $schedule->save();

    expect($schedule->fresh()->working_days)->toBe(['sun', 'mon', 'tue', 'wed', 'thu']);
});

it('gives a schedule left with no day the Sunday-to-Thursday week', function () {
    expect(WEEKEND_migration()->withoutWeekend(['fri', 'sat']))->toBe(['sun', 'mon', 'tue', 'wed', 'thu'])
        ->and(WEEKEND_migration()->withoutWeekend(['thu', 'mon', 'sat']))->toBe(['mon', 'thu']);
});

it('writes one audit row per clock-in and clock-out, with the session and the worked minutes', function () {
    $attendance = app(AttendanceService::class);
    $employee = $this->yaseen->employee;

    $record = $attendance->clockIn($employee);

    $in = AuditLog::query()->where('event', 'attendance.clocked_in')->sole();

    expect($in->new_value)->toEqual(['date' => WEEKEND_DAY, 'at' => '09:00', 'session' => 1, 'worked_minutes' => null])
        ->and($in->old_value)->toBeNull()
        ->and((int) $in->actor_id)->toBe($this->yaseen->id)
        ->and((int) $in->target_id)->toBe($record->id);

    Carbon::setTestNow(WEEKEND_DAY.' 12:00:00');
    $attendance->clockOut($employee->fresh());

    $out = AuditLog::query()->where('event', 'attendance.clocked_out')->sole();

    expect($out->new_value)->toEqual(['date' => WEEKEND_DAY, 'at' => '12:00', 'session' => 1, 'worked_minutes' => 180]);

    // Re-opening the day is the day's second session.
    Carbon::setTestNow(WEEKEND_DAY.' 13:00:00');
    $attendance->clockIn($employee->fresh());

    $reopened = AuditLog::query()->where('event', 'attendance.clocked_in')->orderByDesc('id')->first();

    expect(AuditLog::query()->where('event', 'attendance.clocked_in')->count())->toBe(2)
        ->and($reopened->new_value)->toEqual(['date' => WEEKEND_DAY, 'at' => '13:00', 'session' => 2, 'worked_minutes' => null]);
});
