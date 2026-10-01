<?php

use App\Models\AttendanceRecord;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\TimerService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/*
|--------------------------------------------------------------------------
| Closing the last goodERP tab stops a running timer at that moment
|--------------------------------------------------------------------------
|
| The `pagehide` beacon (`/task-timer/leaving`, `/employee/time/leaving`)
| only marks the moment; the sweep stops the open entry AT it once the mark
| is 30 s old with no heartbeat since. A reload (or another tab) beats and
| cancels the mark. Helpers are global in Pest, so everything is TABCLOSE_.
|
*/

/** A Monday, a working day on every seeded schedule. */
const TABCLOSE_DAY = '2026-09-14';

function TABCLOSE_task(User $user): Task
{
    $task = Task::factory()->create();
    $task->assignees()->attach($user->employee->id, ['is_primary' => true]);

    return $task;
}

function TABCLOSE_at(string $time): void
{
    Carbon::setTestNow(TABCLOSE_DAY.' '.$time);
}

beforeEach(function () {
    TABCLOSE_at('09:00:00');

    $this->seed();

    TimeEntry::query()->delete();
    AttendanceRecord::query()->delete();
    Cache::flush();

    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
});

afterEach(function () {
    Carbon::setTestNow();
});

/** An office task timer running since 09:00 with a heartbeat at 09:04, and the tab closed at 09:05. */
function TABCLOSE_officeTimerLeftAt0905(User $yaseen): TimeEntry
{
    $task = TABCLOSE_task($yaseen);

    test()->actingAs($yaseen)->post("/tasks/{$task->id}/timer", ['clock_in' => true])->assertRedirect();

    TABCLOSE_at('09:04:00');
    test()->actingAs($yaseen)->postJson('/task-timer/heartbeat')->assertOk();

    TABCLOSE_at('09:05:00');
    test()->actingAs($yaseen)->post('/task-timer/leaving', ['_token' => 'beacon'])->assertNoContent();

    return TimeEntry::query()->sole();
}

it('marks the moment and stops an office task timer AT it once the 30-second grace has passed', function () {
    $entry = TABCLOSE_officeTimerLeftAt0905($this->yaseen);

    expect(Cache::get(TimerService::leavingKey($this->yaseen->employee->id)))
        ->toBe(Carbon::parse(TABCLOSE_DAY.' 09:05:00')->toIso8601String());

    TABCLOSE_at('09:05:31');
    $result = app(TimerService::class)->sweep();

    $entry->refresh();

    expect($result['stopped'])->toBe([$entry->id])
        ->and($entry->isStopped())->toBeTrue()
        ->and($entry->ended_at->equalTo(Carbon::parse(TABCLOSE_DAY.' 09:05:00')))->toBeTrue()
        ->and($entry->duration_seconds)->toBe(300)
        ->and($entry->flag_reason)->toBe('Stopped: the last goodERP tab was closed at 09:05.')
        ->and(Cache::has(TimerService::leavingKey($this->yaseen->employee->id)))->toBeFalse();
});

it('does not stop the timer when a heartbeat arrives after the mark (a reload or another tab)', function () {
    $entry = TABCLOSE_officeTimerLeftAt0905($this->yaseen);

    TABCLOSE_at('09:05:10');
    $this->actingAs($this->yaseen)->postJson('/task-timer/heartbeat')->assertOk();

    expect(Cache::has(TimerService::leavingKey($this->yaseen->employee->id)))->toBeFalse();

    TABCLOSE_at('09:06:00');
    app(TimerService::class)->sweep();

    expect($entry->fresh()->isOpen())->toBeTrue();
});

it('does not stop the timer while the mark is younger than 30 seconds', function () {
    $entry = TABCLOSE_officeTimerLeftAt0905($this->yaseen);

    TABCLOSE_at('09:05:10');
    app(TimerService::class)->sweep();

    expect($entry->fresh()->isOpen())->toBeTrue()
        ->and(Cache::has(TimerService::leavingKey($this->yaseen->employee->id)))->toBeTrue();
});

it('sends a guest to the login page', function () {
    $this->post('/task-timer/leaving')->assertRedirect('/login');
    $this->post('/employee/time/leaving')->assertRedirect('/login');
});

it('stops a remote timer at the closing moment the same way', function () {
    $entry = TimeEntry::factory()->forEmployee($this->tapu->employee)->onTask(TABCLOSE_task($this->tapu))
        ->running(Carbon::parse(TABCLOSE_DAY.' 08:50:00'))->heartbeatAt(Carbon::parse(TABCLOSE_DAY.' 09:04:00'))
        ->create();

    TABCLOSE_at('09:05:00');
    $this->actingAs($this->tapu)->post('/employee/time/leaving', ['_token' => 'beacon'])->assertNoContent();

    expect(Cache::has(TimerService::leavingKey($this->tapu->employee->id)))->toBeTrue();

    TABCLOSE_at('09:05:31');
    app(TimerService::class)->sweep();

    $entry->refresh();

    expect($entry->isStopped())->toBeTrue()
        ->and($entry->ended_at->equalTo(Carbon::parse(TABCLOSE_DAY.' 09:05:00')))->toBeTrue()
        ->and($entry->flag_reason)->toBe('Stopped: the last goodERP tab was closed at 09:05.');
});

it('keeps a remote timer whose heartbeat came after the mark', function () {
    $entry = TimeEntry::factory()->forEmployee($this->tapu->employee)->onTask(TABCLOSE_task($this->tapu))
        ->running(Carbon::parse(TABCLOSE_DAY.' 08:50:00'))->heartbeatAt(Carbon::parse(TABCLOSE_DAY.' 09:04:00'))
        ->create();

    TABCLOSE_at('09:05:00');
    $this->actingAs($this->tapu)->post('/employee/time/leaving')->assertNoContent();

    TABCLOSE_at('09:05:10');
    $this->actingAs($this->tapu)->postJson('/employee/time/heartbeat')->assertOk();

    TABCLOSE_at('09:06:00');
    app(TimerService::class)->sweep();

    expect($entry->fresh()->isOpen())->toBeTrue();
});
