<?php

use App\Models\AttendanceRecord;
use App\Models\User;
use App\Services\AttendanceService;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| The shared `clock` prop — what the tab-close guard reads (decision 12-84)
|--------------------------------------------------------------------------
|
| `['clocked_in' => bool]` for an employee on the office clock; `null` for
| everyone else. 2026-09-24 is a Thursday, a seeded working day.
|
*/

beforeEach(function () {
    Carbon::setTestNow('2026-09-24 10:00:00');

    $this->seed();

    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();

    // The demo seed may already have clocked Yaseen in today; start from a day with no record.
    AttendanceRecord::query()
        ->where('employee_id', $this->yaseen->employee->id)
        ->whereDate('date', '2026-09-24')
        ->delete();
});

afterEach(function () {
    Carbon::setTestNow();
});

function clockProp(User $user): mixed
{
    $props = test()->actingAs($user)->get('/profile')->assertOk()->viewData('page')['props'];

    expect($props)->toHaveKey('clock');

    return $props['clock'];
}

it('follows an office employee clocking in and out', function () {
    $attendance = app(AttendanceService::class);

    expect(clockProp($this->yaseen))->toBe(['clocked_in' => false]);

    $attendance->clockIn($this->yaseen->employee);
    expect(clockProp($this->yaseen->fresh()))->toBe(['clocked_in' => true]);

    Carbon::setTestNow('2026-09-24 12:00:00');
    $attendance->clockOut($this->yaseen->employee);
    expect(clockProp($this->yaseen->fresh()))->toBe(['clocked_in' => false]);
});

it('is null for a remote employee', function () {
    expect(clockProp($this->tapu))->toBeNull();
});
