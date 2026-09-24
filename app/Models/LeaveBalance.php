<?php

namespace App\Models;

use Database\Factories\LeaveBalanceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * How many days of one capped type one employee has left.
 *
 * One number, moved by exactly two things — an approval decrementing it, and an Admin setting
 * it with a reason — and both go through `LeaveService`. There is no accrual and no automatic
 * top-up; Part D §9 says so twice.
 *
 * A row exists only for a type that HAS a balance. Unpaid and Other are uncapped, so there is
 * nothing for a row of theirs to hold, and `LeaveService::setBalance()` refuses to make one.
 *
 * @property int $id
 * @property int $employee_id
 * @property int $leave_type_id
 * @property int $balance_days
 * @property Carbon|null $updated_at
 */
#[Fillable(['employee_id', 'leave_type_id', 'balance_days'])]
class LeaveBalance extends Model
{
    /** @use HasFactory<LeaveBalanceFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'balance_days' => 'integer',
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
     * @return BelongsTo<LeaveType, $this>
     */
    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    /**
     * One employee's balance for one type, locked for update inside a transaction.
     *
     * An approval reads the number, checks it and writes it back, and so does an Admin's edit.
     * Two approvals of two requests for the same person landing in the same second must not
     * both read the same balance and both spend it — the lock makes the second wait for the
     * first, and the `CHECK (balance_days >= 0)` catches anything that still gets through.
     */
    public static function lockFor(int $employeeId, int $leaveTypeId): ?static
    {
        return static::query()
            ->where('employee_id', $employeeId)
            ->where('leave_type_id', $leaveTypeId)
            ->lockForUpdate()
            ->first();
    }

    /**
     * The values an audit row records for a balance.
     *
     * One shape for both halves of an adjustment, so the `old_value` and `new_value` of a
     * `leave.balance_adjusted` row are the same keys and a reader diffs them by eye — exactly
     * as `AttendanceRecord::auditValues()` does for an attendance correction.
     *
     * @return array<string, mixed>
     */
    public function auditValues(): array
    {
        return [
            'employee_id' => (int) $this->employee_id,
            'leave_type_id' => (int) $this->leave_type_id,
            'leave_type' => $this->leaveType?->name,
            'balance_days' => (int) $this->balance_days,
        ];
    }
}
