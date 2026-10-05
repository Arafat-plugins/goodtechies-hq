<?php

use App\Models\AttendanceRecord;
use App\Models\User;
use App\Services\AttendanceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/*
|--------------------------------------------------------------------------
| Closing the last tab clocks out (client doc 2026-10-05, item 1)
|--------------------------------------------------------------------------
|
| The pagehide beacon marks the moment; the sweep clocks out AT it once a minute has passed
| with no page of the person's alive. A reload (presence ping, full page load) cancels it.
|
*/

beforeEach(function () {
    Carbon::setTestNow('2026-09-24 10:00:00');

    $this->seed();
    Cache::flush();

    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();

    AttendanceRecord::query()
        ->where('employee_id', $this->yaseen->employee->id)
        ->whereDate('date', '2026-09-24')
        ->delete();

    app(AttendanceService::class)->clockIn($this->yaseen->employee, Carbon::parse('2026-09-24 09:00:00'));
});

function TABCLOSE_record(object $test): AttendanceRecord
{
    return AttendanceRecord::query()
        ->where('employee_id', $test->yaseen->employee->id)
        ->whereDate('date', '2026-09-24')
        ->firstOrFail();
}

it('clocks out at the moment the last tab closed, once the grace has passed', function () {
    $this->actingAs($this->yaseen)->post('/attendance/leaving')->assertNoContent();

    // Inside the grace: still clocked in.
    Carbon::setTestNow('2026-09-24 10:00:30');
    expect(app(AttendanceService::class)->clockOutLeft())->toBe([])
        ->and(TABCLOSE_record($this)->clock_out)->toBeNull();

    Carbon::setTestNow('2026-09-24 10:01:05');
    expect(app(AttendanceService::class)->clockOutLeft())->toBe([$this->yaseen->employee->id]);

    expect(TABCLOSE_record($this)->clock_out->format('H:i:s'))->toBe('10:00:00');
});

it('does not clock out when a page comes back (a reload pings presence at once)', function () {
    $this->actingAs($this->yaseen)->post('/attendance/leaving')->assertNoContent();

    Carbon::setTestNow('2026-09-24 10:00:02');
    $this->actingAs($this->yaseen)->post('/presence/heartbeat')->assertNoContent();

    Carbon::setTestNow('2026-09-24 10:05:00');
    expect(app(AttendanceService::class)->clockOutLeft())->toBe([])
        ->and(TABCLOSE_record($this)->clock_out)->toBeNull();
});

it('marks nothing for somebody who is not clocked in, and refuses the timer-tracked', function () {
    app(AttendanceService::class)->clockOut($this->yaseen->employee, Carbon::parse('2026-09-24 09:30:00'));

    $this->actingAs($this->yaseen)->post('/attendance/leaving')->assertNoContent();

    Carbon::setTestNow('2026-09-24 10:05:00');
    expect(app(AttendanceService::class)->clockOutLeft())->toBe([]);

    // Tapu is on the remote timer, not the office clock.
    $this->actingAs($this->tapu)->post('/attendance/leaving')->assertForbidden();
});
