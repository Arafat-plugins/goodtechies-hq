<?php

namespace App\Events;

use App\Models\LeaveRequest;
use App\Models\User;

/**
 * A leave request was approved (Phase 5, master prompt Part D §9).
 *
 * Fired AFTER the side effects, inside the same transaction as them: the balance is already
 * decremented, the attendance rows are already written and `unpaid_days` is already stored by
 * the time anything hears about this. A listener that read the request would otherwise be
 * reading a half-applied approval.
 *
 * It carries what the approval actually did to the calendar, because the person being told
 * wants to know which of their days are now Leave, and because a notification that says only
 * "approved" makes them open the request to find out.
 */
class LeaveApproved
{
    public function __construct(
        public readonly LeaveRequest $request,
        public readonly User $actor,
        /** How many `attendance_records` rows the approval wrote. Zero is a real answer — see LeaveService::approve(). */
        public readonly int $attendanceDaysWritten = 0,
    ) {}
}
