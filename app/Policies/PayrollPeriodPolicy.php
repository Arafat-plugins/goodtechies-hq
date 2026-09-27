<?php

namespace App\Policies;

use App\Models\PayrollPeriod;
use App\Models\User;
use App\Support\Permission;
use App\Support\RoleName;

/**
 * Who may do each step of the payroll state machine (master prompt Part C §1; Part D §14).
 *
 * Part D §14 is the whole of this file:
 *
 * > *"Accountant fills/adjusts base salary, allowance, bonus, deduction, advance; Calculate
 * > applies leave-impact rules; **Admin reviews and approves** (read-only to Accountant
 * > afterwards); Lock closes the period; **only ADMIN can reverse a lock** (requires reason,
 * > audit-logged); Paid → payslip generated and released."*
 *
 * ## The capability is a permission key; the one "only ADMIN" is a role
 *
 * Part C §1 already gives the split two keys and this policy uses them exactly as the matrix
 * draws them:
 *
 * | ability       | key                | ADMIN | ACCOUNTANT | everybody else |
 * | ------------- | ------------------ | ----- | ---------- | -------------- |
 * | `viewAny`/`view` | `payroll.view_others` | ✅ | 🟡 amounts | ❌ |
 * | `calculate`   | `payroll.draft`    | ✅    | ✅          | ❌ |
 * | `review` · `approve` · `lock` · `markPaid` | `payroll.approve` | ✅ | ❌ | ❌ |
 * | `reverseLock` | `payroll.approve` **and** `RoleName::ADMIN` | ✅ | ❌ | ❌ |
 *
 * *"Accountant cannot approve/lock"* is spelled by the Accountant not holding `payroll.approve`
 * — their name appears nowhere in the first four rows, exactly as *"the Accountant has no
 * meetings"* is spelled `meetings.use` (decisions 2-13, 2-31, 7-11).
 * `PayrollStateMachineTest` greps all three payroll policies for every role name but ADMIN and
 * expects to find none of them.
 *
 * **`reverseLock` is the one place a role is named**, and it is named deliberately. Part D §14
 * and Part C §4 both say *only ADMIN*, in those words, about this one verb. Today that is
 * already true of `payroll.approve` alone — only ADMIN holds it — and that is precisely why the
 * role is added: the day the client asks for a senior Accountant who may approve payroll, the
 * key moves and *"only an Admin un-freezes a month that has been locked"* must not move with
 * it. This is the `RoleName::ADMIN` administrative override that six existing policies already
 * use on somebody else's record (decision 7-11), and it is an **extra** condition on the key,
 * never a replacement for it.
 *
 * ## Status is NOT checked here, with one exception that lives next door
 *
 * Whether a period is in a state to be approved is `PayrollService`'s question, and it answers
 * it by throwing `PayrollStateException` with a sentence naming the status and the moves that
 * are available — because *"I pressed Approve and nothing happened"* is the bug report a silent
 * no-op produces, and a 403 on a button the person is entitled to press is barely better.
 * **This file answers who, the service answers when.**
 *
 * The exception is item editing, where the status genuinely is part of the authorization rule
 * (*"read-only to the Accountant afterwards"* is a different answer for two roles at the same
 * status) — and that is `PayrollItemPolicy::update()`, one file along.
 */
class PayrollPeriodPolicy extends Policy
{
    /**
     * The Payroll period list — the Accountant's Payroll screen and the Admin's.
     *
     * `payroll.view_others`, because a list of months is a list of everybody's pay. An employee
     * reaches their own line through `PayrollItem`, never through a period list, which is why
     * `payroll.view_own` buys nothing here.
     */
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::PayrollViewOthers);
    }

    /**
     * One period, with everybody's lines on it.
     *
     * A refusal here is a **404**, not a 403: the id of a payroll period is not something an
     * employee is entitled to probe for. The controller slice makes that real the way every
     * other slice in this repo does — it resolves the id through a scoped query, so a period
     * this person may not see is *not found* rather than *forbidden*.
     */
    public function view(User $user, PayrollPeriod $period): bool
    {
        return $this->allows($user, Permission::PayrollViewOthers);
    }

    /**
     * Create the month's draft by hand. The same key as Calculate: drafting and calculating are
     * Part C §1's *"🟡 draft/calculate only"* cell, and they are one capability.
     *
     * `hq:create-payroll-draft` does this on the 1st with no actor at all — a scheduled command
     * is not a person and does not hold permissions. That is not a hole: the command is the
     * schedule, and the schedule is the client's decision, recorded in `routes/console.php`.
     */
    public function create(User $user): bool
    {
        return $this->allows($user, Permission::PayrollDraft);
    }

    /** Press Calculate. Part D §14's Accountant verb. */
    public function calculate(User $user, PayrollPeriod $period): bool
    {
        return $this->allows($user, Permission::PayrollDraft);
    }

    /** Submit the month for review — Part D §14's *"Admin reviews"*. */
    public function review(User $user, PayrollPeriod $period): bool
    {
        return $this->allows($user, Permission::PayrollApprove);
    }

    /** Approve it. The audit event Part C §4 names `payroll approved`. */
    public function approve(User $user, PayrollPeriod $period): bool
    {
        return $this->allows($user, Permission::PayrollApprove);
    }

    /**
     * Close the month.
     *
     * This is the ability with the widest blast radius in the application: locking September
     * stops every income and expense dated in September being created, edited, moved or deleted
     * (Part D §13, `FinanceService::assertPeriodIsOpen()`).
     */
    public function lock(User $user, PayrollPeriod $period): bool
    {
        return $this->allows($user, Permission::PayrollApprove);
    }

    /**
     * **Un-close it. Only an ADMIN, and the service requires a reason.**
     *
     * See the class note for why the role is named here and nowhere else in this file.
     */
    public function reverseLock(User $user, PayrollPeriod $period): bool
    {
        return $this->allows($user, Permission::PayrollApprove) && $user->hasRole(RoleName::ADMIN);
    }

    /** Mark the month paid, which releases the payslips. */
    public function markPaid(User $user, PayrollPeriod $period): bool
    {
        return $this->allows($user, Permission::PayrollApprove);
    }
}
