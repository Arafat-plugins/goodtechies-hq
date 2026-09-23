<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\RecurringTask;
use App\Models\User;
use App\Support\Permission;
use App\Support\RoleName;
use Illuminate\Support\Facades\Gate;

/**
 * Who may set up, edit and run a retainer template.
 *
 * The plan scopes these screens to one surface — Phase 3, "**Screens (Admin):** Recurring task
 * templates under a project" — so this file is that sentence plus the scoping rule the rest of
 * the application already has.
 *
 * ## Why Admin and not Admin/Manager
 *
 * A template is not a task. It is a standing instruction that will create tasks every month for
 * as long as the retainer lasts, on somebody else's plate, whether or not anybody is watching —
 * which is a commercial decision about an engagement, not a scheduling one about this week's
 * work. `TagPolicy` lets a Manager in because a tag is task vocabulary and a Manager owns the
 * tasks; nothing about owning this week's tasks implies owning the agency's retainers. A
 * Manager who wants a repeating task this month creates a task.
 *
 * That also makes the surface guard and the policy say the same thing rather than two things:
 * these routes are behind `surface:admin`, so a Manager is refused there first — but the answer
 * would be the same if they were not, because the refusal is about what they may do and not
 * about which shell they arrived in.
 *
 * ## Why the scope check is `view` on the project and not a new permission key
 *
 * A template belongs to a project: its title says what that client buys every month, and its
 * checklist says how. So managing one is gated on being able to see the project at all, through
 * `ProjectPolicy` — exactly as `TagPolicy` scopes a project tag, and for the same reason. Per
 * AGENTS.md a scoped rule is a key plus a scope check in a policy, never a new key; the key is
 * `tasks.create`, because every run of this template ends in `TaskService::create()` and a
 * standing instruction to create a task must not be settable by somebody who may not create one.
 *
 * ## The Accountant
 *
 * Not mentioned once, and that is the enforcement: they hold no `tasks.create`, so every ability
 * below falls at its first line.
 */
class RecurringTaskPolicy extends Policy
{
    /**
     * Seeing a project's templates at all. The project is the one whose tab is being opened.
     */
    public function viewAny(User $user, ?Project $project = null): bool
    {
        return $this->manages($user) && $this->inScope($user, $project);
    }

    /**
     * One template. A template on a project this user cannot see must read as ABSENT rather than
     * refused, which is the caller's job: `RecurringTask::visibleTo()` scopes the lookup so the
     * row is never found, and this answers the permission half once it is.
     */
    public function view(User $user, RecurringTask $template): bool
    {
        return $this->manages($user) && $this->inScope($user, $template->project);
    }

    public function create(User $user, ?Project $project = null): bool
    {
        return $this->viewAny($user, $project);
    }

    /**
     * Editing the title, the checklist, the rule, the assignee or the active toggle. Same
     * standing as creating one: there is nothing an edit can do that a fresh template could not.
     */
    public function update(User $user, RecurringTask $template): bool
    {
        return $this->view($user, $template);
    }

    /**
     * "Generate now".
     *
     * Its own ability rather than a second reading of `update`, because it is the one control
     * here that writes a row somebody else has to work: pressing it puts a task on an assignee's
     * plate today. It happens to be the same people — a template's author is who the engine
     * borrows anyway — but the question a reader of this file asks about that button is "who may
     * make this fire", and it deserves a line that answers it.
     *
     * It is **not** the check that decides whether the task may be created. `TaskService::create()`
     * asks the gate again, as the actor the engine picked, which is why a forced run cannot
     * create a task nobody was allowed to create.
     */
    public function generate(User $user, RecurringTask $template): bool
    {
        return $this->view($user, $template);
    }

    /**
     * The role and permission half: an Admin holding `tasks.create`.
     */
    private function manages(User $user): bool
    {
        return $this->allows($user, Permission::TasksCreate)
            && $user->hasRole(RoleName::ADMIN);
    }

    /**
     * The project half. A template with no project left is nobody's — a null here is the
     * relation failing to load, not a "global" template, and there is no such thing.
     */
    private function inScope(User $user, ?Project $project): bool
    {
        return $project !== null && Gate::forUser($user)->allows('view', $project);
    }
}
