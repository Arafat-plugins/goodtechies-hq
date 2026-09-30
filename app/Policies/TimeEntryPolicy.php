<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Support\Permission;
use App\Support\RoleName;
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
     * May this person run a TASK timer — the ▶ on a board card and in the drawer (flow F3,
     * decision 12-73)? The gate on `/task-timer/*` and the general half of `trackTask()`.
     *
     * **No new permission key.** Working a task is already `tasks.view` plus an assignment, and
     * the timer is how that work is measured, so the rule is stated in keys that exist:
     *
     *   - `tasks.view` — the Accountant holds none and is refused outright (403);
     *   - an employee record, because an entry belongs to an employee;
     *   - the person's OWN tracking mode decides which clock their day is on, exactly as it does
     *     for `track()` and for the office clock: `remote_timer` still needs `timer.use` (that
     *     timer IS their day, and `track()` is unchanged), `office_attendance` needs
     *     `attendance.view_own`, the key the clock-in itself asks for. `none` gets nothing.
     *
     * Nothing here compares a role name, for the reason this class's docblock gives.
     */
    public function trackTasks(User $user): bool
    {
        if (! $this->allows($user, Permission::TasksView)) {
            return false;
        }

        return match ($user->employee?->tracking_mode) {
            TrackingMode::RemoteTimer => $this->allows($user, Permission::TimerUse),
            TrackingMode::OfficeAttendance => $this->allows($user, Permission::AttendanceViewOwn),
            default => false,
        };
    }

    /**
     * ▶ on THIS task: `trackTasks()`, the task still live, and EITHER the person is assigned to
     * it OR they are an Admin/Manager who may see it (`TaskPolicy::view`) — brief 021.
     *
     * An employee sees only assigned tasks (`Task::visibleTo`), so for them "every card I can
     * see" and "every card I am on" are the same set and their rule is unchanged: a task they
     * are not on is 404 at the controller, never reaches here. An Admin or Manager sees every
     * task and may time any of them — the original request was that "admin will have this
     * system to track his own work" on every card. They time it under their OWN employee
     * record; nobody is assigned by this. The role check is the one `TaskPolicy::view` makes.
     *
     * Reads the loaded `assignees` relation when there is one, so a board of two hundred cards
     * asks this per card without a query per card.
     */
    public function trackTask(User $user, Task $task): bool
    {
        if (! $this->trackTasks($user) || $task->isArchived()) {
            return false;
        }

        $employeeId = $user->employee?->getKey();

        if ($employeeId === null) {
            return false;
        }

        if ($user->hasRole(RoleName::ADMIN, RoleName::MANAGER) && $user->can('view', $task)) {
            return true;
        }

        if ($task->relationLoaded('assignees')) {
            return $task->assignees->contains('id', $employeeId);
        }

        return $task->assignees()->where('employees.id', $employeeId)->exists();
    }

    /**
     * May this person see, live, who is running a timer on a card and for how long?
     *
     * `attendance.manage_others` — the key that already shows them every entry in the table
     * (`TimeEntry::visibleTo()`) and Admin → Workforce → Time. Seeing a running timer is seeing
     * an open row of that same table, so it is not a new privilege and gets no new key. Everyone
     * else sees their own timer and nobody else's: `running_timers` is ABSENT from their payload.
     */
    public function watchLive(User $user): bool
    {
        return $this->allows($user, Permission::AttendanceManageOthers);
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
        // A task-timer breakdown row is not hours, so there is no figure on it to correct: its
        // day is the attendance record, and that has its own correction (decision 12-73).
        if (! $entry->isStopped() || ! $entry->countsTowardHours()) {
            return false;
        }

        if ($this->allows($user, Permission::AttendanceManageOthers)) {
            return true;
        }

        return $this->track($user) && $this->owns($user, $entry);
    }

    /**
     * Ruling on an entry: Admin → Workforce → Time's Approve and Reject.
     *
     * **One ability for both verbs**, because they are one decision made two ways. A person who
     * may sign hours off is exactly the person who may refuse them; splitting it would create a
     * role that can approve but not turn anything down, which is not a job anybody has.
     *
     * Three things have to hold:
     *
     *   - `attendance.manage_others` — the same key that lets somebody correct another person's
     *     attendance record, which is the same act on the other table. Approving hours is not a
     *     new privilege and does not get a new key; a scoped rule is the key plus a scope check,
     *     never a second key.
     *   - The entry is **finished**. A session still going has no agreed length to rule on, and
     *     `TimerService::assertDecidable()` refuses it too — the same pairing `update()` has.
     *   - It is **not their own**. Nobody signs off their own claim, however senior. Today no
     *     seeded person holds `attendance.manage_others` and a timer at once, so this branch is
     *     unreachable through the UI — which is precisely why it is coded rather than argued.
     *     The day somebody's tracking mode is changed is not the day to discover the rule was
     *     only true by accident.
     *
     * The refusal is a **403**: being unable to rule on time entries at all is a fact about the
     * requester. An entry they may not SEE is a different question and answers 404 — the
     * controller resolves through `TimeEntry::visibleTo()` first (Part C §1).
     */
    public function approve(User $user, TimeEntry $entry): bool
    {
        // Nothing to rule on: a breakdown row is nobody's hours (decision 12-73).
        if (! $entry->isStopped() || ! $entry->countsTowardHours()) {
            return false;
        }

        if ($this->owns($user, $entry)) {
            return false;
        }

        return $this->allows($user, Permission::AttendanceManageOthers);
    }

    /**
     * The Admin → Workforce → Time screen itself: the queue, and hours by employee, project and
     * task.
     *
     * Not `viewAny` — that one is the employee's own Time page and is deliberately narrower
     * (see its docblock). This is the other screen the same phase builds, and it asks the
     * question that screen cannot: whose hours am I responsible for.
     */
    public function review(User $user): bool
    {
        return $this->allows($user, Permission::AttendanceManageOthers);
    }

    private function owns(User $user, TimeEntry $entry): bool
    {
        $employeeId = $user->employee?->getKey();

        return $employeeId !== null && (int) $entry->employee_id === (int) $employeeId;
    }
}
