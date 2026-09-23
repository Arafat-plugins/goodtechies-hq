<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Read-only access to the `daily_work_summary` VIEW (master prompt Part C rule 6).
 *
 * One row per employee per day, joining the office clock (`attendance_records`) to the remote
 * timer (`time_entries`) **for reporting only**. The two tables are never merged, and this is
 * not a third place worked hours are stored: it has no rows of its own, so it cannot disagree
 * with either source or go stale between them. See the view's migration for the predicates.
 *
 * ## Nothing writes here, and that is enforced twice
 *
 * `save()`, `delete()` and every mass write are refused below, and PostgreSQL would refuse them
 * anyway — a view over a FULL OUTER JOIN of two grouped relations is not auto-updatable. The
 * override exists so the refusal arrives as a sentence naming the rule rather than as an SQL
 * error forty frames down.
 *
 * ## Not the operational path
 *
 * The roster's *"Remote — 2h 10m tracked"* comes from `AttendanceService::trackedMinutes()`,
 * which asks `time_entries` directly. This view is what a REPORT reads — the Company
 * dashboard's remote-time cards today, Phase 10's reports later. Both ask the same question the
 * same way (`approved_at is not null`, decision 4-7), and a test asserts the two agree for the
 * same employee and day, because "two answers to how long somebody worked" is the exact bug
 * rule 6 exists to prevent.
 */
class DailyWorkSummary extends Model
{
    /** What a caller is told when it tries to write worked hours to the join instead of a source. */
    private const REFUSAL = 'daily_work_summary is a reporting view over attendance_records and '
        .'time_entries (Part C rule 6). Worked hours are written to one of those two tables, never here.';

    protected $table = 'daily_work_summary';

    /** A view has no surrogate key: a row is identified by the employee and the day. */
    public $incrementing = false;

    /** The view carries no `created_at` / `updated_at`; its sources do. */
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'employee_id' => 'integer',
            'work_date' => 'date',
            'clock_in' => 'datetime',
            'clock_out' => 'datetime',
            'worked_minutes' => 'integer',
            'tracked_minutes' => 'integer',
            'pending_minutes' => 'integer',
            'rejected_minutes' => 'integer',
            'entry_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /* --------------------------------------------------------------------- the scopes */

    /**
     * @param  Builder<DailyWorkSummary>  $query
     */
    public function scopeForDate(Builder $query, CarbonInterface $date): void
    {
        $query->whereDate('work_date', $date->toDateString());
    }

    /**
     * @param  Builder<DailyWorkSummary>  $query
     */
    public function scopeBetween(Builder $query, CarbonInterface $from, CarbonInterface $to): void
    {
        $query->whereBetween('work_date', [$from->toDateString(), $to->toDateString()]);
    }

    /**
     * @param  Builder<DailyWorkSummary>  $query
     * @param  list<int>  $employeeIds
     */
    public function scopeForEmployees(Builder $query, array $employeeIds): void
    {
        $query->whereIn('employee_id', $employeeIds);
    }

    /* ------------------------------------------------------------------- read-only */

    /**
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = []): bool
    {
        throw new \LogicException(self::REFUSAL);
    }

    public function delete(): bool
    {
        throw new \LogicException(self::REFUSAL);
    }
}
