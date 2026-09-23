<?php

namespace App\Models;

use App\Support\AttendanceStatus;
use Database\Factories\AttendanceRecordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * One office day for one employee.
 *
 * The row is the record of a presence, not a judgement about it: there is no score here, no
 * rating and nothing that ranks one employee against another (Part H §1). Everything this
 * model can be asked is a fact — when somebody arrived, when they left, and which of Part D
 * §8's statuses the day was given.
 *
 * A row exists only for a day something happened on. Off Day and Remote have no rows at all;
 * `AttendanceService` derives them from the schedule and the tracking mode.
 *
 * @property int $id
 * @property int $employee_id
 * @property Carbon $date
 * @property Carbon|null $clock_in
 * @property Carbon|null $clock_out
 * @property AttendanceStatus $status
 * @property string|null $note
 * @property int|null $edited_by
 */
#[Fillable(['employee_id', 'date', 'clock_in', 'clock_out', 'status', 'note', 'edited_by'])]
class AttendanceRecord extends Model
{
    /** @use HasFactory<AttendanceRecordFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'clock_in' => 'datetime',
            'clock_out' => 'datetime',
            'status' => AttendanceStatus::class,
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
     * The Admin who last corrected this row by hand. Null on a row nobody edited — which is
     * how the month grid marks an edited day without reading the audit log per cell.
     *
     * @return BelongsTo<User, $this>
     */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by');
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeForDate(Builder $query, Carbon $date): void
    {
        $query->whereDate('date', $date);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeBetween(Builder $query, Carbon $from, Carbon $to): void
    {
        $query->whereBetween('date', [$from->toDateString(), $to->toDateString()]);
    }

    /**
     * Minutes between clock-in and clock-out, or null while the day is still open.
     *
     * Null and zero are different answers and the difference is the point: null means "still
     * in the office", zero means "clocked out in the same minute". A screen that printed 0m
     * for somebody who has not left yet would be reporting a day that has not happened.
     */
    public function workedMinutes(): ?int
    {
        if ($this->clock_in === null || $this->clock_out === null) {
            return null;
        }

        return (int) $this->clock_in->diffInMinutes($this->clock_out);
    }

    public function isOpen(): bool
    {
        return $this->clock_in !== null && $this->clock_out === null;
    }

    /**
     * The values an audit row records for this attendance record.
     *
     * One shape, used for both halves of an edit, so the `old_value` and `new_value` of an
     * `attendance.edited` row are the same keys and a reader can diff them by eye. Times are
     * ISO strings rather than Carbon instances because the column is JSON and a serialised
     * Carbon carries a timezone blob nobody reading an audit log wants to parse.
     *
     * @return array<string, mixed>
     */
    public function auditValues(): array
    {
        return [
            'date' => $this->date?->toDateString(),
            'status' => $this->status?->value,
            'clock_in' => $this->clock_in?->toIso8601String(),
            'clock_out' => $this->clock_out?->toIso8601String(),
            'note' => $this->note,
        ];
    }

    /**
     * Insert a row only if that employee has no row for that date yet, and say whether it went
     * in — the `hq:mark-absent` sweep's one write.
     *
     * `insertOrIgnore` against `unique(employee_id, date)` rather than a SELECT and an INSERT:
     * the sweep runs unattended at 23:55 and may be retried, and somebody clocking in at 23:54
     * must win. A row that loses the race is not an error, it is the correct outcome — which is
     * exactly why this returns a count instead of throwing, and why the command can report
     * "already recorded" without a second query.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function insertIfAbsent(array $attributes): bool
    {
        $now = now();

        return static::query()->insertOrIgnore([
            ...$attributes,
            'created_at' => $now,
            'updated_at' => $now,
        ]) > 0;
    }

    /**
     * Rows keyed by `Y-m-d`, for a month grid that must look up every day of a month without
     * a query per cell.
     *
     * @param  Collection<int, static>  $records
     * @return array<string, static>
     */
    public static function keyByDate(Collection $records): array
    {
        return $records
            ->keyBy(fn (self $record): string => $record->date->toDateString())
            ->all();
    }

    /**
     * A day's row for one employee, locked for update inside a transaction.
     *
     * The clock-in and clock-out paths both read-then-write, and both may be pressed twice in
     * the same second by somebody on a phone who is not sure the first tap landed. The row
     * lock makes the second tap wait for the first rather than read a stale absence of a row.
     */
    public static function lockForDay(int $employeeId, Carbon $date): ?static
    {
        return static::query()
            ->where('employee_id', $employeeId)
            ->whereDate('date', $date)
            ->lockForUpdate()
            ->first();
    }
}
