<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Support\LeaveStatus;
use App\Support\Permission;

/**
 * Who may apply for leave, who may rule on it, and who may move a balance.
 *
 * Part C §1 gives this three cells and this file is those three cells:
 *
 *   | Apply for own leave | ✅ every role, the ACCOUNTANT included |
 *   | Approve leave       | ✅ ADMIN · 🟡 MANAGER own team · ❌ everyone else |
 *   | (balances)          | the approve key plus the same scope — never a new key |
 *
 * Each is the permission key plus a scope check, never a separate key (Part C §1).
 *
 * ## Permission is resolved here, on the server, per record
 *
 * Nothing in Vue decides whether a button is drawn: the payloads carry a `permissions` block
 * that these methods answered, and the endpoint asks again. Decisions 2-28 and 2-31, and the
 * bug they record — a copy of a policy living in a Vue file, where nobody remembers to change
 * it — is exactly what a screen with Approve / Reject / Request correction on it would invite.
 *
 * ## 404 and 403, and which is which
 *
 * A request belonging to somebody this person may not see is **absent**: the controllers
 * re-resolve every id through `LeaveRequest::visibleTo()` before a policy is asked, so it is
 * not found and 404 is what they have rather than what they decide. That is the rule for a
 * RECORD, and it is the whole of "the Accountant can reach nobody else's request".
 *
 * A **403** is about the person and the verb: the Admin leave screens live on the Admin surface
 * and `surface:admin` refuses every other shell before a record is looked up, and `decide()`
 * refuses a holder of no `leave.approve` key who somehow reached one.
 *
 * ## Nobody rules on their own request
 *
 * `decide()` says so and `LeaveService` says so again, and the second one is not redundant: a
 * future command or console call reaches the service and not the policy. It is the same rule
 * decision 4-21 established for approving a time entry — the refusal is about ownership, not
 * about a missing key — and it is testable in both directions because the agency has two
 * Admins.
 */
class LeaveRequestPolicy extends Policy
{
    /**
     * My Leave: balances, the apply form and this person's own history.
     *
     * Every role holds `leave.apply`, and it is still asked — a role that lost it must lose the
     * page rather than keep it because everybody else has it. A person with no employee record
     * has no leave to show and is refused rather than shown an empty page, because there is
     * nothing for an apply form to be about.
     */
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::LeaveApply) && $user->employee !== null;
    }

    /**
     * One request. Own, or one inside the scope a `leave.approve` holder manages.
     *
     * It calls straight into `LeaveRequest::visibleTo()` rather than restating the rule, so a
     * list and a lookup can never disagree (decision 2-37) — the same shape
     * `AttendanceRecordPolicy::manages()` uses.
     */
    public function view(User $user, LeaveRequest $request): bool
    {
        if (! $user->isActive()) {
            return false;
        }

        return LeaveRequest::query()
            ->visibleTo($user)
            ->whereKey($request->getKey())
            ->exists();
    }

    /**
     * Filing one. Always about yourself: there is no endpoint for applying on somebody else's
     * behalf, and an Admin who needs to record a colleague's leave asks them to file it.
     */
    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Answering a correction request by resubmitting.
     *
     * Only the applicant, and only from `correction_requested`. An approved or rejected request
     * is finished; a pending one is already in the queue and editing it under the approver
     * would change what they are reading as they read it. Part D §9 gives the employee exactly
     * one move, and this is it.
     */
    public function resubmit(User $user, LeaveRequest $request): bool
    {
        return $this->isOwn($user, $request)
            && $this->allows($user, Permission::LeaveApply)
            && $request->status === LeaveStatus::CorrectionRequested;
    }

    /**
     * The queue: is there an Approve / Reject / Request correction screen for this person at
     * all. Whose requests are on it is `LeaveRequest::visibleTo()`'s answer.
     */
    public function review(User $user): bool
    {
        return $this->allows($user, Permission::LeaveApprove);
    }

    /**
     * Ruling on one request — all three verbs, because they are one decision with three
     * answers and a person who may say yes may say no.
     *
     * Three conditions. The key, the scope, and **not your own**.
     */
    public function decide(User $user, LeaveRequest $request): bool
    {
        return $this->allows($user, Permission::LeaveApprove)
            && ! $this->isOwn($user, $request)
            && $this->view($user, $request)
            && $request->status->isOpen();
    }

    /**
     * Reading and setting other people's balances.
     *
     * The `leave.approve` key plus the scope, never a new key: deciding how many days somebody
     * has and deciding whether they may take them are the same authority, and Part C §1 lists
     * one cell for it.
     */
    public function manageBalances(User $user, ?Employee $subject = null): bool
    {
        if (! $this->allows($user, Permission::LeaveApprove)) {
            return false;
        }

        if ($subject === null) {
            return true;
        }

        return Employee::query()
            ->leaveVisibleTo($user)
            ->whereKey($subject->getKey())
            ->exists();
    }

    private function isOwn(User $user, LeaveRequest $request): bool
    {
        return $user->employee !== null
            && (int) $user->employee->getKey() === (int) $request->employee_id;
    }
}
