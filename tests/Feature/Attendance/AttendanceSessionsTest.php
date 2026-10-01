<?php

use App\Exceptions\AttendanceStateException;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Employee;
use App\Models\Schedule;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\AttendanceService;
use App\Support\AttendanceStatus;
use App\Support\RoleName;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Attendance sessions — an office day is a list of in -> out stretches
|--------------------------------------------------------------------------
|
| Decision 12-76: somebody may clock in and out several times a day. Each
| in -> out is an `attendance_sessions` row, worked time is the sum of the
| closed ones, and the gaps between them are breaks, not worked time.
|
| 2026-09-21 is a Monday.
|
*/

const SESSIONS_MONDAY = '2026-09-21';

function sessionsEmployee(): Employee
{
    $employee = Employee::factory()->forRole(RoleName::EMPLOYEE)->create();

    Schedule::create([
        'employee_id' => $employee->id,
        'working_days' => ['sun', 'mon', 'tue', 'wed', 'thu'],
        'working_hours_per_day' => '8.00',
        'start_time' => '09:00',
        'office_or_remote' => 'office',
    ]);

    return $employee->fresh(['schedule']);
}

function sessionsService(): AttendanceService
{
    return app(AttendanceService::class);
}

function sessionsAt(int $hour, int $minute = 0): Carbon
{
    return Carbon::parse(SESSIONS_MONDAY)->setTime($hour, $minute);
}

/**
 * 09:00 in, 12:00 out, 13:00 in, 17:00 out.
 */
function sessionsTwoSessionDay(Employee $employee): AttendanceRecord
{
    sessionsService()->clockIn($employee, sessionsAt(9));
    sessionsService()->clockOut($employee, sessionsAt(12));
    sessionsService()->clockIn($employee, sessionsAt(13));

    return sessionsService()->clockOut($employee, sessionsAt(17));
}

it('sums two sessions and leaves the lunch break out of the worked time', function () {
    $employee = sessionsEmployee();

    $record = sessionsTwoSessionDay($employee)->fresh();

    expect($record->sessions()->count())->toBe(2)
        ->and($record->clock_in->format('H:i'))->toBe('09:00')
        ->and($record->clock_out->format('H:i'))->toBe('17:00')
        ->and($record->workedMinutes())->toBe(420)
        ->and($record->status)->toBe(AttendanceStatus::Present);
});

it('gives daily_work_summary the same 420 minutes', function () {
    $employee = sessionsEmployee();

    sessionsTwoSessionDay($employee);

    $worked = DB::table('daily_work_summary')
        ->where('employee_id', $employee->id)
        ->where('work_date', SESSIONS_MONDAY)
        ->value('worked_minutes');

    expect((int) $worked)->toBe(420);
});

it('refuses a clock-in while a session is open and keeps exactly one open session', function () {
    $employee = sessionsEmployee();

    sessionsService()->clockIn($employee, sessionsAt(9));

    expect(fn () => sessionsService()->clockIn($employee, sessionsAt(10)))
        ->toThrow(AttendanceStateException::class);

    expect(AttendanceSession::query()->whereNull('clock_out')->count())->toBe(1);
});

it('reports the closed minutes, the open session and its start while the second session runs', function () {
    $employee = sessionsEmployee();

    sessionsService()->clockIn($employee, sessionsAt(9));
    sessionsService()->clockOut($employee, sessionsAt(12));
    $record = sessionsService()->clockIn($employee, sessionsAt(13))->fresh();

    expect($record->workedMinutes())->toBe(180);

    $day = sessionsService()->dayFor($employee, sessionsAt(0), $record)->toArray();

    expect($day['open_since'])->toBe(sessionsAt(13)->toIso8601String())
        ->and($day['sessions'])->toHaveCount(2)
        ->and($day['sessions'][0])->toBe(['clock_in' => '09:00', 'clock_out' => '12:00', 'minutes' => 180])
        ->and($day['sessions'][1]['clock_in'])->toBe('13:00')
        ->and($day['sessions'][1]['clock_out'])->toBeNull()
        ->and($day['sessions'][1]['minutes'])->toBeNull();
});

