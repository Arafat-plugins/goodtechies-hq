<?php

namespace App\Events;

use App\Models\LeaveRequest;
use App\Models\User;

/**
 * A leave request was turned down (Phase 5, master prompt Part D §9).
 *
 * Nothing is spent and nothing is written to the calendar: a refusal leaves the request, its
 * dates and the employee's own words exactly where they were and adds the decision beside them
 * — the same shape decision 4-18 chose for a refused time entry, and for the same reason. The
 * person who asked has to be able to read what happened to their request.
 *
 * The reason is required by the Form Request and is on `$request->decision_note`.
 */
class LeaveRejected
{
    public function __construct(
        public readonly LeaveRequest $request,
        public readonly User $actor,
    ) {}
}
