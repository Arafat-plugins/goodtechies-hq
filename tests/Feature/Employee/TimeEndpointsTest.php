<?php

use App\Models\Employee;
use App\Models\Setting;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\TimerService;
use App\Support\RoleName;
use App\Support\TrackingMode;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| The timer endpoints
|--------------------------------------------------------------------------
|
| The privacy rules of this slice, stated as requests:
|
|   - only a REMOTE_EMPLOYEE may time, and an office role gets 403 from
|     every endpoint — a fact about the requester, not about a record;
|   - another employee's entry is 404, never 403, because a 403 would
|     confirm it exists (Part C §1);
|   - the server wins: the browser finds out through the reply to the ping
|     it was making anyway.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-24 09:00:00');

    $this->seed(RolePermissionSeeder::class);
    $this->seed(SettingsSeeder::class);

    $this->tapu = Employee::factory()->forRole(RoleName::REMOTE_EMPLOYEE)->create();
    $this->task = Task::factory()->create();
    $this->task->assignees()->attach($this->tapu->id, ['is_primary' => true]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** Every timer endpoint, as method + path, so a refusal test covers all of them and not three. */
function timerEndpoints(): array
{
    return [
        ['get', '/employee/time'],
        ['get', '/employee/time/current'],
        ['post', '/employee/time/start'],
        ['post', '/employee/time/pause'],
        ['post', '/employee/time/resume'],
        ['post', '/employee/time/stop'],
        ['post', '/employee/time/heartbeat'],
        ['post', '/employee/time/replay'],
        ['post', '/employee/time/entries'],
    ];
}

/* ------------------------------------------------------- who may time at all */

it('refuses every timer endpoint to an office employee, with a 403 and not a 404', function (): void {
    $yaseen = Employee::factory()->forRole(RoleName::EMPLOYEE)->create();

    foreach (timerEndpoints() as [$method, $path]) {
        $this->actingAs($yaseen->user)
            ->{$method}($path)
            ->assertForbidden();
    }
});

it('refuses every timer endpoint to a Manager, who shares the surface but has no timer', function (): void {
    $manager = Employee::factory()->forRole(RoleName::MANAGER)->create();

    foreach (timerEndpoints() as [$method, $path]) {
        $this->actingAs($manager->user)
            ->{$method}($path)
            ->assertForbidden();
    }
});

it('refuses the timer to a remote employee whose tracking mode has been switched off', function (): void {
    // The role still holds `timer.use`. The per-employee fact is what changed, and it is the
    // fact that decides — see TimeEntryPolicy.
    $this->tapu->update(['tracking_mode' => TrackingMode::OfficeAttendance]);

    $this->actingAs($this->tapu->user->fresh())
        ->get('/employee/time')
        ->assertForbidden();
});

it('tells the screens who may time, as a server-resolved fact rather than a role', function (): void {
    $this->actingAs($this->tapu->user)
        ->get('/employee/dashboard')
        ->assertInertia(fn ($page) => $page->where('auth.user.canTrackTime', true));

    $yaseen = Employee::factory()->forRole(RoleName::EMPLOYEE)->create();

    $this->actingAs($yaseen->user)
        ->get('/employee/dashboard')
        ->assertInertia(fn ($page) => $page->where('auth.user.canTrackTime', false));
});

/* -------------------------------------------------------- one person's record */

it('answers 404 — never 403 — for another employee\'s entry', function (): void {
    $other = Employee::factory()->forRole(RoleName::REMOTE_EMPLOYEE)->create();

    $theirs = TimeEntry::factory()->forEmployee($other)->onTask($this->task)->create();

    $this->actingAs($this->tapu->user)
        ->put("/employee/time/entries/{$theirs->id}", [
            'started_at' => '2026-09-24 09:00:00',
            'ended_at' => '2026-09-24 10:00:00',
            'reason' => 'Not mine to touch.',
        ])
        ->assertNotFound();

    expect($theirs->fresh()->duration_seconds)->toBe(3600);
});

it('will not start a timer on a task the employee is not assigned to', function (): void {
    $somebodyElsesTask = Task::factory()->create();

    $this->actingAs($this->tapu->user)
        ->post('/employee/time/start', [
            'task_id' => $somebodyElsesTask->id,
            'client_uuid' => (string) Str::uuid(),
        ])
        ->assertNotFound();

    expect(TimeEntry::count())->toBe(0);
});

/* ----------------------------------------------------- the round trip, for real */

it('runs a session end to end: start, pause, resume, stop', function (): void {
    $uuid = (string) Str::uuid();

    $this->actingAs($this->tapu->user)
        ->post('/employee/time/start', ['task_id' => $this->task->id, 'client_uuid' => $uuid])
        ->assertRedirect();

    Carbon::setTestNow('2026-09-24 11:30:00');
    $this->actingAs($this->tapu->user)->post('/employee/time/pause')->assertRedirect();

    Carbon::setTestNow('2026-09-24 12:17:00');
    $this->actingAs($this->tapu->user)->post('/employee/time/resume')->assertRedirect();

    Carbon::setTestNow('2026-09-24 14:00:00');
    $this->actingAs($this->tapu->user)->post('/employee/time/stop')->assertRedirect();

    $entry = TimeEntry::where('client_uuid', $uuid)->sole();

    expect($entry->duration_seconds)->toBe((5 * 3600) - (47 * 60))
        ->and($entry->isStopped())->toBeTrue();
});

it('reports the day against the schedule target rather than against a score', function (): void {
    $this->tapu->schedule()->create([
        'working_days' => ['mon', 'tue', 'wed', 'thu', 'fri'],
        'working_hours_per_day' => 5,
        'office_or_remote' => 'remote',
    ]);

    TimeEntry::factory()->forEmployee($this->tapu)->onTask($this->task)->create([
        'work_date' => '2026-09-24',
        'duration_seconds' => 2 * 3600,
    ]);

    $this->actingAs($this->tapu->user)
        ->getJson('/employee/time/current')
        ->assertOk()
        ->assertJsonPath('today.counted_seconds', 2 * 3600)
        ->assertJsonPath('today.target_seconds', 5 * 3600);
});

/* ------------------------------------------------- the browser learns the truth */

it('tells a browser through its own ping that the watchdog stopped its timer', function (): void {
    $timer = app(TimerService::class);

    $entry = $timer->start($this->tapu, $this->task, (string) Str::uuid(), Carbon::parse('2026-09-24 09:00:00'));

    Carbon::setTestNow('2026-09-24 09:02:00');
    $timer->heartbeat($entry, Carbon::parse('2026-09-24 09:02:00'));

    Carbon::setTestNow('2026-09-24 09:40:00');
    $timer->stopAbandoned();

    // The tab wakes up and pings, knowing nothing about any of this.
    $this->actingAs($this->tapu->user)
        ->postJson('/employee/time/heartbeat')
        ->assertOk()
        // `running: null` IS the news. The widget throws its own idea of the session away.
        ->assertJsonPath('running', null);
});

it('replays an offline batch over HTTP without double-counting it', function (): void {
    Carbon::setTestNow('2026-09-24 18:05:00');

    $batch = [
        'task_id' => $this->task->id,
        'client_uuid' => (string) Str::uuid(),
        'started_at' => '2026-09-24 09:00:00',
        'heartbeats' => ['2026-09-24 12:00:00', '2026-09-24 13:04:00'],
        'paused_seconds' => 600,
        'stopped_at' => '2026-09-24 18:00:00',
    ];

    $this->actingAs($this->tapu->user)->postJson('/employee/time/replay', $batch)->assertOk();
    $this->actingAs($this->tapu->user)->postJson('/employee/time/replay', $batch)->assertOk();
    $this->actingAs($this->tapu->user)->postJson('/employee/time/replay', $batch)->assertOk();

    $entry = TimeEntry::sole();

    expect(TimeEntry::count())->toBe(1)
        // Capped at the last heartbeat, not the six o'clock the browser claimed.
        ->and($entry->duration_seconds)->toBe((4 * 3600) + (4 * 60) - 600)
        ->and($entry->isFlagged())->toBeTrue();
});

/* ------------------------------------------------------- manual and corrections */

it('will not take a manual entry without a reason', function (): void {
    $this->actingAs($this->tapu->user)
        ->post('/employee/time/entries', [
            'task_id' => $this->task->id,
            'started_at' => '2026-09-24 08:00:00',
            'ended_at' => '2026-09-24 08:45:00',
        ])
        ->assertSessionHasErrors('reason');

    expect(TimeEntry::count())->toBe(0);
});

it('keeps a manual entry out of the day total until it is approved, and says so', function (): void {
    Setting::query()->updateOrCreate(['key' => 'manual_time_requires_approval'], ['value' => true]);

    $this->actingAs($this->tapu->user)
        ->post('/employee/time/entries', [
            'task_id' => $this->task->id,
            'started_at' => '2026-09-24 08:00:00',
            'ended_at' => '2026-09-24 08:45:00',
            'reason' => 'Forgot to start the timer.',
        ])
        ->assertRedirect();

    $this->actingAs($this->tapu->user)
        ->getJson('/employee/time/current')
        ->assertJsonPath('today.counted_seconds', 0)
        // Not counted is not the same as hidden.
        ->assertJsonPath('today.pending_seconds', 45 * 60);
});

it('requires a reason to correct an entry, and refuses to correct a running one', function (): void {
    $timer = app(TimerService::class);
    $running = $timer->start($this->tapu, $this->task, (string) Str::uuid());

    $this->actingAs($this->tapu->user)
        ->put("/employee/time/entries/{$running->id}", [
            'started_at' => '2026-09-24 09:00:00',
            'ended_at' => '2026-09-24 10:00:00',
        ])
        ->assertSessionHasErrors('reason');

    $this->actingAs($this->tapu->user)
        ->put("/employee/time/entries/{$running->id}", [
            'started_at' => '2026-09-24 09:00:00',
            'ended_at' => '2026-09-24 10:00:00',
            'reason' => 'Trying to edit a session that is still going.',
        ])
        ->assertForbidden();
});

/* --------------------------------------------------------------- the Time page */

it('shows the entries by day, with a flagged row carrying its reason in words', function (): void {
    $timer = app(TimerService::class);

    $entry = $timer->start($this->tapu, $this->task, (string) Str::uuid(), Carbon::parse('2026-09-24 09:00:00'));

    // The clock has to move with the session: the service will not bank a heartbeat from a
    // moment that has not happened yet, which is a rule of its own and tested next door.
    Carbon::setTestNow('2026-09-24 13:04:00');
    $timer->heartbeat($entry, Carbon::parse('2026-09-24 13:04:00'));

    Carbon::setTestNow('2026-09-24 18:00:00');
    $timer->stopAbandoned();

    $this->actingAs($this->tapu->user)
        ->get('/employee/time')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Employee/Time/Index')
            ->where('flagged_count', 1)
            ->where('days.0.date', '2026-09-24')
            ->where('days.0.entries.0.is_flagged', true)
            // The reason is a sentence the page can print, not a code it has to translate.
            ->where('days.0.entries.0.flag_reason', fn (string $reason): bool => str_contains($reason, '1:04 pm'))
            ->where('days.0.entries.0.state_label', 'Stopped')
            ->etc());
});

it('never sends a score or a productivity figure to the Time page', function (): void {
    TimeEntry::factory()->forEmployee($this->tapu)->onTask($this->task)->create(['work_date' => '2026-09-24']);

    $response = $this->actingAs($this->tapu->user)->get('/employee/time')->assertOk();

    $payload = json_encode($response->viewData('page'));

    // Part H forbids productivity scoring outright. Tracked time is a record, not a judgement,
    // so neither word may reach the screen — nor any number that would be one in disguise.
    expect(strtolower((string) $payload))
        ->not->toContain('score')
        ->not->toContain('productivity')
        ->not->toContain('efficiency')
        ->not->toContain('rating');
});

it('gives a signed-out visitor the login page, not a timer', function (): void {
    foreach (timerEndpoints() as [$method, $path]) {
        $this->{$method}($path)->assertRedirect('/login');
    }
});

it('is a page a user with no employee record cannot reach', function (): void {
    $stray = User::factory()->create();

    $this->actingAs($stray)->get('/employee/time')->assertForbidden();
});
