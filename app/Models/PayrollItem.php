<?php

namespace App\Models;

use App\Support\Permission;
use Database\Factories\PayrollItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One employee's line in one month of payroll (master prompt Part D §14, §20).
 *
 * ## `net_salary` is read-only, because PostgreSQL computes it
 *
 * The column is `GENERATED ALWAYS AS (base_salary + allowance + bonus - deduction - advance -
 * leave_impact) STORED` (see the migration). It is not fillable and nothing in PHP assigns it;
 * writing it is an error from the database, which is exactly the point. After any write that
 * changes a component, `refresh()` — or a re-read — is what gets the new net; `PayrollService`
 * does that for its callers.
 *
 * ## `scopeVisibleTo()` is Part B §3 rule 2, and it is a SCOPE on purpose
 *
 * > *"Every payroll/salary query defaults to `employee_id = current_user.employee_id` unless the
 * > requester's role is explicitly ADMIN or ACCOUNTANT."*
 *
 * Expressed here as the two permission keys Part C §1 gives those two roles rather than as their
 * names — `payroll.view_others` for the wide view, `payroll.view_own` for one's own line. A
 * scope and not a policy call, for the reason `LeaveRequest::scopeVisibleTo()` is one: it makes
 * somebody else's payslip **absent** rather than refused. A list narrowed by it does not contain
 * them, and a lookup by id comes back empty — so **404 is what the caller has rather than what
 * it decides** (Part C, Part B §3 rule 1: a 403 would confirm the record exists).
 *
 * The third half of that rule — the audit row for the attempt — is not here, because a scope
 * cannot tell "you asked for somebody else's" from "you listed your own". It is in
 * `PayrollService::findItemFor()`, which is the only way a single item is fetched by id.
 *
 * @property int $id
 * @property int $payroll_period_id
 * @property int $employee_id
 * @property string $base_salary
 * @property string $allowance
 * @property string $bonus
 * @property string $deduction
 * @property string $advance
 * @property string $leave_impact
 * @property string $net_salary
 * @property string|null $admin_notes
 */
#[Fillable([
    'payroll_period_id',
    'employee_id',
    'base_salary',
    'allowance',
    'bonus',
    'deduction',
    'advance',
    'leave_impact',
    'admin_notes',
])]
class PayrollItem extends Model
{
    /** @use HasFactory<PayrollItemFactory> */
    use HasFactory;

    /**
     * The figures the Accountant fills and adjusts (Part D §14).
     *
     * `leave_impact` is **not** on this list: it is computed by Calculate from approved leave,
     * never typed. Neither is `admin_notes`, which is the Admin's alone — see
     * `PayrollService::annotate()`.
     *
     * @var list<string>
     */
    public const ADJUSTABLE = ['base_salary', 'allowance', 'bonus', 'deduction', 'advance'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Strings in, strings out: never floats. See the migration.
            'base_salary' => 'decimal:2',
            'allowance' => 'decimal:2',
            'bonus' => 'decimal:2',
            'deduction' => 'decimal:2',
            'advance' => 'decimal:2',
            'leave_impact' => 'decimal:2',
            'net_salary' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<PayrollPeriod, $this>
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * The items this user may see at all. See the class docblock.
     *
     * Three cases and no fourth:
     *
     *   - **nobody**, for a signed-out or deactivated user;
     *   - **everybody**, for a holder of `payroll.view_others` — Part C §1 gives it to ADMIN
     *     (✅) and ACCOUNTANT (🟡 amounts, no personal notes). The 🟡 is a FIELD rule, not a
     *     row rule, so it is `PayrollItemResource`'s business and not this scope's: the
     *     Accountant sees every line, minus one column.
     *   - **their own**, for a holder of `payroll.view_own` — which Part C §1 gives to every
     *     role, the Accountant included. It is still asked, so a role that lost it loses its
     *     payslip rather than keeping one because everybody else has it.
     *
     * No role is named anywhere in it.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeVisibleTo(Builder $query, ?User $user): void
    {
        if ($user === null || ! $user->isActive()) {
            $query->whereRaw('1 = 0');

            return;
        }

        if ($user->hasPermission(Permission::PayrollViewOthers)) {
            return;
        }

        $query->where(
            'payroll_items.employee_id',
            $user->hasPermission(Permission::PayrollViewOwn) ? $user->employee?->getKey() : null,
        );
    }

    /**
     * Is this line about this user? The question `PayrollService::findItemFor()` asks before it
     * decides whether a refusal is worth an audit row.
     */
    public function belongsToEmployeeOf(?User $user): bool
    {
        $own = $user?->employee?->getKey();

        return $own !== null && (int) $this->employee_id === (int) $own;
    }
}
