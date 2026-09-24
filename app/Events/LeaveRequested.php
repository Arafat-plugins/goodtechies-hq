<?php

namespace App\Events;

use App\Models\LeaveRequest;
use App\Models\User;

/**
 * Somebody applied for leave, or answered a correction request by resubmitting (Phase 5,
 * master prompt Part D §9).
 *
 * One event for both, on purpose. A resubmission is the same request asking the same question
 * again, and the dedup rule (decision 2-33) is what turns that into one row in the approver's
 * bell with a count of 2 rather than a second row repeating the first — the same shape
 * `TaskSubmittedForReview` has for a resubmitted task, and `NotificationType::summary()` grows
 * the same plural branch for it (decision 2-46).
 *
 * `$actor` is the applicant. For a request filed by somebody on their own behalf — which is
 * every request, because there is no endpoint for applying for leave on another person's
 * behalf — that is the employee's own user.
 */
class LeaveRequested
{
    public function __construct(
        public readonly LeaveRequest $request,
        public readonly User $actor,
        /** True when this is an answer to a correction request rather than a first filing. */
        public readonly bool $resubmitted = false,
    ) {}
}
