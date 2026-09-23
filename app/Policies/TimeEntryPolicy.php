<?php

namespace App\Policies;

use App\Models\TimeEntry;
use App\Models\User;
use App\Support\Permission;
use App\Support\TrackingMode;

/**
 * Who may use the timer, and whose time they may touch (master prompt Part C §1, Part D §7).
 *
 * ## Two facts, both required
 *
 * `timer.use` is the ROLE's grant — the permission key the matrix gives to REMOTE_EMPLOYEE and
 * to nobody else. `tracking_mode = remote_timer` is the EMPLOYEE's own fact, and the plan is
 * explicit that it "is a per-employee field, not a role rule". Both have to hold, and the second
 * is the one the screens ask about, because it is the one that answers the real question: does
 * this person's day get recorded by a timer or by a clock-in?
 *
 * Nothing here compares a role NAME. A role name in an authorisation check is how "the timer is
 * for remote employees" quietly becomes "the timer is for people called REMOTE_EMPLOYEE", and
 * the two stop meaning the same thing the first time somebody's tracking mode is changed.
 *
 * ## 403 and 404
 *
 * Being unable to use the timer at all is a fact about the requester, not about a record, so it
 * is a **403** — Part C §1's "a whole route or surface the role may not use". An office employee
 * gets that from every timer endpoint, and no timer UI to click in the first place.
 *
 * Somebody else's entry is a different question and gets a different answer: the controllers
 * resolve through `TimeEntry::visibleTo()` and `firstOrFail()`, so another employee's entry is
 * **404**. A 403 there would confirm that the record exists, which is exactly what Part C
 * forbids.
 */
class TimeEntryPolicy extends Policy
{
    /**
     * May this person run a timer at all? The gate on every start / pause / resume / stop /
     * heartbeat / replay endpoint, and on the Time page.
     */
    public function track(User $user): bool
    {
        return $this->allows($user, Permission::TimerUse)
            && $user->employee?->tracking_mode === TrackingMode::RemoteTimer;
    }

    /**
     * The Time page on the Employee surface: *my* entries, by day.
     *
     * Deliberately the same answer as `track` and not a word wider. A Manager holds
     * `attendance.manage_others` and still gets a 403 here, because this page is a timer
     * employee's own record and rendering it for somebody with no timer would be a screen of
     * controls they cannot use. Admin → Workforce → Time is a different screen on a different
     * surface, and it is a later slice's to build.
     */
    public function viewAny(User $user): bool
    {
        return $this->track($user);
    }

    /**
     * Adding a stretch of time by hand. The same gate as running the timer: a manual entry is
     * the timer's fallback, not a separate privilege.
     */
    public function create(User $user): bool
    {
        return $this->track($user);
    }

    /**
     * Correcting an entry — "I left at one and the timer ran on".
     *
     * The employee may correct their own, with a reason. Whoever manages other people's
     * attendance may correct anybody's, with a reason, and it is written to `audit_logs` either
     * way.
     *
     * A session that is still going is not corrected, it is stopped: there is no agreed ending
     * to move yet, so the endpoint would be editing a number that is still changing.
     */
    public function update(User $user, TimeEntry $entry): bool
    {
        if (! $entry->isStopped()) {
            return false;
        }

        if ($this->allows($user, Permission::AttendanceManageOthers)) {
            return true;
        }

        return $this->track($user) && $this->owns($user, $entry);
    }

    private function owns(User $user, TimeEntry $entry): bool
    {
        $employeeId = $user->employee?->getKey();

        return $employeeId !== null && (int) $entry->employee_id === (int) $employeeId;
    }
}
