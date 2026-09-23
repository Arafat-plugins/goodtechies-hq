<?php

namespace App\Services;

use App\Exceptions\TimerStateException;
use App\Models\Employee;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Support\AuditEvent;
use App\Support\TimeEntryType;
use App\Support\TimerFlag;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The remote timer (master prompt Part D §7, Phase 4).
 *
 * Every rule about a `time_entries` row lives here. The controllers resolve a record, check a
 * policy and call one method; the watchdog command calls two. There is no second place a timer
 * is started, paused or stopped, which is the same discipline `TaskService::transition()` keeps
 * for a status (decision 2-9).
 *
 * ## The three facts this service is built around
 *
 * **1. One open entry per employee is the database's promise, not this file's.** Every method
 * that could create a second one is wrapped in a catch for
 * `time_entries_one_open_per_employee`, the partial unique index. The `if` above the catch
 * exists to produce a readable sentence in the ordinary case; the index exists because the
 * widget, a replayed offline batch and the watchdog can all reach the same employee in the same
 * millisecond and an `if` cannot settle that (decision 3-1).
 *
 * **2. `client_uuid` is the idempotency key, and nothing here ever increments.** Every write
 * sets `ended_at`, `duration_seconds` and `paused_seconds` to absolute values computed from
 * timestamps. Replaying the same batch five times therefore produces the same row five times
 * over — there is no `+=` for a retry to double.
 *
 * **3. The server wins.** A client that was offline for six hours is believed exactly as far as
 * its heartbeats go and not one second further, and an entry the watchdog has already stopped
 * keeps the watchdog's ending unless the replayed heartbeats prove a later one. See `replay()`.
 *
 * ## Thresholds
 *
 * `heartbeat_timeout_minutes`, `timer_max_session_hours` and `manual_time_requires_approval` are
 * read from `SettingsService` on every call. There is no constant for any of them here: an Admin
 * who changes one in Settings has changed the rule, and a value copied into this file is a rule
 * that quietly ignores them.
 */
