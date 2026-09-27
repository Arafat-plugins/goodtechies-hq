<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\EmployeeSalaryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What one employee is paid, from one date onwards (master prompt Part D §14, §20).
 *
 * A row per change, never an edit of an old one — the migration argues that at length. The one
 * question this table answers is *"what was in force on this date"*, and
 * `scopeInForceOn()` is the only place it is answered.
 *
 * @property int $id
 * @property int $employee_id
 * @property string $base_salary
 * @property string $allowance
 * @property Carbon $effective_from
 * @property int $set_by
 */
#[Fillable([
    'employee_id',
    'base_salary',
    'allowance',
    'effective_from',
    'set_by',
])]
class EmployeeSalary extends Model
{
    /** @use HasFactory<EmployeeSalaryFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // A string in, a string out: never a float. See the migration.
            'base_salary' => 'decimal:2',
            'allowance' => 'decimal:2',
            'effective_from' => 'date',
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
    public function setter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by');
    }

    /**
     * The salary in force for one employee on one date — the latest row at or before it.
     *
     * **This is the whole of "an old month recalculates to its old number".** A raise recorded
     * with `effective_from = 2026-11-01` is invisible to `inForceOn(…, '2026-09-01')`, because
     * the `WHERE` never reaches it. Nothing has to remember to exclude it.
     *
     * A scope rather than a method on `Employee`, because it is asked of a query the caller
     * already has — `PayrollService::salaryFor()` asks it once per employee when a draft is
     * created, and `hq:create-payroll-draft` asks it for everybody.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeInForceOn(Builder $query, Employee|int $employee, CarbonInterface|string $date): void
    {
        $query
            ->where('employee_id', $employee instanceof Employee ? $employee->getKey() : $employee)
            ->whereDate('effective_from', '<=', Carbon::parse($date)->toDateString())
            ->orderByDesc('effective_from')
            // Two rows cannot share a date (`employee_salaries_one_per_day`), so this only
            // fixes the order of a tie that cannot happen — and makes the query deterministic
            // for a reader who does not know that.
            ->orderByDesc('id');
    }

    /**
     * Everything this row said, for the `salary.changed` audit trail (Part C §4).
     *
     * The employee's NAME is in it as well as their id, for the reason `Income::auditValues()`
     * carries the category's name: an audit row has to be readable years later, including after
     * the person it is about has left and their record has been deactivated.
     *
     * @return array<string, mixed>
     */
    public function auditValues(): array
    {
        return [
            'id' => (int) $this->getKey(),
            'employee_id' => (int) $this->employee_id,
            'employee_name' => $this->employee?->user?->name,
            'base_salary' => $this->base_salary,
            'allowance' => $this->allowance,
            'effective_from' => $this->effective_from?->toDateString(),
            'set_by' => (int) $this->set_by,
        ];
    }
}
