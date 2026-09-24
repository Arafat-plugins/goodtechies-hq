<?php

namespace App\Events;

use App\Models\LeaveRequest;
use App\Models\User;

/**
 * An approver sent a leave request back with a note (Phase 5, master prompt Part D §9's third
 * verb: Approve / Reject / **Request Correction** with note).
 *
 * ## Why this event exists although the plan's list does not name it
 *
 * Phase 5's Backend paragraph names `LeaveRequested` and `LeaveApproved/Rejected`. The same
 * phase's Screens paragraph gives the Admin three buttons, and its Tests line asks for
 * "apply/approve/reject/**correction**". A correction request that nobody is told about is a
 * question asked into the void: the employee's request simply stops moving, and the only way
 * they find out is by opening a page they have no reason to open. The two halves of the plan
 * disagree and the acceptance behaviour wins, exactly as it did for `TaskCompleted`'s
 * recipients (decision 2-45).
 *
 * It is not a rejection and must not read as one. The request keeps its place — the employee
 * can amend it and send it back, which is what `correction_requested → pending` is for — and
 * nothing has been spent or written.
 */
class LeaveCorrectionRequested
{
    public function __construct(
        public readonly LeaveRequest $request,
        public readonly User $actor,
    ) {}
}
