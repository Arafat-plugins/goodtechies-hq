<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;
use App\Support\Permission;

/**
 * Who may read and change a work schedule.
 *
 * A schedule is not settings and it is not the person's own preference: it is the input to
 * `AttendanceService::isWorkingDay()` and `isLate()`, so whoever can change it decides which
 * of somebody's days are Off Days and which of their arrivals are Late — from today until it
 * is changed again. That is the same standing as correcting an attendance record, so it takes
 * the same key, `attendance.manage_others`, plus the same scope check.
 *
 * It is deliberately **not** `settings.manage`. `settings` is one global key/value list for the
 * whole agency; a schedule belongs to one person, and a Manager who may correct their own
 * team's attendance (Part C's 🟡 cell) should be able to say which days that team works
 * without being handed the application's settings.
 *
 * Nobody may edit their own schedule through this policy either, and there is no line here
 * excluding them: an Admin holds `attendance.manage_others` over everybody, themselves
 * included, and a Manager's scope contains themselves. The protection that matters is that the
 * change is audit-logged with old and new values, not that a person cannot make it.
 */
class SchedulePolicy extends Policy
{
    /**
     * The Work Schedule screen exists for this person at all.
     */
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::AttendanceManageOthers);
    }

    /**
     * Saving one employee's schedule. Scoped through `Employee::attendanceVisibleTo()` — the
     * one statement of who a person's attendance belongs to, so an employee outside the scope
     * is not found rather than refused, and this ability never has to know the word "Admin".
     */
    public function update(User $user, Employee $subject): bool
    {
        return $this->viewAny($user)
            && Employee::query()->attendanceVisibleTo($user)->whereKey($subject->getKey())->exists();
    }
}
