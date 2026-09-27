<?php

namespace App\Policies;

use App\Models\Expense;
use App\Models\User;
use App\Support\Permission;

/**
 * Who may see and change expenses (master prompt Part C §1; Part D §13).
 *
 * The mirror of `IncomePolicy`, key for key, and the argument is written out once there: two
 * keys, no role named anywhere, no scope because neither matrix cell is scoped, a 403 rather
 * than a 404 on a denial, and the locked-period block deferred to Phase 9's seam in
 * `FinanceService`.
 *
 * It is a separate class rather than one `FinancePolicy` over both models because Laravel
 * discovers a policy by the model's name, and because Part D §13 may yet diverge the two —
 * an expense has no project link and income does, and Phase 9's payroll writes expenses that
 * nobody should be able to hand-edit. When that day comes the divergence lands in one file
 * instead of in a `match` on a class name.
 */
class ExpensePolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::FinanceView);
    }

    public function view(User $user, Expense $expense): bool
    {
        return $this->allows($user, Permission::FinanceView);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::FinanceManage);
    }

    public function update(User $user, Expense $expense): bool
    {
        return $this->allows($user, Permission::FinanceManage);
    }

    public function delete(User $user, Expense $expense): bool
    {
        return $this->allows($user, Permission::FinanceManage);
    }
}
