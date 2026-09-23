<?php

use App\Exceptions\TimerStateException;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Setting;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Services\TimerService;
use App\Support\AuditEvent;
use App\Support\RoleName;
use App\Support\TimeEntryType;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| TimerService
|--------------------------------------------------------------------------
|
| Tapu starts a timer in the morning, forgets it at lunch and closes the
| laptop at six. Everything here is a way of asking "and what does the
| system say happened?".
|
| Three rules run through all of it:
|
|   - one open entry per employee is the partial unique index's promise, not
|     the service's `if` — there is a race test below that proves the index
|     is what catches it;
|   - `client_uuid` is the idempotency key and nothing in the service
|     increments, so a replay lands the same row in the same state however
|     many times it arrives;
|   - every threshold is read from `settings` on the call. Each safeguard
|     test changes its setting and watches the behaviour follow.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-24 09:00:00');

    $this->seed(RolePermissionSeeder::class);
    $this->seed(SettingsSeeder::class);

    $this->timer = app(TimerService::class);
    $this->tapu = Employee::factory()->forRole(RoleName::REMOTE_EMPLOYEE)->create();
    $this->task = Task::factory()->create();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** Move the clock and hand back the moment, so a test reads as a sequence of times of day. */
function at(string $time): Carbon
{
    $moment = Carbon::parse('2026-09-24 '.$time);

    Carbon::setTestNow($moment);

    return $moment->copy();
}

/** Change a setting without going through the Admin gate, and drop the per-request cache. */
function setSetting(string $key, mixed $value): void
{
    Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);

    app()->forgetScopedInstances();

    test()->timer = app(TimerService::class);
}

/* ------------------------------------------------------------------- start */

it('starts a timer on a task and records the day, the task and the project', function (): void {
    $entry = $this->timer->start($this->tapu, $this->task, (string) Str::uuid());

    expect($entry->isRunning())->toBeTrue()
        ->and($entry->entry_type)->toBe(TimeEntryType::Auto)
        ->and((int) $entry->project_id)->toBe((int) $this->task->project_id)
        ->and($entry->work_date->toDateString())->toBe('2026-09-24')
        // The start IS the first heartbeat, or a tab closed in the first minute would give
        // rule 1 nothing to measure from.
        ->and($entry->last_heartbeat_at->toIso8601String())->toBe($entry->started_at->toIso8601String())
        ->and($entry->duration_seconds)->toBeNull();
});

it('is idempotent on client_uuid, so a start sent twice makes one entry', function (): void {
    $uuid = (string) Str::uuid();

    $first = $this->timer->start($this->tapu, $this->task, $uuid);
    $second = $this->timer->start($this->tapu, $this->task, $uuid);

    expect($second->getKey())->toBe($first->getKey())
        ->and(TimeEntry::count())->toBe(1);
});

it('refuses a second timer while one is open, running or paused', function (): void {
    $running = $this->timer->start($this->tapu, $this->task, (string) Str::uuid());

    expect(fn () => $this->timer->start($this->tapu, $this->task, (string) Str::uuid()))
        ->toThrow(TimerStateException::class, 'A timer is already going');

    $this->timer->pause($running, Carbon::now()->addMinutes(5));

    // Paused is still open: a second session beside it is how an afternoon gets counted twice.
    expect(fn () => $this->timer->start($this->tapu, $this->task, (string) Str::uuid()))
        ->toThrow(TimerStateException::class);
});

