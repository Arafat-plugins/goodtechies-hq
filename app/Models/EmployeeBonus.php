<?php

namespace App\Models;

use App\Support\BonusPayout;
use App\Support\BonusStatus;
use App\Support\BonusType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One bonus for one employee (Eid, annual, performance, special reward or custom).
 *
 * An approved bonus is linked to the payroll period of the month its payment date falls in,
 * and `BonusService::syncPeriodFor()` writes the sum of the linked approved/paid bonuses into
 * that month's `payroll_items.bonus`. Nothing else writes that column.
 *
 * @property int $id
 * @property int $employee_id
 * @property int|null $payroll_period_id
 * @property BonusType $type
 * @property string|null $title
 * @property string $amount
 * @property string $currency
 * @property Carbon $payment_date
 * @property string|null $reason
 * @property BonusStatus $status
 * @property int|null $created_by
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property int|null $cancelled_by
 * @property Carbon|null $cancelled_at
 * @property string|null $cancellation_reason
 * @property BonusPayout $payout
 * @property Carbon|null $paid_at
 */
#[Fillable([
    'employee_id',
    'payroll_period_id',
    'type',
    'title',
    'amount',
    'currency',
    'payment_date',
    'reason',
    'status',
    'created_by',
    'approved_by',
    'approved_at',
    'cancelled_by',
    'cancelled_at',
    'cancellation_reason',
    'payout',
    'paid_at',
])]
class EmployeeBonus extends Model
{
    protected $table = 'employee_bonuses';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => BonusType::class,
            'status' => BonusStatus::class,
            'amount' => 'decimal:2',
            'payment_date' => 'date',
            'approved_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'payout' => BonusPayout::class,
            'paid_at' => 'datetime',
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
     * @return BelongsTo<PayrollPeriod, $this>
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
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
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * The custom title for a custom bonus, the type's label for every other (`Eid bonus`).
     */
    public function displayName(): string
    {
        if ($this->type === BonusType::Custom && trim((string) $this->title) !== '') {
            return (string) $this->title;
        }

        return $this->type->label();
    }

    /**
     * @return array<string, mixed>
     */
    public function auditValues(): array
    {
        return [
            'id' => (int) $this->getKey(),
            'employee_id' => (int) $this->employee_id,
            'payroll_period_id' => $this->payroll_period_id === null ? null : (int) $this->payroll_period_id,
            'type' => $this->type->value,
            'title' => $this->title,
            'amount' => (string) $this->amount,
            'currency' => $this->currency,
            'payment_date' => $this->payment_date?->toDateString(),
            'reason' => $this->reason,
            'status' => $this->status->value,
            'cancellation_reason' => $this->cancellation_reason,
            'payout' => ($this->payout ?? BonusPayout::WithPayroll)->value,
            'paid_at' => $this->paid_at?->toIso8601String(),
        ];
    }
}
