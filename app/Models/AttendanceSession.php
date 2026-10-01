<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One clock-in -> clock-out stretch of an office day (decision 12-76).
 *
 * An office day may hold several: somebody clocks out for lunch and back in after it. The day's
 * `attendance_records` row keeps the first clock-in, the last clock-out and the status; its
 * worked time is the sum of these rows' minutes, and the gaps between them are breaks.
 *
 * @property int $id
 * @property int $attendance_record_id
 * @property Carbon $clock_in
 * @property Carbon|null $clock_out
 */
#[Fillable(['attendance_record_id', 'clock_in', 'clock_out'])]
class AttendanceSession extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'clock_in' => 'datetime',
            'clock_out' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AttendanceRecord, $this>
     */
    public function record(): BelongsTo
    {
        return $this->belongsTo(AttendanceRecord::class, 'attendance_record_id');
    }

    /**
     * Whole minutes in this session, or null while it is still open. Floor of seconds / 60 —
     * the same rule `daily_work_summary` uses, so PHP and SQL never disagree by a minute.
     */
    public function minutes(): ?int
    {
        if ($this->clock_out === null) {
            return null;
        }

        return intdiv((int) $this->clock_in->diffInSeconds($this->clock_out, true), 60);
    }
}