it('lets the database — not the check — be what actually guarantees one open timer', function (): void {
    // The competing row is written from a `creating` listener: strictly after the service's
    // `current()` check has passed, strictly before its own INSERT. Only the partial unique
    // index can settle that, and this asserts the message only reachable from the catch block.
    TimeEntry::creating(function (TimeEntry $entry): void {
        static $once = true;

        if (! $once) {
            return;
        }

        $once = false;

        DB::table('time_entries')->insert([
            'employee_id' => $entry->employee_id,
            'task_id' => $entry->task_id,
            'project_id' => $entry->project_id,
            'work_date' => '2026-09-24',
            'started_at' => Carbon::now(),
            'entry_type' => TimeEntryType::Auto->value,
            'client_uuid' => (string) Str::uuid(),
            'paused_seconds' => 0,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    });

    expect(fn () => $this->timer->start($this->tapu, $this->task, (string) Str::uuid()))
        ->toThrow(TimerStateException::class, 'A timer is already going');

    TimeEntry::flushEventListeners();
});

/* --------------------------------------------------------- the pause arithmetic */

it('stores the wall clock minus the pause, to the second', function (): void {
    $entry = $this->timer->start($this->tapu, $this->task, (string) Str::uuid(), at('09:00:00'));

    // 09:00 → 11:30 running, 11:30 → 12:17 paused, 12:17 → 14:00 running.
    $this->timer->pause($entry, at('11:30:00'));
    $this->timer->resume($entry, at('12:17:00'));

    $this->timer->stop($entry, at('14:00:00'));

    $entry->refresh();

    // 5 h wall clock, 47 min paused → 4 h 13 min.
    expect($entry->paused_seconds)->toBe(47 * 60)
        ->and($entry->duration_seconds)->toBe((5 * 3600) - (47 * 60))
        ->and($entry->isStopped())->toBeTrue()
        ->and($entry->paused_at)->toBeNull();
});

it('counts a pause that is still open up to the moment of the stop', function (): void {
    $entry = $this->timer->start($this->tapu, $this->task, (string) Str::uuid(), at('09:00:00'));

    $this->timer->pause($entry, at('09:40:00'));

    at('10:00:00');
    $this->timer->stop($entry);

    $entry->refresh();

    expect($entry->paused_seconds)->toBe(20 * 60)
        ->and($entry->duration_seconds)->toBe(40 * 60);
});

it('reads a paused entry as frozen at the moment it was paused', function (): void {
    $entry = $this->timer->start($this->tapu, $this->task, (string) Str::uuid(), at('09:00:00'));
    $this->timer->pause($entry, at('09:25:00'));

    at('11:00:00');

    expect($entry->fresh()->elapsedSeconds())->toBe(25 * 60);
});

it('refuses to pause what is paused and to resume what is running', function (): void {
    $entry = $this->timer->start($this->tapu, $this->task, (string) Str::uuid());

    expect(fn () => $this->timer->resume($entry))->toThrow(TimerStateException::class, 'not paused');

    $this->timer->pause($entry, at('09:01:00'));

    expect(fn () => $this->timer->pause($entry))->toThrow(TimerStateException::class, 'already paused');

    $this->timer->stop($entry, at('09:02:00'));

    expect(fn () => $this->timer->stop($entry))->toThrow(TimerStateException::class, 'already been stopped');
});

it('keeps tasks.tracked_seconds as a recomputed sum and never an increment', function (): void {
    $first = $this->timer->start($this->tapu, $this->task, (string) Str::uuid(), at('09:00:00'));
    at('10:00:00');
    $this->timer->stop($first);

    expect((int) DB::table('tasks')->where('id', $this->task->id)->value('tracked_seconds'))->toBe(3600);

    $second = $this->timer->start($this->tapu, $this->task, (string) Str::uuid(), at('11:00:00'));
    at('11:30:00');
    $this->timer->stop($second);

    expect((int) DB::table('tasks')->where('id', $this->task->id)->value('tracked_seconds'))->toBe(3600 + 1800);
});

/* ------------------------------------------------------------- heartbeats */

it('never moves the last heartbeat backwards', function (): void {
    $entry = $this->timer->start($this->tapu, $this->task, (string) Str::uuid(), at('09:00:00'));

    $this->timer->heartbeat($entry, at('09:30:00'));
    // An out-of-order ping from a replayed queue must not hand rule 1 a live entry to kill.
    $this->timer->heartbeat($entry, Carbon::parse('2026-09-24 09:10:00'));

    expect($entry->fresh()->last_heartbeat_at->toIso8601String())
        ->toBe(Carbon::parse('2026-09-24 09:30:00')->toIso8601String());
});

/* ------------------------------------------------- watchdog rule 1: heartbeat */

it('stops a heartbeat-less timer at its last heartbeat and says why', function (): void {
    $entry = $this->timer->start($this->tapu, $this->task, (string) Str::uuid(), at('09:00:00'));
    $this->timer->heartbeat($entry, at('13:04:00'));

    // The laptop was shut at 13:04 and it is now six o'clock.
    at('18:00:00');

    expect($this->timer->stopAbandoned())->toBe([(int) $entry->getKey()]);

    $entry->refresh();

    expect($entry->ended_at->toIso8601String())->toBe(Carbon::parse('2026-09-24 13:04:00')->toIso8601String())
        ->and($entry->duration_seconds)->toBe((4 * 3600) + (4 * 60))
        ->and($entry->isFlagged())->toBeTrue()
        // The flag is a sentence with the threshold and the moment in it, not a code.
        ->and($entry->flag_reason)->toContain('5 minutes')
        ->and($entry->flag_reason)->toContain('1:04 pm');
});

it('reads the heartbeat timeout from settings on every sweep', function (): void {
    $entry = $this->timer->start($this->tapu, $this->task, (string) Str::uuid(), at('09:00:00'));
    $this->timer->heartbeat($entry, at('09:50:00'));

    at('10:20:00');

    setSetting('heartbeat_timeout_minutes', 45);

    // 30 minutes of silence, and the rule now says 45.
    expect($this->timer->stopAbandoned())->toBe([]);

    setSetting('heartbeat_timeout_minutes', 5);

    expect($this->timer->stopAbandoned())->toBe([(int) $entry->getKey()]);
});

it('leaves a session that has only just started alone', function (): void {
    $this->timer->start($this->tapu, $this->task, (string) Str::uuid());

    at('09:00:30');

    expect($this->timer->stopAbandoned())->toBe([]);
});

it('closes an abandoned PAUSED entry too, so tomorrow is not blocked', function (): void {
    $entry = $this->timer->start($this->tapu, $this->task, (string) Str::uuid(), at('09:00:00'));
    $this->timer->pause($entry, at('09:30:00'));

    at('18:00:00');

    expect($this->timer->stopAbandoned())->toBe([(int) $entry->getKey()]);

    $entry->refresh();

    // The pause was still on at the stop point, so the entry banks the half hour before it.
    expect($entry->duration_seconds)->toBe(30 * 60)
        ->and($entry->isOpen())->toBeFalse();

    // And the employee can start again.
    expect($this->timer->start($this->tapu, $this->task, (string) Str::uuid())->isRunning())->toBeTrue();
});

/* ----------------------------------------------- watchdog rule 2: max session */

it('pauses a session that has run past the maximum and says why', function (): void {
    $entry = $this->timer->start($this->tapu, $this->task, (string) Str::uuid(), Carbon::parse('2026-09-24 00:00:00'));

    at('10:30:00');
    // Still pinging — this is not rule 1's case.
    $this->timer->heartbeat($entry, Carbon::now());

    expect($this->timer->pauseOverlongSessions())->toBe([(int) $entry->getKey()]);

    $entry->refresh();

    expect($entry->isPaused())->toBeTrue()
        ->and($entry->isFlagged())->toBeTrue()
        ->and($entry->flag_reason)->toContain('10 hours');
});

it('reads the maximum session length from settings', function (): void {
    $entry = $this->timer->start($this->tapu, $this->task, (string) Str::uuid(), at('09:00:00'));

    at('12:00:00');

    expect($this->timer->pauseOverlongSessions())->toBe([]);

    setSetting('timer_max_session_hours', 2);

    expect($this->timer->pauseOverlongSessions())->toBe([(int) $entry->getKey()]);
});

it('flags a very long session once and does not nag after a resume', function (): void {
    $entry = $this->timer->start($this->tapu, $this->task, (string) Str::uuid(), Carbon::parse('2026-09-24 00:00:00'));

    at('10:30:00');
    $this->timer->pauseOverlongSessions();

    $this->timer->resume($entry->refresh(), Carbon::now());

    at('10:31:00');

    // A safeguard that fires every minute after it has been acknowledged is one people learn
    // to route around. The flag stands; the nagging does not.
    expect($this->timer->pauseOverlongSessions())->toBe([])
        ->and($entry->fresh()->isRunning())->toBeTrue()
        ->and($entry->fresh()->isFlagged())->toBeTrue();
});

/* --------------------------------------------------------- manual entries */

it('counts a manual entry immediately when approval is switched off', function (): void {
    setSetting('manual_time_requires_approval', false);

    $entry = $this->timer->manualEntry(
        $this->tapu,
        $this->task,
        Carbon::parse('2026-09-24 08:00:00'),
        Carbon::parse('2026-09-24 08:45:00'),
        'Forgot to start the timer.',
        $this->tapu->user,
    );

    expect($entry->entry_type)->toBe(TimeEntryType::Manual)
        ->and($entry->duration_seconds)->toBe(45 * 60)
        ->and($entry->counts())->toBeTrue()
        ->and($this->timer->countedSecondsOn($this->tapu, Carbon::parse('2026-09-24')))->toBe(45 * 60);
});

it('keeps a manual entry out of the total until it is approved', function (): void {
    setSetting('manual_time_requires_approval', true);

    $entry = $this->timer->manualEntry(
        $this->tapu,
        $this->task,
        Carbon::parse('2026-09-24 08:00:00'),
        Carbon::parse('2026-09-24 08:45:00'),
        'Forgot to start the timer.',
        $this->tapu->user,
    );

    expect($entry->counts())->toBeFalse()
        ->and($entry->awaitsApproval())->toBeTrue()
        ->and($this->timer->countedSecondsOn($this->tapu, Carbon::parse('2026-09-24')))->toBe(0)
        // Not counted is not the same as hidden: the employee is told where the 45 minutes went.
        ->and($this->timer->pendingSecondsOn($this->tapu, Carbon::parse('2026-09-24')))->toBe(45 * 60);
});

it('refuses a manual entry that ends before it starts, runs into the future, or spans days', function (): void {
    $manual = fn (string $from, string $to) => $this->timer->manualEntry(
        $this->tapu, $this->task, Carbon::parse($from), Carbon::parse($to), 'A reason.', $this->tapu->user,
    );

    expect(fn () => $manual('2026-09-24 10:00:00', '2026-09-24 09:00:00'))
        ->toThrow(TimerStateException::class, 'after the start time')
        ->and(fn () => $manual('2026-09-24 08:00:00', '2026-09-25 09:00:00'))
        ->toThrow(TimerStateException::class)
        ->and(fn () => $manual('2026-09-20 08:00:00', '2026-09-23 08:00:00'))
        ->toThrow(TimerStateException::class, 'longer than 24 hours');
});

/* ------------------------------------------------------------------- edits */

it('writes an audit row with old and new values whenever an entry is corrected', function (): void {
    at('17:30:00');

    $entry = TimeEntry::factory()->forEmployee($this->tapu)->onTask($this->task)->create([
        'started_at' => Carbon::parse('2026-09-24 09:00:00'),
        'ended_at' => Carbon::parse('2026-09-24 17:00:00'),
        'duration_seconds' => 8 * 3600,
    ]);

    $admin = Employee::factory()->forRole(RoleName::ADMIN)->create()->user;

    $this->timer->edit(
        $entry,
        Carbon::parse('2026-09-24 09:00:00'),
        Carbon::parse('2026-09-24 13:00:00'),
        'Tapu left at one; the timer ran on.',
        $admin,
    );

    $log = AuditLog::where('event', AuditEvent::TimeEntryEdited->value)->sole();

    expect((int) $log->actor_id)->toBe((int) $admin->getKey())
        ->and((int) $log->target_id)->toBe((int) $entry->getKey())
        ->and($log->old_value['duration_seconds'])->toBe(8 * 3600)
        ->and($log->new_value['duration_seconds'])->toBe(4 * 3600)
        ->and($entry->fresh()->duration_seconds)->toBe(4 * 3600)
        ->and($entry->fresh()->reason)->toBe('Tapu left at one; the timer ran on.');
});

it('sends a corrected entry back for approval when the setting says so', function (): void {
    at('11:00:00');

    $entry = TimeEntry::factory()->forEmployee($this->tapu)->onTask($this->task)->create();

    expect($entry->counts())->toBeTrue();

    setSetting('manual_time_requires_approval', true);

    $this->timer->edit(
        $entry,
        Carbon::parse('2026-09-24 09:00:00'),
        Carbon::parse('2026-09-24 10:00:00'),
        'Corrected.',
        $this->tapu->user,
    );

    expect($entry->fresh()->counts())->toBeFalse();

    setSetting('manual_time_requires_approval', false);

    $this->timer->edit(
        $entry->fresh(),
        Carbon::parse('2026-09-24 09:00:00'),
        Carbon::parse('2026-09-24 10:30:00'),
        'Corrected again.',
        $this->tapu->user,
    );

    expect($entry->fresh()->counts())->toBeTrue()
        ->and($entry->fresh()->duration_seconds)->toBe(90 * 60);
});

/* ------------------------------------------------------------------ replay */

it('replays an offline session into one entry and caps it at the last heartbeat', function (): void {
    at('18:05:00');

    $uuid = (string) Str::uuid();

    $entry = $this->timer->replay(
        $this->tapu,
        $this->task,
        $uuid,
        Carbon::parse('2026-09-24 09:00:00'),
        ['2026-09-24 12:00:00', '2026-09-24 13:04:00', '2026-09-24 11:00:00'],
        pausedSeconds: 600,
        // The browser woke up at six and believes it ran the whole time.
        stoppedAt: Carbon::parse('2026-09-24 18:00:00'),
    );

    expect($entry->ended_at->toIso8601String())->toBe(Carbon::parse('2026-09-24 13:04:00')->toIso8601String())
        ->and($entry->duration_seconds)->toBe((4 * 3600) + (4 * 60) - 600)
        ->and($entry->isFlagged())->toBeTrue()
        ->and($entry->flag_reason)->toContain('offline');
});

it('cannot double-count a replayed batch, however many times it arrives', function (): void {
    at('18:05:00');

    $uuid = (string) Str::uuid();
    $batch = fn () => $this->timer->replay(
        $this->tapu,
        $this->task,
        $uuid,
        Carbon::parse('2026-09-24 09:00:00'),
        ['2026-09-24 12:00:00', '2026-09-24 13:04:00'],
        pausedSeconds: 600,
        stoppedAt: Carbon::parse('2026-09-24 13:05:00'),
    );

    $first = $batch();
    $again = $batch();
    $andAgain = $batch();

    expect(TimeEntry::count())->toBe(1)
        ->and($andAgain->getKey())->toBe($first->getKey())
        ->and($andAgain->duration_seconds)->toBe($first->duration_seconds)
        ->and($again->duration_seconds)->toBe($first->duration_seconds)
        // The total the day reports is the same after three replays as after one.
        ->and($this->timer->countedSecondsOn($this->tapu, Carbon::parse('2026-09-24')))
        ->toBe((int) $first->duration_seconds);
});

it('lets the watchdog beat the client: a six-hour claim does not undo a stop at the last heartbeat', function (): void {
    $uuid = (string) Str::uuid();

    $entry = $this->timer->start($this->tapu, $this->task, $uuid, at('09:00:00'));
    $this->timer->heartbeat($entry, at('13:04:00'));

    at('18:00:00');
    $this->timer->stopAbandoned();

    $stoppedAt = $entry->fresh()->ended_at->toIso8601String();

    // The laptop comes back and claims it ran until six, with nothing later than 13:04 to
    // show for it.
    $replayed = $this->timer->replay(
        $this->tapu,
        $this->task,
        $uuid,
        Carbon::parse('2026-09-24 09:00:00'),
        ['2026-09-24 13:04:00'],
        stoppedAt: Carbon::parse('2026-09-24 18:00:00'),
    );

    expect(TimeEntry::count())->toBe(1)
        ->and($replayed->ended_at->toIso8601String())->toBe($stoppedAt)
        ->and($replayed->duration_seconds)->toBe((4 * 3600) + (4 * 60))
        ->and($replayed->isFlagged())->toBeTrue();
});

it('extends a watchdog-stopped entry exactly as far as the replayed heartbeats go', function (): void {
    $uuid = (string) Str::uuid();

    $entry = $this->timer->start($this->tapu, $this->task, $uuid, at('09:00:00'));
    $this->timer->heartbeat($entry, at('09:20:00'));

    at('09:40:00');
    $this->timer->stopAbandoned();

    // The buffered queue holds pings the server never received, up to 09:35 — and a claim of
    // 11:00 that nothing supports.
    $replayed = $this->timer->replay(
        $this->tapu,
        $this->task,
        $uuid,
        Carbon::parse('2026-09-24 09:00:00'),
        ['2026-09-24 09:25:00', '2026-09-24 09:35:00'],
        stoppedAt: Carbon::parse('2026-09-24 11:00:00'),
    );

    expect($replayed->ended_at->toIso8601String())
        ->toBe(Carbon::parse('2026-09-24 09:35:00')->toIso8601String())
        ->and($replayed->duration_seconds)->toBe(35 * 60)
        // The flag stays, and its sentence now describes what actually happened to the entry.
        ->and($replayed->isFlagged())->toBeTrue()
        ->and($replayed->flag_reason)->toContain('extended to 9:35 am');
});

it('does not open a second timer when a replay lands beside a live one', function (): void {
    $live = $this->timer->start($this->tapu, $this->task, (string) Str::uuid(), Carbon::parse('2026-09-24 08:00:00'));

    at('09:30:00');

    // A second tab's buffered session, replayed, still running as far as it knows.
    $replayed = $this->timer->replay(
        $this->tapu,
        $this->task,
        (string) Str::uuid(),
        Carbon::parse('2026-09-24 08:30:00'),
        ['2026-09-24 09:00:00'],
    );

    expect($replayed->isStopped())->toBeTrue()
        ->and($live->fresh()->isRunning())->toBeTrue()
        ->and(TimeEntry::query()->open()->count())->toBe(1);
});

it('will not let a replay reach another employee\'s entry through its uuid', function (): void {
    $uuid = (string) Str::uuid();
    $this->timer->start($this->tapu, $this->task, $uuid);

    $other = Employee::factory()->forRole(RoleName::REMOTE_EMPLOYEE)->create();

    expect(fn () => $this->timer->replay($other, $this->task, $uuid, Carbon::parse('2026-09-24 09:00:00')))
        ->toThrow(TimerStateException::class);
});

it('rejects nonsense timestamps in a batch without throwing the rest of it away', function (): void {
    at('14:00:00');

    $entry = $this->timer->replay(
        $this->tapu,
        $this->task,
        (string) Str::uuid(),
        Carbon::parse('2026-09-24 09:00:00'),
        ['not a timestamp', '2026-09-24 11:30:00'],
        stoppedAt: Carbon::parse('2026-09-24 11:30:00'),
    );

    expect($entry->duration_seconds)->toBe(150 * 60);
});

it('never banks time that has not happened yet', function (): void {
    $entry = $this->timer->start(
        $this->tapu,
        $this->task,
        (string) Str::uuid(),
        // A browser clock an hour fast.
        Carbon::parse('2026-09-24 10:00:00'),
    );

    expect($entry->started_at->toIso8601String())->toBe(Carbon::parse('2026-09-24 09:00:00')->toIso8601String());
});
