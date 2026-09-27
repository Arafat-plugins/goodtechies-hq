<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;
use App\Support\Permission;

/**
 * Who may read and administer a personnel record (Part C §1's *Manage roles/permissions* row:
 * *"ADMIN ✅ (not own account)"*).
 *
 * Every ability is `roles.manage` plus, for the five acts Part C §1's parenthesis is about, the
 * self-guard — changing a role, changing a tracking mode, deactivating, reactivating and
 * granting a project permission. The policy is what draws or hides a control and what answers a **403**; the
 * absence of somebody from the list and the **404** on their id are a query
 * (`EmployeeAdministrationService::findFor()`), never a refusal here — a refusal would confirm
 * the record exists (Part C).
 *
 * `EmployeeAdministrationService::guard()` states the self rule a second time, in the service,
 * and that duplication is deliberate: this half stops the request, that half stops the *call*,
 * and a future caller that is not an HTTP request still cannot deactivate its own account.
 *
 * There is **no `delete`** ability and there will not be one. Part B §3 rule 11: an employee who
 * leaves is `status = inactive`, never a missing row.
 */
class EmployeePolicy extends Policy
{
    /**
     * The Employees list. Scoping — which employees are on it — is not asked here; see
     * `EmployeeAdministrationService::listFor()`.
     */
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::RolesManage);
    }

    public function view(User $user, Employee $employee): bool
    {
        return ($user->isActive() && $this->isSelf($user, $employee))
            || $this->allows($user, Permission::RolesManage);
    }

    /**
     * Hiring. Class-first, because there is no record yet — which is the whole point of it.
     */
    public function create(User $user): bool
    {
        return $this->allows($user, Permission::RolesManage);
    }

    public function changeRole(User $user, Employee $employee): bool
    {
        return ! $this->isSelf($user, $employee) && $this->allows($user, Permission::RolesManage);
    }

    /**
     * Changing how somebody's working time is measured — the third of Part D §2's
     * *"role/schedule/tracking_mode"*, and the same question `changeRole` asks about a different
     * field.
     *
     * It carries the self-guard for the same reason the other four do, and the reason is not
     * privilege escalation this time: `tracking_mode` decides which table an Admin's own working
     * day is recorded in, and somebody quietly moving their own days out of `attendance_records`
     * — where `hq:mark-absent` can mark them and Phase 9 reads them — is exactly the act
     * Part C §1's *"(not own account)"* exists to stop. One rule for all five acts, rather than
     * four rules and an exception nobody remembers the reason for.
     */
    public function changeTrackingMode(User $user, Employee $employee): bool
    {
        return ! $this->isSelf($user, $employee) && $this->allows($user, Permission::RolesManage);
    }

    public function deactivate(User $user, Employee $employee): bool
    {
        return ! $this->isSelf($user, $employee) && $this->allows($user, Permission::RolesManage);
    }

    /**
     * Switching a login back on, with the same self-guard the deactivation carries.
     *
     * The self-guard reads as pointless in one direction — somebody whose account is inactive
     * cannot be signed in to ask for it — and it is kept for the direction that is not pointless:
     * `EnsureActiveUser` is one middleware, and an ability that trusted it would be an ability
     * that becomes wrong the day a route forgets it. It also keeps the three self-actions one
     * rule rather than two rules and an exception.
     */
    public function reactivate(User $user, Employee $employee): bool
    {
        return ! $this->isSelf($user, $employee) && $this->allows($user, Permission::RolesManage);
    }

    /**
     * Re-issuing somebody's sign-in password.
     *
     * The same self-guard as every other act here, and here it is doing real work rather than
     * being kept for symmetry: an Admin changes their OWN password at Profile → Password, where
     * they must prove they know the current one. An ability that let them mint themselves a new
     * one from this screen would be a way around that proof, which is the one thing standing
     * between a borrowed unlocked laptop and a password the owner cannot notice has changed.
     */
    public function resetPassword(User $user, Employee $employee): bool
    {
        return ! $this->isSelf($user, $employee) && $this->allows($user, Permission::RolesManage);
    }

    /**
     * Granting and revoking `user_project_permissions` — one ability for both directions.
     *
     * Nobody may do it to their own account. Granting yourself project finance is the privilege
     * escalation Part C §1's parenthesis exists to stop; revoking your own is harmless and is
     * refused anyway, because *the account you are signed in as* is the thing the rule is about
     * and an exception would be a second rule to get wrong.
     */
    public function managePermissions(User $user, Employee $employee): bool
    {
        return ! $this->isSelf($user, $employee) && $this->allows($user, Permission::RolesManage);
    }

    private function isSelf(User $user, Employee $employee): bool
    {
        return $employee->user_id === $user->id;
    }
}
