<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;
use App\Services\AttendanceService;
use App\Support\Permission;

/**
 * Who may read and correct whose attendance.
 *
 * Part C §1 gives this two cells and this file is those two cells:
 *
 *   | View/manage own attendance | ✅ every role (🟡 optional for the Accountant) |
 *   | Manage others' attendance  | ✅ ADMIN · 🟡 MANAGER own team · ❌ everyone else |
 *
 * Both are the permission key plus a scope check, never a new key.
 *
 * ## The subject is an Employee, although the policy is the record's
 *
 * The file is named for `AttendanceRecord` so that Laravel's policy discovery binds it without
 * a line in a provider, and every ability is then called class-first —
 * `Gate::authorize('update', [AttendanceRecord::class, $employee])` — the shape
 * `RecurringTaskPolicy::viewAny()` already established here.
 *
 * Every ability takes the employee whose day it is, because most days have no record:
 * an Off Day and a Remote day are derived and have no `attendance_records` row to authorise
 * against. Asking "may you see this person's attendance" answers both cases with one rule, and
 * it is the same rule `Employee::attendanceVisibleTo()` states for a set — this file calls
 * straight into that scope rather than restating it, so a list and a lookup can never disagree
 * (decision 2-37).
 *
 * ## Why `view` is used to produce a 404 and not a 403
 *
 * Part C: a record the requester may not see is ABSENT. The controllers do not call `view` and
 * then translate a false into a 404 — they resolve the subject through the scope, so somebody
 * else's attendance is simply not found. `viewFor()` is here for the payload's `permissions`
 * block and for the few places that hold an employee already.
 *
 * ## `clock` is a tracking-mode question
 *
 * `TrackingMode::OfficeAttendance` is the fact that decides who gets a clock-in widget — not
 * the role name (decisions 2-28, 2-31). Both Admins and Yaseen clock in; Tapu's work is the
 * timer's and the Accountant's is tracked by nothing, and neither of them is named here.
 */
class AttendanceRecordPolicy extends Policy
{
    public function __construct(private readonly AttendanceService $attendance) {}

    /**
     * The roster — somebody else's attendance, as a list. `Employee::attendanceVisibleTo()`
     * decides whose; this decides whether there is a screen at all.
     */
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::AttendanceManageOthers);
    }

    /**
     * One person's attendance page.
     */
    public function viewFor(User $user, Employee $subject): bool
    {
        return $this->isSelf($user, $subject)
            ? $this->allows($user, Permission::AttendanceViewOwn)
            : $this->manages($user, $subject);
    }

    /**
     * Correcting one of somebody's days, with a reason.
     *
     * Never includes yourself by the own-attendance key: `attendance.view_own` is *view and
     * manage own* in Part C's wording, but what it manages is a clock-in — the record of a day
     * as it happened. Rewriting a past day is `attendance.manage_others`, and an Admin editing
     * their OWN record holds that key and so may, which is correct and is why the audit row
     * carries the actor.
     *
     * ## Only a day the office clock owns
     *
     * The subject must be on `office_attendance`. That is what makes **"Tapu is never Absent"**
     * structural instead of a matter of discipline: the 23:55 sweep skips him, Remote is
     * derived for him — and now there is no hand that can write him an `attendance_records`
     * row either, because the one endpoint that could is refused here. A remote employee's day
     * is the timer's; an Admin who needs to say something about it says it there.
     *
     * It is the same `clocks()` question `clock()` asks below, for the same reason: what
     * decides is `TrackingMode`, never a role name (decisions 2-28, 2-31).
     */
    public function update(User $user, Employee $subject): bool
    {
        return $this->manages($user, $subject) && $this->attendance->clocks($subject);
    }

    /**
     * Pressing Clock in / Clock out. Always about yourself: there is no endpoint for clocking
     * somebody else in, and an Admin who needs to record a colleague's day edits it, with a
     * reason, in the log.
     */
    public function clock(User $user, Employee $subject): bool
    {
        return $this->isSelf($user, $subject)
            && $this->allows($user, Permission::AttendanceViewOwn)
            && $this->attendance->clocks($subject);
    }

    /**
     * The manage-others half, scoped. An Admin manages everybody; anybody else holding the key
     * manages the people who report to them (Part C's 🟡 own team) — which in MVP is the
     * dormant MANAGER role and nobody else, by seed rather than by this file naming them.
     */
    private function manages(User $user, Employee $subject): bool
    {
        if (! $this->allows($user, Permission::AttendanceManageOthers)) {
            return false;
        }

        return Employee::query()
            ->attendanceVisibleTo($user)
            ->whereKey($subject->getKey())
            ->exists();
    }

    private function isSelf(User $user, Employee $subject): bool
    {
        return $user->employee !== null
            && (int) $user->employee->getKey() === (int) $subject->getKey();
    }
}
