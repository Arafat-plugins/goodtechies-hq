<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\EmployeeSalary;
use App\Models\User;
use App\Support\Permission;

/**
 * Who may read and set what somebody is paid (master prompt Part C §1, §3; Part D §14).
 *
 * ## Two keys, no role names
 *
 * | ability | key | ADMIN | ACCOUNTANT | everybody else |
 * | ------- | --- | ----- | ---------- | -------------- |
 * | `viewAny` · `view` | `payroll.view_others` | ✅ | ✅ | ❌ |
 * | `create` (set a salary) | `payroll.approve` | ✅ | ❌ | ❌ |
 *
 * **Reading** is `payroll.view_others` because the Accountant already receives every base
 * salary and allowance on the payroll items they draft — Part C §1 gives them *"🟡 amounts, no
 * personal notes"* — so withholding the salary table from them would hide a figure they are
 * handed on the next screen. There is no `viewOwn`: an employee's own salary reaches them as
 * the `base_salary` and `allowance` on their own payslip, through `PayrollItemResource`, which
 * is the one serializer Part B §3 rule 1 names. A second route to the same number would be a
 * second place to get its privacy right.
 *
 * **Setting** is `payroll.approve`, which is Part C §1's *"Manage payroll"* row and which only
 * ADMIN holds — Part D §14 puts *"salary settings per employee (base salary, allowances —
 * audit-logged)"* on the Admin screen and nowhere else. No role is named for it: the key is
 * already exactly the Admin half of that row, and Part C §1 forbids inventing a `salary.manage`
 * key for what is a scope of one that exists.
 *
 * ## There is no `update` and no `delete`, and that is the point of the table
 *
 * `employee_salaries` is history: a row per change, never an edit of an old one (see the
 * migration). Correcting a figure set today is `create` again on the same `effective_from` — the
 * unique index makes it the same row — and there is no verb at all for rewriting what somebody
 * was paid in a month that has been locked and paid. `PayrollService::setSalary()` is the only
 * writer and it asks for `create`.
 */
class EmployeeSalaryPolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::PayrollViewOthers);
    }

    /**
     * One salary row. A refusal is a **404**, like every record-level denial in this
     * application (Part B §3 rule 1) — and Part C §3 goes further for this table in
     * particular: *"Every access attempt to another employee's salary … by an unauthorized role
     * is logged to `audit_logs`"*. That logging is `PayrollService`'s, beside the lookup.
     */
    public function view(User $user, EmployeeSalary $salary): bool
    {
        return $this->allows($user, Permission::PayrollViewOthers);
    }

    /**
     * Set somebody's salary, from a date.
     *
     * The `Employee` is taken but not read: there is no per-person scope here, because Part D
     * §14 gives salary settings to the Admin for the whole team and the matrix has no 🟡 in
     * that cell. It is in the signature so that a later phase adding one — a Manager who may
     * set their own team's pay, say — changes this method rather than every caller.
     */
    public function create(User $user, ?Employee $employee = null): bool
    {
        return $this->allows($user, Permission::PayrollApprove);
    }
}
