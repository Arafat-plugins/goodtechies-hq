<?php

namespace App\Policies;

use App\Models\EmployeeBonus;
use App\Models\User;
use App\Support\BonusPayout;
use App\Support\BonusStatus;
use App\Support\Permission;

/**
 * Who may create, edit, approve, mark paid and cancel a bonus. Holders of `payroll.draft` create, edit
 * and cancel; only `payroll.approve` approves. A paid or cancelled bonus is read-only to all.
 * Whether the bonus's payroll month is still open is `BonusService`'s check, not this one's.
 */
class EmployeeBonusPolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::PayrollDraft);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::PayrollDraft);
    }

    public function update(User $user, EmployeeBonus $bonus): bool
    {
        return $this->mayChange($user, $bonus);
    }

    public function approve(User $user, ?EmployeeBonus $bonus = null): bool
    {
        return $this->allows($user, Permission::PayrollApprove) && ($bonus === null || $this->isOpen($bonus));
    }

    public function cancel(User $user, EmployeeBonus $bonus): bool
    {
        return $this->mayChange($user, $bonus);
    }

    /** Only `payroll.approve` pays an approved bonus that is paid separately, outside payroll. */
    public function markPaid(User $user, EmployeeBonus $bonus): bool
    {
        return $this->allows($user, Permission::PayrollApprove)
            && $bonus->payout === BonusPayout::Separate
            && $bonus->status === BonusStatus::Approved;
    }

    /** A pending bonus needs `payroll.draft`; an approved one needs `payroll.approve`. */
    private function mayChange(User $user, EmployeeBonus $bonus): bool
    {
        return match ($bonus->status) {
            BonusStatus::Approved => $this->allows($user, Permission::PayrollApprove),
            BonusStatus::Pending => $this->allows($user, Permission::PayrollDraft),
            default => false,
        };
    }

    private function isOpen(EmployeeBonus $bonus): bool
    {
        return $bonus->status === BonusStatus::Pending || $bonus->status === BonusStatus::Approved;
    }
}