it('turns a legacy record with no session rows into its first session when the day is re-opened', function () {
    $employee = sessionsEmployee();

    AttendanceRecord::create([
        'employee_id' => $employee->id,
        'date' => SESSIONS_MONDAY,
        'clock_in' => sessionsAt(9),
        'clock_out' => sessionsAt(12),
        'status' => AttendanceStatus::Present,
    ]);

    sessionsService()->clockIn($employee, sessionsAt(13));
    $record = sessionsService()->clockOut($employee, sessionsAt(14))->fresh();

    expect($record->sessions()->count())->toBe(2)
        ->and($record->workedMinutes())->toBe(240);
});

it('collapses the day to one session when an Admin edits the times, and not when only the status or note changes', function () {
    $admin = Employee::factory()->forRole(RoleName::ADMIN)->create()->user;
    $monday = Carbon::parse(SESSIONS_MONDAY);

    $edited = sessionsEmployee();
    sessionsTwoSessionDay($edited);

    $record = sessionsService()->edit($edited, $monday, [
        'status' => AttendanceStatus::Present,
        'clock_in' => sessionsAt(10),
        'clock_out' => sessionsAt(16),
        'note' => 'Forgot to clock in on time.',
    ], $admin)->fresh();

    $sessions = $record->sessions()->get();

    expect($sessions)->toHaveCount(1)
        ->and($sessions[0]->clock_in->format('H:i'))->toBe('10:00')
        ->and($sessions[0]->clock_out->format('H:i'))->toBe('16:00')
        ->and($record->workedMinutes())->toBe(360);

    $kept = sessionsEmployee();
    sessionsTwoSessionDay($kept);

    $record = sessionsService()->edit($kept, $monday, [
        'status' => AttendanceStatus::Late,
        'clock_in' => sessionsAt(9),
        'clock_out' => sessionsAt(17),
        'note' => 'Arrived late, agreed with the manager.',
    ], $admin)->fresh();

    expect($record->sessions()->count())->toBe(2)
        ->and($record->status)->toBe(AttendanceStatus::Late)
        ->and($record->workedMinutes())->toBe(420);
});

it('keeps the sessions when the Admin dialog sends back the same HH:mm of a clock-in that had seconds', function () {
    $admin = Employee::factory()->forRole(RoleName::ADMIN)->create()->user;
    $employee = sessionsEmployee();

    sessionsService()->clockIn($employee, sessionsAt(9)->setSecond(27));
    sessionsService()->clockOut($employee, sessionsAt(12)->setSecond(5));
    sessionsService()->clockIn($employee, sessionsAt(13));
    sessionsService()->clockOut($employee, sessionsAt(17)->setSecond(41));

    // What EditDayDialog posts: the HH:mm it showed, with only the status changed.
    $record = sessionsService()->edit($employee, Carbon::parse(SESSIONS_MONDAY), [
        'status' => AttendanceStatus::Late,
        'clock_in' => '09:00',
        'clock_out' => '17:00',
        'note' => 'Status only.',
    ], $admin)->fresh();

    expect($record->sessions()->count())->toBe(2)
        ->and($record->status)->toBe(AttendanceStatus::Late);
});

it('says "Clocked in again at" when the employee clocks in on a closed day', function () {
    $this->seed();
    TimeEntry::query()->delete();
    AttendanceRecord::query()->delete();

    $yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();

    $this->travelTo(sessionsAt(9));
    $this->actingAs($yaseen)->post('/attendance/clock-in')->assertRedirect();

    $this->travelTo(sessionsAt(12));
    $this->actingAs($yaseen)->post('/attendance/clock-out')->assertRedirect();

    $this->travelTo(sessionsAt(13));
    $this->actingAs($yaseen)
        ->post('/attendance/clock-in')
        ->assertRedirect()
        ->assertSessionHas('success', fn (string $message): bool => str_starts_with($message, 'Clocked in again at 13:00'));
});

it('refuses a second open session for the same day at the database', function () {
    $employee = sessionsEmployee();

    $record = sessionsService()->clockIn($employee, sessionsAt(9));

    expect(fn () => DB::table('attendance_sessions')->insert([
        'attendance_record_id' => $record->id,
        'clock_in' => sessionsAt(10),
        'clock_out' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});
