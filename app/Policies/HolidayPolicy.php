<?php

namespace App\Policies;

use App\Models\Holiday;
use App\Models\User;
use App\Support\Permission;

/**
 * Who may read and edit the company holiday calendar.
 *
 * ## Reading is everybody's; editing is `settings.manage`
 *
 * A holiday is the one record in this application that is **the same fact for every person in
 * the company**. There is no employee on the row, no project, no scope — so there is no such
 * thing as a holiday somebody may not see, and `viewAny()` asks only that the account is
 * active. Both dashboards' "Upcoming holidays" cards read it, on every surface.
 *
 * Editing is `settings.manage`, and the choice is worth stating because two other keys were
 * candidates:
 *
 *   - **`leave.approve`** is the Workforce → Leave area's key and the screen lives under that
 *     menu — but its MANAGER cell is *🟡 own team* (Part C §1), and a company-wide calendar has
 *     no team. Using it would have silently handed a team manager the power to shut the agency
 *     for a day, which is a widening the 🟡 was never about.
 *   - **`attendance.manage_others`** has the same shape and the same problem.
 *
 * `settings.manage` is ✅ ADMIN, ❌ everybody else — exactly the plan's "Admin edits" — and it
 * says the true thing about what this list is: an agency-wide configuration that decides what a
 * day is called for everybody. That is also why `HolidayService` audits a change as
 * `configuration.changed` rather than inventing a new audit event, and why no new permission
 * key was added (Part C: the 🟡 cells are a key plus a scope check, never a new key).
 *
 * ## There is no 404 here, and that is not an omission
 *
 * Part C's absence rule — "a record they may not see is 404" — has nothing to bite on: every
 * holiday is visible to every signed-in person, so no row can be absent for one reader and
 * present for another. Every refusal on this screen is therefore **403**: `surface:admin` for
 * the wrong shell and `can:settings.manage` for the wrong role. The only 404 the routes can
 * produce is an id that does not exist, which is route-model binding and is asserted directly
 * in tests/Feature/Workforce/HolidayEndpointsTest.php rather than through the matrix, where
 * every role that could show it is refused by the surface first (the shape decision 3-8 set).
 */
class HolidayPolicy extends Policy
{
    /**
     * The calendar, as a list. Everybody in the company — a holiday is not private to anyone.
     *
     * No permission key, deliberately. Every key in Part C §1 is about a *scope* — whose
     * clients, whose tasks, whose attendance — and a holiday has no whose.
     */
    public function viewAny(User $user): bool
    {
        return $user->isActive();
    }

    public function view(User $user, Holiday $holiday): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->manages($user);
    }

    public function update(User $user, Holiday $holiday): bool
    {
        return $this->manages($user);
    }

    public function delete(User $user, Holiday $holiday): bool
    {
        return $this->manages($user);
    }

    /**
     * May this person edit the calendar at all?
     *
     * Public because the controller asks it for the screen as a whole — "is there an Add
     * button" — while `create()`/`update()`/`delete()` answer for an act. One rule, two
     * questions, and no second copy of the key anywhere (decisions 2-28, 2-31: never derived
     * in Vue from a role).
     */
    public function manages(User $user): bool
    {
        return $this->allows($user, Permission::SettingsManage);
    }
}
