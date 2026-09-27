<?php

namespace App\Policies;

use App\Models\PayrollItem;
use App\Models\User;
use App\Support\Permission;
use App\Support\RoleName;

/**
 * Who may see and change one employee's line of payroll (master prompt Part B §3 rule 2;
 * Part C §1; Part D §14).
 *
 * ## `view` mirrors the scope, and a refusal is a 404
 *
 * `PayrollItem::scopeVisibleTo()` is the statement of who may see whose line, and this method
 * asks the same question of one row so that a policy call and a scoped list can never disagree.
 * **The scope is the one that matters**: it is what makes somebody else's payslip *absent* from
 * a list and *not found* by id, which is Part B §3 rule 1 — *"a record they may not see is
 * omitted from lists and returns 404 by id"*, never 403, which would confirm it exists.
 *
 * The third part of that rule — the audit row for the attempt — is in
 * `PayrollService::findItemFor()`, because neither a scope nor a policy can tell *"they asked
 * for somebody else's"* from *"they listed their own"*.
 *
 * ## `update` is where the status IS part of the authorization rule
 *
 * Part D §14: *"Admin reviews and approves (**read-only to Accountant afterwards**)"*. That is
 * two different answers for two roles at the same status, which is authorization and not a
 * state-machine guard — so unlike every verb on `PayrollPeriodPolicy`, this one reads the
 * period's status.
 *
 * | period status | ACCOUNTANT | ADMIN |
 * | ------------- | ---------- | ----- |
 * | draft · calculated · reviewed | ✅ | ✅ |
 * | approved      | ❌ *(Part D's "afterwards")* | ✅ |
 * | locked · paid | ❌ | ❌ |
 *
 * The role IS named in that table, and it has to be: the rule Part D states is about the
 * Accountant specifically, and the two roles here hold the same key (`payroll.draft`). It is
 * written as the ADMIN override the other six policies use (decision 7-11) rather than as
 * `hasRole(ACCOUNTANT)`, so a sixth role added later is treated as *not an Admin* — the safe
 * default — instead of silently inheriting the Accountant's window.
 *
 * **Nobody edits a locked or paid period**, which is the same set of statuses that closes the
 * finance ledger. That is not a coincidence: it is what locking a month means.
 */
class PayrollItemPolicy extends Policy
{
    /**
     * May this person open a payroll list at all — the Admin's period detail, the Accountant's,
     * or their own My Payslip.
     *
     * Either key, because both are real answers: `payroll.view_others` opens everybody's lines
     * and `payroll.view_own` opens one. What they then SEE is `scopeVisibleTo()`'s business, and
     * an employee with no payslips yet gets an empty list rather than a refusal.
     */
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::PayrollViewOthers)
            || $this->allows($user, Permission::PayrollViewOwn);
    }

    /**
     * See one line. **A refusal here is a 404** — see the class note.
     *
     * The same three cases as the scope, asked of one row: everybody's, for a holder of
     * `payroll.view_others`; their own, for a holder of `payroll.view_own`; nothing otherwise.
     * No role is named.
     */
    public function view(User $user, PayrollItem $item): bool
    {
        if (! $user->isActive()) {
            return false;
        }

        if ($user->hasPermission(Permission::PayrollViewOthers)) {
            return true;
        }

        return $user->hasPermission(Permission::PayrollViewOwn) && $item->belongsToEmployeeOf($user);
    }

    /**
     * Change the figures on one line — base, allowance, bonus, deduction, advance.
     *
     * The key is `payroll.draft` (Part C §1's *"Manage payroll (draft/calculate/approve)"* row),
     * and the status window is the table in the class note.
     */
    public function update(User $user, PayrollItem $item): bool
    {
        if (! $this->allows($user, Permission::PayrollDraft)) {
            return false;
        }

        $status = $item->period?->status;

        if ($status === null) {
            return false;
        }

        return $user->hasRole(RoleName::ADMIN)
            ? $status->isOpenToAdmin()
            : $status->isOpenToAccountant();
    }

    /**
     * Write the **personal notes** column.
     *
     * ADMIN only, and it is the one verb in this file that has nothing to do with the period's
     * status. Part D §14 calls `admin_notes` *"the 'personal notes' column the Accountant never
     * receives"*; `PayrollItemResource` sends it to nobody else, and it is no part of the
     * payslip — so a note added to a locked month changes nothing that was paid and nothing
     * anybody else can read. Refusing it after the lock would only mean that the note explaining
     * *why* the lock was reversed could not be written on the item it is about.
     *
     * The key beside the role is `payroll.view_others`: you cannot annotate a line you are not
     * entitled to see.
     */
    public function annotate(User $user, PayrollItem $item): bool
    {
        return $this->allows($user, Permission::PayrollViewOthers) && $user->hasRole(RoleName::ADMIN);
    }
}
