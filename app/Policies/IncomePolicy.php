<?php

namespace App\Policies;

use App\Models\Income;
use App\Models\User;
use App\Support\Permission;

/**
 * Who may see and change income (master prompt Part C §1; Part D §13).
 *
 * ## Two keys, no roles, and no scope
 *
 * The matrix gives *View finance (income/expense)* and *Manage finance* to **ADMIN and
 * ACCOUNTANT** and to nobody else. Both cells are ✅ for both roles — there is no 🟡 anywhere on
 * either row — so there is nothing to scope: a person either has the company's books or does
 * not. `finance.view` reads them and `finance.manage` writes them, and that is the whole file.
 *
 * **No role is named here**, and that is how the Employee's and the Remote employee's 403 is
 * spelled: they hold neither key, so they fall out of every method without appearing in any of
 * them — exactly as `meetings.use` and `messages.use` spell *"the Accountant has no meetings"*
 * and *"the Accountant has no messaging routes"* (decisions 2-13, 2-31). The practical
 * consequence is the one worth writing down: **a future bookkeeper role gets these screens by
 * being granted the two keys in `RolePermissionSeeder`, with no edit to this file.**
 *
 * Contrast `MeetingPolicy`, which does name ADMIN — because a meeting has an owner and an Admin
 * override on somebody else's record is a scope. A finance record has no owner. The Accountant
 * who entered September's invoices and the Admin looking at them have exactly the same rights
 * over the row, because the row is the company's, not the recorder's. `recorded_by` is a fact
 * about who typed it, never a claim on it.
 *
 * ## A denial here is a 403, not a 404
 *
 * Part B §3 rule 1 makes a record-level denial a 404 — *they must not learn it exists*. That
 * rule is about a record inside a feature the person can otherwise use: one employee's payroll
 * item, one project they are not on. This is the other case. An Employee is refused the finance
 * feature entirely, not one row of it, and there is no id they could guess that would behave
 * differently. So the honest answer is *you may not do that*, and the phase's own security list
 * says it in those words: *"Employees/Remote get **403** on every finance route"*.
 *
 * ## The locked-period block is NOT here
 *
 * Part D §13 blocks an edit whose `date` falls inside a `LOCKED` or `PAID` payroll period. That
 * is **Phase 9** — the table does not exist yet — and it would not belong in this file in any
 * case: it is a fact about the month, not about the person. Its seam is
 * `FinanceService::assertPeriodIsOpen()`.
 */
class IncomePolicy extends Policy
{
    /** The income list, and every rollup that reads these rows. */
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::FinanceView);
    }

    public function view(User $user, Income $income): bool
    {
        return $this->allows($user, Permission::FinanceView);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::FinanceManage);
    }

    /**
     * Editing one. `finance.manage` and nothing else — in particular not *"you recorded it"*.
     * See the class note: the row belongs to the company.
     */
    public function update(User $user, Income $income): bool
    {
        return $this->allows($user, Permission::FinanceManage);
    }

    /**
     * Deleting one — a **hard** delete, with the whole row written to `audit_logs` first
     * (`FinanceService::deleteIncome()`).
     *
     * The same key as editing, deliberately. It was tempting to reserve deletion for an Admin,
     * and it was rejected: Part D §13 gives the Accountant the books, Part C §4 names
     * *"finance record deleted"* as an audited event precisely because it is an act the people
     * who keep the books perform, and a deletion nobody can perform is a mistyped amount that
     * stays in the ledger for ever. The protection is the audit row, not a smaller list of
     * people.
     */
    public function delete(User $user, Income $income): bool
    {
        return $this->allows($user, Permission::FinanceManage);
    }
}
