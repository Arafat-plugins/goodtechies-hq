<?php

use App\Models\Employee;
use App\Models\Setting;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Services\TimerService;
use App\Support\RoleName;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| hq:timer-watchdog
|--------------------------------------------------------------------------
|
| The command itself holds no rules — both sweeps are TimerService's, and
| both thresholds are read from `settings` inside them. So this is about the
| command: that it runs the two rules, in the right order, and says what it
| did. The arithmetic is tested next door in TimerServiceTest.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-24 09:00:00');

    $this->seed(RolePermissionSeeder::class);
    $this->seed(SettingsSeeder::class);

    $this->tapu = Employee::factory()->forRole(RoleName::REMOTE_EMPLOYEE)->create();
    $this->task = Task::factory()->create();
    $this->timer = app(TimerService::class);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('says so when there is nothing to do', function (): void {
    $this->artisan('hq:timer-watchdog')
        ->expectsOutputToContain('No timers needed attention.')
        ->assertSuccessful();
});

it('stops a silent timer at its last heartbeat and reports it', function (): void {
    $entry = $this->timer->start($this->tapu, $this->task, (string) Str::uuid(), Carbon::parse('2026-09-24 09:00:00'));

    Carbon::setTestNow('2026-09-24 13:04:00');
    $this->timer->heartbeat($entry, Carbon::now());

    Carbon::setTestNow('2026-09-24 18:00:00');

    $this->artisan('hq:timer-watchdog')
        ->expectsOutputToContain('1 timer stopped at the last heartbeat')
        ->assertSuccessful();

    $entry->refresh();

    expect($entry->ended_at->toIso8601String())->toBe(Carbon::parse('2026-09-24 13:04:00')->toIso8601String())
        ->and($entry->isFlagged())->toBeTrue();
});

it('pauses an overlong session that is still pinging', function (): void {
    $entry = $this->timer->start($this->tapu, $this->task, (string) Str::uuid(), Carbon::parse('2026-09-24 00:00:00'));

    Carbon::setTestNow('2026-09-24 10:30:00');
    $this->timer->heartbeat($entry, Carbon::now());

    $this->artisan('hq:timer-watchdog')->assertSuccessful();

    expect($entry->fresh()->isPaused())->toBeTrue()
        ->and($entry->fresh()->flag_reason)->toContain('10 hours');
});

it('treats a session that is both silent and overlong as dead, not as overlong', function (): void {
    // Rule 1 runs first on purpose. An entry that has been quiet for hours AND running for
    // eleven is a closed laptop, and the true account of the day ends it at its last heartbeat
    // rather than pausing it at now.
    $entry = $this->timer->start($this->tapu, $this->task, (string) Str::uuid(), Carbon::parse('2026-09-24 00:00:00'));

    Carbon::setTestNow('2026-09-24 02:00:00');
    $this->timer->heartbeat($entry, Carbon::now());

    Carbon::setTestNow('2026-09-24 11:00:00');

    $this->artisan('hq:timer-watchdog')->assertSuccessful();

    $entry->refresh();

    expect($entry->isStopped())->toBeTrue()
        ->and($entry->duration_seconds)->toBe(2 * 3600)
        ->and($entry->flag_reason)->toContain('last check-in');
});

it('takes both thresholds from settings and not from the command', function (): void {
    $entry = $this->timer->start($this->tapu, $this->task, (string) Str::uuid(), Carbon::parse('2026-09-24 09:00:00'));

    Carbon::setTestNow('2026-09-24 10:00:00');
    $this->timer->heartbeat($entry, Carbon::now());

    Carbon::setTestNow('2026-09-24 10:20:00');

    // An hour of grace and a two-hour maximum: nothing to do at 10:20.
    Setting::query()->updateOrCreate(['key' => 'heartbeat_timeout_minutes'], ['value' => 60]);
    Setting::query()->updateOrCreate(['key' => 'timer_max_session_hours'], ['value' => 2]);

    // `SettingsService` is bound scoped, so it caches for one request or job. A scheduled run
    // is a fresh process and reads the settings as they are; inside one test the cache has to
    // be dropped by hand, or the second half of this would be measuring the first half's rule.
    app()->forgetScopedInstances();

    $this->artisan('hq:timer-watchdog')
        ->expectsOutputToContain('No timers needed attention.')
        ->assertSuccessful();

    expect($entry->fresh()->isRunning())->toBeTrue();

    // Move the maximum under the session's length and the same command pauses it.
    Setting::query()->updateOrCreate(['key' => 'timer_max_session_hours'], ['value' => 1]);

    app()->forgetScopedInstances();

    $this->artisan('hq:timer-watchdog')->assertSuccessful();

    expect($entry->fresh()->isPaused())->toBeTrue();
});

it('leaves everybody else\'s day alone', function (): void {
    $other = Employee::factory()->forRole(RoleName::REMOTE_EMPLOYEE)->create();

    $live = $this->timer->start($other, $this->task, (string) Str::uuid(), Carbon::parse('2026-09-24 08:55:00'));

    $abandoned = $this->timer->start($this->tapu, $this->task, (string) Str::uuid(), Carbon::parse('2026-09-24 08:00:00'));

    Carbon::setTestNow('2026-09-24 09:10:00');
    $this->timer->heartbeat($live, Carbon::now());

    $this->artisan('hq:timer-watchdog')->assertSuccessful();

    expect($live->fresh()->isRunning())->toBeTrue()
        ->and($abandoned->fresh()->isStopped())->toBeTrue()
        ->and(TimeEntry::query()->open()->count())->toBe(1);
});
