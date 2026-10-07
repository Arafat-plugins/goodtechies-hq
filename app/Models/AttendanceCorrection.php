<?php

namespace App\Models;

use App\Support\AttendanceCorrectionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Polish 029: an employee's request to correct one day of their attendance (for example a
 * Late they did not deserve), and the Admin's answer. The day only changes on approval, through
 * `AttendanceService::edit()`.
 *
 * @property int $id
 * @property int $employee_id
 * @property Carbon $date
 * @property string $current_status
 * @property string $reason
 * @property AttendanceCorrectionStatus $status
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $decision_note
 */
#[Fillable(['employee_id', 'date', 'current_status', 'reason', 'status', 'decided_by', 'decided_at', 'decision_note'])]
class AttendanceCorrection extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'decided_at' => 'datetime',
            'status' => AttendanceCorrectionStatus::class,
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
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * What the screens need about one request.
     *
     * @return array{id: int, date: string, current_status: string, reason: string, status: string, status_label: string, decision_note: string|null, decided_by: string|null}
     */
    public function toPayload(): array
    {
        return [
            'id' => (int) $this->getKey(),
            'date' => $this->date->toDateString(),
            'current_status' => $this->current_status,
            'reason' => $this->reason,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'decision_note' => $this->decision_note,
            'decided_by' => $this->decider?->name,
        ];
    }
}