class TimerService
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    /* ============================================================== reading the state */

    /**
     * This employee's open entry — running or paused — if they have one.
     */
    public function current(Employee $employee): ?TimeEntry
    {
        return TimeEntry::query()
            ->with(['task:id,title,project_id', 'project:id,name'])
            ->where('employee_id', $employee->getKey())
            ->open()
            ->first();
    }

    /**
     * Seconds already banked on `$date`: finished entries that count (see `scopeCounted`).
     *
     * The open entry is deliberately not in here. Its duration is a question about `now()`, so
     * the widget adds its own live elapsed on top rather than the server pretending a running
     * session has a fixed length.
     */
    public function countedSecondsOn(Employee $employee, ?Carbon $date = null): int
    {
        return (int) TimeEntry::query()
            ->where('employee_id', $employee->getKey())
            ->whereDate('work_date', ($date ?? Carbon::today())->toDateString())
            ->stopped()
            ->counted()
            ->sum('duration_seconds');
    }

    /**
     * Seconds on `$date` that are finished but waiting for somebody to sign them off.
     *
     * Reported separately and never folded into the total, because a manual entry that has not
     * been approved has not been agreed — but hiding it entirely is how an employee concludes
     * the system lost their afternoon.
     */
    public function pendingSecondsOn(Employee $employee, ?Carbon $date = null): int
    {
        return (int) TimeEntry::query()
            ->where('employee_id', $employee->getKey())
            ->whereDate('work_date', ($date ?? Carbon::today())->toDateString())
            ->stopped()
            ->whereNull('approved_at')
            ->sum('duration_seconds');
    }

    /**
     * The employee's daily target in seconds — `schedules.working_hours_per_day`, which for Tapu
     * is 5 h. Null when they have no schedule, in which case the widget shows a total and no bar
     * rather than counting against a number nobody set.
     */
    public function targetSecondsFor(Employee $employee): ?int
    {
        $hours = $employee->schedule?->working_hours_per_day;

        if ($hours === null) {
            return null;
        }

        return (int) round(((float) $hours) * 3600);
    }

    /* ==================================================================== the verbs */

    /**
     * Start tracking `$task`.
     *
     * Idempotent on `$clientUuid`: a widget that sent the start, lost the connection before the
     * reply and sent it again gets back the entry it already made, not a second one.
     */
    public function start(Employee $employee, Task $task, string $clientUuid, ?Carbon $at = null): TimeEntry
    {
        $existing = $this->byClientUuid($clientUuid);

        if ($existing !== null) {
            return $existing;
        }

        // The readable refusal. The index below is what actually guarantees it.
        if ($this->current($employee) !== null) {
            throw TimerStateException::alreadyOpen();
        }

        $startedAt = $this->notInTheFuture($at ?? Carbon::now());

        return $this->createOrRecover($clientUuid, fn (): TimeEntry => TimeEntry::create([
            'employee_id' => $employee->getKey(),
            'task_id' => $task->getKey(),
            'project_id' => $task->project_id,
            'work_date' => $this->workDate($startedAt),
            'started_at' => $startedAt,
            'entry_type' => TimeEntryType::Auto,
            'client_uuid' => $clientUuid,
            // The first heartbeat is the start itself: otherwise an entry whose tab is closed
            // in its first minute would have nothing for rule 1 to measure from.
            'last_heartbeat_at' => $startedAt,
        ]));
    }

    /**
     * Pause a running entry. The pause's own clock starts here and is banked on resume or stop.
     */
    public function pause(TimeEntry $entry, ?Carbon $at = null): TimeEntry
    {
        $this->assertOpen($entry);

        if (! $entry->isRunning()) {
            throw TimerStateException::notRunning();
        }

        $at = $this->notBefore($this->notInTheFuture($at ?? Carbon::now()), $entry->started_at);

        $entry->forceFill([
            'paused_at' => $at,
            'last_heartbeat_at' => $this->latest($entry->last_heartbeat_at, $at),
        ])->save();

        return $entry;
    }

    /**
     * Resume a paused entry, banking the pause that just ended.
     */
    public function resume(TimeEntry $entry, ?Carbon $at = null): TimeEntry
    {
        $this->assertOpen($entry);

        if (! $entry->isPaused()) {
            throw TimerStateException::notPaused();
        }

        $at = $this->notBefore($this->notInTheFuture($at ?? Carbon::now()), $entry->paused_at);

        $entry->forceFill([
            'paused_seconds' => (int) $entry->paused_seconds + (int) $entry->paused_at->diffInSeconds($at),
            'paused_at' => null,
            'last_heartbeat_at' => $this->latest($entry->last_heartbeat_at, $at),
        ])->save();

        return $entry;
    }

    /**
     * Stop an open entry at `$at`, optionally flagging it with a reason.
     *
     * This is the one place a duration is written. `$at` may be in the past — that is exactly
     * what watchdog rule 1 does when it ends an entry at its last heartbeat — so a pause that
     * had not begun by `$at` is discarded rather than counted.
     */
    public function stop(TimeEntry $entry, ?Carbon $at = null, ?string $flagReason = null): TimeEntry
    {
        $this->assertOpen($entry);

        $endedAt = $this->notBefore($this->notInTheFuture($at ?? Carbon::now()), $entry->started_at);

        $pausedSeconds = (int) $entry->paused_seconds;

        if ($entry->paused_at !== null && $entry->paused_at->lessThanOrEqualTo($endedAt)) {
            // A pause that started before the stop point ends at the stop point. One that would
            // have started after it never happened as far as this entry is concerned.
            $pausedSeconds += (int) $entry->paused_at->diffInSeconds($endedAt);
        }

        $entry->forceFill([
            ...$this->closure($entry->started_at, $endedAt, $pausedSeconds),
            // An entry the timer measured is approved by the system the moment it stops. Only a
            // hand-written or hand-corrected one has to be signed off.
            'approved_at' => $entry->approved_at ?? Carbon::now(),
        ])->save();

        if ($flagReason !== null) {
            $this->flag($entry, $flagReason);
        }

        $this->refreshTaskTotal((int) $entry->task_id);

        return $entry;
    }

    /**
     * Record that the running client is still there.
     *
     * Monotonic: a heartbeat that arrives out of order, or one a replayed batch carries from
     * before the last live ping, never moves `last_heartbeat_at` backwards — which would hand
     * rule 1 an entry to kill that is perfectly alive.
     */
    public function heartbeat(TimeEntry $entry, ?Carbon $at = null): TimeEntry
    {
        $this->assertOpen($entry);

        $at = $this->notInTheFuture($at ?? Carbon::now());

        $entry->forceFill([
            'last_heartbeat_at' => $this->latest($entry->last_heartbeat_at, $at),
        ])->save();

        return $entry;
    }

    /* ================================================================= offline replay */

    /**
     * Replay what a browser did while it could not reach the server.
     *
     * The batch carries the `client_uuid` the widget generated before the session started, the
     * task, the start, the heartbeats it buffered, the pause it accumulated and — if the session
     * ended offline — the stop it believes in.
     *
     * **Only the heartbeats are evidence.** Everything the batch claims is measured against
     * `max(heartbeats ∪ {started_at})`, and the entry never ends later than that. A laptop that
     * was shut at 13:04 and reopened at 19:00 replays a stop at 19:00 and a last heartbeat at
     * 13:04, and the entry ends at 13:04 with a sentence on it saying why.
     *
     * **Nothing here increments.** Every value written is absolute, so the same batch replayed
     * any number of times lands the same row in the same state. That is what makes the endpoint
     * safe to retry, and it is asserted directly in the tests.
     *
     * @param  list<string>  $heartbeats  ISO timestamps, in any order
     */
    public function replay(
        Employee $employee,
        Task $task,
        string $clientUuid,
        Carbon $startedAt,
        array $heartbeats = [],
        int $pausedSeconds = 0,
        ?Carbon $stoppedAt = null,
    ): TimeEntry {
        $startedAt = $this->notInTheFuture($startedAt);

        // What the client can actually prove. Its own start counts — it reached the server or it
        // did not, and either way the session demonstrably began.
        $evidence = $this->evidence($startedAt, $heartbeats);

        $entry = $this->byClientUuid($clientUuid);

        if ($entry === null) {
            return $this->replayIntoNewEntry(
                $employee, $task, $clientUuid, $startedAt, $evidence, $pausedSeconds, $stoppedAt,
            );
        }

        // Somebody else's uuid is not a key into this employee's timer. Hand back nothing to
        // work with rather than letting a replay touch another person's afternoon.
        if ((int) $entry->employee_id !== (int) $employee->getKey()) {
            throw TimerStateException::alreadyOpen();
        }

        return $entry->isStopped()
            ? $this->replayIntoStoppedEntry($entry, $evidence, $pausedSeconds)
            : $this->replayIntoOpenEntry($entry, $evidence, $pausedSeconds, $stoppedAt);
    }

    /**
     * The batch describes a session the server never heard of.
     */
    private function replayIntoNewEntry(
        Employee $employee,
        Task $task,
        string $clientUuid,
        Carbon $startedAt,
        Carbon $evidence,
        int $pausedSeconds,
        ?Carbon $stoppedAt,
    ): TimeEntry {
        // A session the client says is still going may only stay open if the employee has no
        // other open entry — the one-open rule is not suspended because a laptop was shut. When
        // it must close, it is INSERTED closed rather than opened and then stopped: an open row
        // would collide with the very index this branch exists to respect.
        $mustClose = $stoppedAt !== null || $this->current($employee) !== null;

        $attributes = [
            'employee_id' => $employee->getKey(),
            'task_id' => $task->getKey(),
            'project_id' => $task->project_id,
            'work_date' => $this->workDate($startedAt),
            'started_at' => $startedAt,
            'entry_type' => TimeEntryType::Auto,
            'client_uuid' => $clientUuid,
            'last_heartbeat_at' => $evidence,
            'paused_seconds' => max(0, $pausedSeconds),
        ];

        if ($mustClose) {
            $attributes = [
                ...$attributes,
                ...$this->closure($startedAt, $evidence, max(0, $pausedSeconds)),
                'approved_at' => Carbon::now(),
            ];
        }

        $entry = $this->createOrRecover(
            $clientUuid,
            fn (): TimeEntry => TimeEntry::create($attributes),
        );

        // `createOrRecover` may have handed back a row another request had already written, in
        // which case this batch is a replay after all and the branches above own it.
        if (! $entry->wasRecentlyCreated) {
            return $entry->isStopped()
                ? $this->replayIntoStoppedEntry($entry, $evidence, $pausedSeconds)
                : $this->replayIntoOpenEntry($entry, $evidence, $pausedSeconds, $stoppedAt);
        }

        if ($mustClose) {
            $this->refreshTaskTotal((int) $task->getKey());

            $trimmed = $this->trimReason($stoppedAt, $evidence);

            if ($trimmed !== null) {
                $this->flag($entry, $trimmed);
            }
        }

        return $entry;
    }

    /**
     * The server has already closed this entry — the watchdog did, or the employee stopped it
     * from another tab.
     *
     * The server's ending stands unless the replayed heartbeats prove a later one, which is the
     * spec's "extends the entry only up to the replayed heartbeats". A client that thinks it ran
     * until six o'clock and can show a heartbeat at ten past one gets ten past one.
     */
    private function replayIntoStoppedEntry(TimeEntry $entry, Carbon $evidence, int $pausedSeconds): TimeEntry
    {
        if ($evidence->lessThanOrEqualTo($entry->ended_at)) {
            // Nothing new was proved. Idempotent by construction: this is where the second and
            // third replay of the same batch land.
            return $entry;
        }

        $wasFlagged = $entry->isFlagged();

        $entry->forceFill($this->closure(
            $entry->started_at,
            $evidence,
            max((int) $entry->paused_seconds, max(0, $pausedSeconds)),
        ))->save();

        // The flag survives the extension, but its sentence must not: the old one named the
        // moment the entry used to end, and that is no longer where it ends. A flag whose reason
        // has stopped being true is worse than none.
        if ($wasFlagged) {
            $this->flag($entry, TimerFlag::extendedOnReplay($evidence->format('g:i a')), $entry->flagged_at);
        }

        $this->refreshTaskTotal((int) $entry->task_id);

        return $entry;
    }

    /**
     * The entry is still open here. Bring its heartbeat and pause up to date, and close it if
     * the client says the session ended.
     */
    private function replayIntoOpenEntry(
        TimeEntry $entry,
        Carbon $evidence,
        int $pausedSeconds,
        ?Carbon $stoppedAt,
    ): TimeEntry {
        $entry->forceFill([
            'last_heartbeat_at' => $this->latest($entry->last_heartbeat_at, $evidence),
            // The client knows about pauses the server never heard; the server knows about ones
            // this batch predates. Neither may shrink the other.
            'paused_seconds' => max((int) $entry->paused_seconds, max(0, $pausedSeconds)),
        ])->save();

        if ($stoppedAt !== null) {
            $this->stop($entry, $evidence, $this->trimReason($stoppedAt, $evidence));
        }

        return $entry;
    }

    /**
     * A sentence when the client claimed meaningfully more than it could prove, and null when it
     * did not. The minute of slack is for a browser clock that is a little fast, not for six
     * hours of a closed laptop.
     */
    private function trimReason(?Carbon $stoppedAt, Carbon $evidence): ?string
    {
        if ($stoppedAt === null || $stoppedAt->lessThanOrEqualTo($evidence->copy()->addMinute())) {
            return null;
        }

        return TimerFlag::replayTrimmed($evidence->format('g:i a'));
    }

    /**
     * The latest moment the client can actually vouch for.
     *
     * @param  list<string>  $heartbeats
     */
    private function evidence(Carbon $startedAt, array $heartbeats): Carbon
    {
        $latest = $startedAt->copy();

        foreach ($heartbeats as $heartbeat) {
            try {
                $at = Carbon::parse($heartbeat);
            } catch (\Throwable) {
                // A malformed timestamp is not evidence. It is also not a reason to reject the
                // rest of a batch somebody's afternoon depends on.
                continue;
            }

            $at = $this->notInTheFuture($at);

            if ($at->greaterThan($latest)) {
                $latest = $at;
            }
        }

        return $latest;
    }

    /* ================================================== manual entries and corrections */

    /**
     * A stretch typed in afterwards: "I worked 09:00–11:30 on this and forgot to start it."
     *
     * Always carries a reason — the Form Request requires one — and counts toward totals only
     * when `settings.manual_time_requires_approval` is off. When it is on the row is written
     * with `approved_at` null and is reported as awaiting approval rather than hidden.
     */
    public function manualEntry(
        Employee $employee,
        Task $task,
        Carbon $startedAt,
        Carbon $endedAt,
        string $reason,
        User $actor,
    ): TimeEntry {
        $this->assertSaneRange($startedAt, $endedAt);

        $requiresApproval = (bool) $this->settings->get('manual_time_requires_approval');

        $entry = TimeEntry::create([
            'employee_id' => $employee->getKey(),
            'task_id' => $task->getKey(),
            'project_id' => $task->project_id,
            'work_date' => $this->workDate($startedAt),
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'duration_seconds' => (int) $startedAt->diffInSeconds($endedAt),
            'entry_type' => TimeEntryType::Manual,
            // Server-generated, so every row has exactly one idempotency key and no code path
            // downstream has to reason about a null one.
            'client_uuid' => (string) Str::uuid(),
            'reason' => $reason,
            'approved_at' => $requiresApproval ? null : Carbon::now(),
            'approved_by' => $requiresApproval ? null : $actor->getKey(),
        ]);

        $this->refreshTaskTotal((int) $task->getKey());

        return $entry;
    }

    /**
     * Correct an entry's start and finish, with a reason, and write the change to `audit_logs`.
     *
     * Every edit is recorded old-value-to-new, whoever made it — the employee fixing a timer
     * they forgot to stop, or an Admin correcting somebody else's day. The plan names the Admin
     * case; logging only that one would mean the trail depended on who was signed in, which is
     * not a trail.
     *
     * An edited entry re-enters approval when `manual_time_requires_approval` is on. That is
     * Part D §21's rule for "forgot to stop the timer" read literally: a corrected figure is a
     * claimed figure, and the setting is what decides whether a claim counts unsigned.
     */
    public function edit(
        TimeEntry $entry,
        Carbon $startedAt,
        Carbon $endedAt,
        string $reason,
        User $actor,
    ): TimeEntry {
        $this->assertSaneRange($startedAt, $endedAt);

        $old = [
            'started_at' => $entry->started_at?->toIso8601String(),
            'ended_at' => $entry->ended_at?->toIso8601String(),
            'duration_seconds' => $entry->duration_seconds,
            'reason' => $entry->reason,
        ];

        $requiresApproval = (bool) $this->settings->get('manual_time_requires_approval');

        // A correction states the whole range, so the pause the original session had is already
        // inside the number the person typed. Keeping the old `paused_seconds` on top would
        // subtract it twice.
        $entry->forceFill([
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'work_date' => $this->workDate($startedAt),
            'paused_at' => null,
            'paused_seconds' => 0,
            'duration_seconds' => (int) $startedAt->diffInSeconds($endedAt),
            'reason' => $reason,
            'edited_at' => Carbon::now(),
            'edited_by' => $actor->getKey(),
            'approved_at' => $requiresApproval ? null : ($entry->approved_at ?? Carbon::now()),
            'approved_by' => $requiresApproval ? null : $entry->approved_by,
        ])->save();

        $new = [
            'started_at' => $entry->started_at?->toIso8601String(),
            'ended_at' => $entry->ended_at?->toIso8601String(),
            'duration_seconds' => $entry->duration_seconds,
            'reason' => $entry->reason,
        ];

        $this->audit->record(AuditEvent::TimeEntryEdited, $entry, $old, $new, $actor);

        $this->refreshTaskTotal((int) $entry->task_id);

        return $entry;
    }

    /* ==================================================================== the watchdog */

    /**
     * Rule 1 — no heartbeat for `settings.heartbeat_timeout_minutes`: stop the entry AT its last
     * heartbeat and flag it.
     *
     * "A closed laptop never logs hours" is the whole requirement, and the ending has to be the
     * last heartbeat rather than now, or the laptop logs every hour it was shut.
     *
     * It sweeps paused entries as well as running ones. A paused session banks no time either
     * way, but an open row holds the employee's one timer slot — leave it and Tapu cannot start
     * tomorrow's.
     *
     * @return list<int> the ids stopped
     */
    public function stopAbandoned(?Carbon $now = null): array
    {
        $now = $now ?? Carbon::now();
        $timeout = max(1, (int) $this->settings->get('heartbeat_timeout_minutes'));
        $deadline = $now->copy()->subMinutes($timeout);

        $stale = TimeEntry::query()
            ->open()
            // An entry that has not pinged since it started is measured from its start, so a
            // session begun ten seconds ago is not swept for having no heartbeat yet.
            ->whereRaw('coalesce(last_heartbeat_at, started_at) <= ?', [$deadline])
            ->orderBy('id')
            ->get();

        $stopped = [];

        foreach ($stale as $entry) {
            $endAt = $entry->last_heartbeat_at ?? $entry->started_at;

            $this->stop($entry, $endAt, TimerFlag::heartbeatTimeout($timeout, $endAt->format('g:i a')));

            $stopped[] = (int) $entry->getKey();
        }

        return $stopped;
    }

    /**
     * Rule 2 — running longer than `settings.timer_max_session_hours`: pause it and flag it.
     *
     * Only unflagged entries are swept. Without that the rule fires again a minute after the
     * employee resumes, and then again, and a safeguard that cannot be acknowledged is one
     * people learn to work around. The flag is the record; it does not go away when they carry
     * on, and the Time page keeps saying the session was very long.
     *
     * @return list<int> the ids paused
     */
    public function pauseOverlongSessions(?Carbon $now = null): array
    {
        $now = $now ?? Carbon::now();
        $maxHours = (float) $this->settings->get('timer_max_session_hours');

        if ($maxHours <= 0) {
            return [];
        }

        $deadline = $now->copy()->subSeconds((int) round($maxHours * 3600));

        $overlong = TimeEntry::query()
            ->running()
            ->whereNull('flagged_at')
            ->where('started_at', '<=', $deadline)
            ->orderBy('id')
            ->get();

        $paused = [];

        foreach ($overlong as $entry) {
            $this->pause($entry, $now);
            $this->flag($entry, TimerFlag::maxSession($maxHours), $now);

            $paused[] = (int) $entry->getKey();
        }

        return $paused;
    }

    /* ====================================================================== internals */

    /**
     * A flag is a fact with a reason: the timestamp and the sentence are written together, and
     * there is no way to set one without the other.
     */
    private function flag(TimeEntry $entry, string $reason, ?Carbon $at = null): void
    {
        $entry->forceFill([
            'flagged_at' => $at ?? Carbon::now(),
            'flag_reason' => $reason,
        ])->save();
    }

    /**
     * The columns that close an entry, computed once and used by everything that closes one:
     * the ordinary stop, the watchdog's stop, and a replay that inserts an already-finished
     * session. Duration is wall clock minus pauses, and a pause can never be longer than the
     * session that contains it.
     *
     * @return array<string, mixed>
     */
    private function closure(Carbon $startedAt, Carbon $endedAt, int $pausedSeconds): array
    {
        $wall = (int) $startedAt->diffInSeconds($endedAt);
        $paused = max(0, min($pausedSeconds, $wall));

        return [
            'ended_at' => $endedAt,
            'paused_at' => null,
            'paused_seconds' => $paused,
            'duration_seconds' => $wall - $paused,
        ];
    }

    private function byClientUuid(string $clientUuid): ?TimeEntry
    {
        return TimeEntry::query()->where('client_uuid', $clientUuid)->first();
    }

    /**
     * Run a create that two requests may be making at once, and turn whichever unique index
     * catches it into the right answer.
     *
     * `time_entries_one_open_per_employee` means somebody already has a timer going — the
     * sentence the `if` upstream would have produced had it not been beaten by a millisecond.
     * `client_uuid` means this very batch arrived twice, and the row the other request wrote is
     * the answer.
     *
     * @param  callable(): TimeEntry  $create
     */
    private function createOrRecover(string $clientUuid, callable $create): TimeEntry
    {
        try {
            return DB::transaction($create);
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), TimeEntry::ONE_OPEN_INDEX)) {
                throw TimerStateException::alreadyOpen();
            }

            $existing = $this->byClientUuid($clientUuid);

            if ($existing !== null) {
                return $existing;
            }

            throw $e;
        }
    }

    /**
     * `tasks.tracked_seconds` is a CACHE of this table, recomputed from it and never incremented.
     *
     * The column predates the timer — Phase 2 seeded it so the list had a Time tracked column —
     * so the rule is that the first real entry on a task takes the column over, and from then on
     * it is the sum of that task's counted entries and nothing else. An `+=` here would be a
     * second, drifting statement of a number this table already holds.
     *
     * Written with the query builder rather than the model on purpose: a task's `status` guard
     * (decision 2-9) fires on a model save, and a time entry is not a status move.
     */
    private function refreshTaskTotal(int $taskId): void
    {
        $seconds = (int) TimeEntry::query()
            ->where('task_id', $taskId)
            ->stopped()
            ->counted()
            ->sum('duration_seconds');

        DB::table('tasks')->where('id', $taskId)->update(['tracked_seconds' => $seconds]);
    }

    private function assertOpen(TimeEntry $entry): void
    {
        if ($entry->isStopped()) {
            throw TimerStateException::alreadyStopped();
        }
    }

    private function assertSaneRange(Carbon $startedAt, Carbon $endedAt): void
    {
        if ($endedAt->lessThanOrEqualTo($startedAt)) {
            throw TimerStateException::endsBeforeItStarts();
        }

        if ($endedAt->greaterThan(Carbon::now()->addMinute())) {
            throw TimerStateException::inTheFuture();
        }

        if ($startedAt->diffInSeconds($endedAt) > 86400) {
            throw TimerStateException::longerThanADay();
        }
    }

    /**
     * The local day an entry belongs to, in the app timezone (`settings.timezone`). Stored on
     * the row so "entries by day" is an index lookup and not a conversion inside a WHERE.
     */
    private function workDate(Carbon $at): string
    {
        return $at->copy()->setTimezone(config('app.timezone'))->toDateString();
    }

    /**
     * A browser clock that is ahead does not get to bank time that has not happened. A minute of
     * slack, because clock skew is normal and a refusal over four seconds is not.
     */
    private function notInTheFuture(Carbon $at): Carbon
    {
        $now = Carbon::now();

        return $at->greaterThan($now->copy()->addMinute()) ? $now : $at->copy();
    }

    private function notBefore(Carbon $at, ?Carbon $floor): Carbon
    {
        if ($floor === null) {
            return $at;
        }

        return $at->lessThan($floor) ? $floor->copy() : $at;
    }

    private function latest(?Carbon $a, Carbon $b): Carbon
    {
        return $a === null || $b->greaterThan($a) ? $b->copy() : $a->copy();
    }
}
