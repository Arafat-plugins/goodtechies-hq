<?php

namespace App\Models;

use App\Support\Permission;
use App\Support\TimeEntryType;
use Database\Factories\TimeEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One stretch of tracked work (master prompt Part D §7).
 *
 * ## Running, paused and stopped are read, never stored
 *
 * The three states are the two nullable timestamps that cause them, and the scopes below are the
 * single spelling of each predicate — the same one the partial unique index in the migration is
 * written against. Adding a `state` column would be a second statement of the same three facts
 * (decision 2-37), and the pair would first disagree on the day a watchdog stop forgot to keep
 * them in step.
 *
 * ## Duration
 *
 * `duration_seconds` is a FACT about a finished entry, written once at stop. While the entry is
 * open its duration is a question about `now()`, so it is null on the row and answered by
 * `elapsedSeconds()`. Nothing anywhere adds a running entry into a stored total.
 */
#[Fillable([
    'employee_id',
    'task_id',
    'project_id',
    'work_date',
    'started_at',
    'ended_at',
    'paused_at',
    'paused_seconds',
    'duration_seconds',
    'entry_type',
    'client_uuid',
    'last_heartbeat_at',
    'flagged_at',
    'flag_reason',
    'approved_at',
    'approved_by',
    'reason',
    'edited_at',
    'edited_by',
])]
class TimeEntry extends Model
{
    /** @use HasFactory<TimeEntryFactory> */
    use HasFactory;

    /**
     * The name of the partial unique index that guarantees one open entry per employee. Read by
     * TimerService when it turns a race into a sentence — see the catch block there.
     */
    public const ONE_OPEN_INDEX = 'time_entries_one_open_per_employee';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'paused_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
            'flagged_at' => 'datetime',
            'approved_at' => 'datetime',
            'edited_at' => 'datetime',
            'entry_type' => TimeEntryType::class,
            'paused_seconds' => 'integer',
            'duration_seconds' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by');
    }

    /* ------------------------------------------------------------ the three states */

    /** Open: started and not finished. Running OR paused — both block a second timer. */
    public function isOpen(): bool
    {
        return $this->ended_at === null;
    }

    public function isRunning(): bool
    {
        return $this->ended_at === null && $this->paused_at === null;
    }

    public function isPaused(): bool
    {
        return $this->ended_at === null && $this->paused_at !== null;
    }

    public function isStopped(): bool
    {
        return $this->ended_at !== null;
    }

    public function isFlagged(): bool
    {
        return $this->flagged_at !== null;
    }

    /** The one question every total asks. A manual entry awaiting approval answers false. */
    public function counts(): bool
    {
        return $this->approved_at !== null;
    }

    /** A finished manual entry that nobody has signed off yet. */
    public function awaitsApproval(): bool
    {
        return $this->isStopped() && $this->approved_at === null;
    }

    /** One word for the state, for a payload and for a screen that must not use colour alone. */
    public function stateKey(): string
    {
        return match (true) {
            $this->isRunning() => 'running',
            $this->isPaused() => 'paused',
            default => 'stopped',
        };
    }

    /* ------------------------------------------------------------------- the maths */

    /**
     * Seconds of work in this entry as of `$now`: wall clock minus every pause.
     *
     * A stopped entry answers from its stored `duration_seconds`, because that is what was
     * agreed at stop and a later clock change must not move it. An open entry is measured, and a
     * paused one is measured to the moment it was paused — which is the whole point of pausing.
     */
    public function elapsedSeconds(?Carbon $now = null): int
    {
        if ($this->isStopped()) {
            return (int) $this->duration_seconds;
        }

        $until = $this->paused_at ?? ($now ?? Carbon::now());

        $seconds = $this->started_at->diffInSeconds($until, false) - (int) $this->paused_seconds;

        // A clock that went backwards, or a `now` handed in from before the start, is not
        // negative work.
        return max(0, (int) $seconds);
    }

    /**
     * Seconds spent paused as of `$now`, including the pause that is still open.
     */
    public function pausedSeconds(?Carbon $now = null): int
    {
        $accumulated = (int) $this->paused_seconds;

        if ($this->paused_at === null) {
            return $accumulated;
        }

        return $accumulated + max(0, (int) $this->paused_at->diffInSeconds($now ?? Carbon::now(), false));
    }

    /* -------------------------------------------------------------------- the scopes */

    /**
     * @param  Builder<TimeEntry>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('ended_at');
    }

    /**
     * @param  Builder<TimeEntry>  $query
     */
    public function scopeRunning(Builder $query): void
    {
        $query->whereNull('ended_at')->whereNull('paused_at');
    }

    /**
     * @param  Builder<TimeEntry>  $query
     */
    public function scopePaused(Builder $query): void
    {
        $query->whereNull('ended_at')->whereNotNull('paused_at');
    }

    /**
     * @param  Builder<TimeEntry>  $query
     */
    public function scopeStopped(Builder $query): void
    {
        $query->whereNotNull('ended_at');
    }

    /**
     * Entries that count toward a total. The single predicate: somebody (or the system) approved
     * it. See the migration's docblock.
     *
     * @param  Builder<TimeEntry>  $query
     */
    public function scopeCounted(Builder $query): void
    {
        $query->whereNotNull('approved_at');
    }

    /**
     * @param  Builder<TimeEntry>  $query
     */
    public function scopeFlagged(Builder $query): void
    {
        $query->whereNotNull('flagged_at');
    }

    /**
     * Everything this user may see. `Task::visibleTo()`'s shape, for this table.
     *
     * An employee sees their own entries and nobody else's, which is what makes another
     * employee's entry ABSENT rather than refused: every controller resolves through this scope
     * and `firstOrFail()`, so a foreign id answers 404 (Part C §1).
     *
     * Whoever manages other people's attendance — the Admin, and a Manager — sees every entry,
     * because Admin → Workforce → Time is their screen. That screen is a later slice; the
     * predicate is stated here once so that the slice which builds it does not write a second
     * one in a controller (decision 2-37).
     *
     * @param  Builder<TimeEntry>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->isActive() && $user->hasPermission(Permission::AttendanceManageOthers)) {
            return;
        }

        $employeeId = $user->employee?->getKey();

        if ($employeeId === null) {
            // Not an employee at all: sees nothing, rather than everything.
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where('time_entries.employee_id', $employeeId);
    }
}
