<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;
use App\Services\TaskReviewers;
use App\Support\Permission;
use App\Support\RoleName;
use App\Support\TaskStatus;

/**
 * Who may see and move a task (master prompt Part C §1).
 *
 * Deny by default, like every policy here: there is no before() bypass and an Admin passes
 * the same checks as everyone else, just with a wider answer.
 *
 * The Accountant is not mentioned once in this file. That is the enforcement: they hold no
 * tasks.* permission, so every ability below falls at its first line.
 */
class TaskPolicy extends Policy
{
    public function __construct(private readonly TaskReviewers $reviewers) {}

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::TasksView);
    }

    /**
     * Admins and Managers see every task. An employee sees only the tasks ASSIGNED to them —
     * being a member of the project is not enough, which is the difference from ProjectPolicy.
     *
     * A task they are not assigned to must read as absent, not refused: the controllers go
     * through Task::visibleTo() and firstOrFail(), so an id they may not see answers 404.
     */
    public function view(User $user, Task $task): bool
    {
        if (! $this->allows($user, Permission::TasksView)) {
            return false;
        }

        if ($user->hasRole(RoleName::ADMIN, RoleName::MANAGER)) {
            return true;
        }

        return $user->hasRole(RoleName::EMPLOYEE, RoleName::REMOTE_EMPLOYEE)
            && $this->isAssigned($user, $task);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::TasksCreate);
    }

    /**
     * An archived task is read-only for everyone; unarchive it first. Both assignees of a
     * two-person task may edit it — the primary's privilege is completion, not editing.
     *
     * Slice 2 owns the mutation endpoints; this is here now because TaskResource reports it.
     */
    public function update(User $user, Task $task): bool
    {
        if ($task->isArchived() || ! $this->view($user, $task)) {
            return false;
        }

        return $user->hasRole(RoleName::ADMIN, RoleName::MANAGER)
            || $this->isAssigned($user, $task);
    }

    /**
     * Who the task is assigned to is a management decision, not work on the task: the two
     * assignees may edit it, they may not quietly add a third person or make themselves
     * primary. Every change here writes task.assigned or task.reassigned to audit_logs.
     */
    public function assign(User $user, Task $task): bool
    {
        return $this->allows($user, Permission::TasksCreate)
            && ! $task->isArchived()
            && $this->view($user, $task)
            && $user->hasRole(RoleName::ADMIN, RoleName::MANAGER);
    }

    /**
     * Handing the task over: moving "primary" to the other assignee, so that their work
     * summary is the one completion accepts.
     *
     * Admins and Managers may do it, and so may the current primary — handing over your own
     * work when you go on leave is the case the rule exists for, and requiring a manager for
     * it would leave the task uncompletable until Monday.
     */
    public function handOff(User $user, Task $task): bool
    {
        if ($task->isArchived() || ! $this->view($user, $task)) {
            return false;
        }

        if ($this->allows($user, Permission::TasksCreate) && $user->hasRole(RoleName::ADMIN, RoleName::MANAGER)) {
            return true;
        }

        $primary = $task->primary();

        return $primary !== null && $user->employee?->id === $primary->id;
    }

    /**
     * Delete is Admin/Manager, audit-logged, and soft, so the history survives.
     */
    public function delete(User $user, Task $task): bool
    {
        return $this->allows($user, Permission::TasksDelete)
            && $user->hasRole(RoleName::ADMIN, RoleName::MANAGER);
    }

    public function archive(User $user, Task $task): bool
    {
        return $this->allows($user, Permission::TasksCreate)
            && $user->hasRole(RoleName::ADMIN, RoleName::MANAGER);
    }

    /** Only an Admin brings an archived task back. */
    public function unarchive(User $user, Task $task): bool
    {
        return $this->allows($user, Permission::TasksCreate)
            && $user->hasRole(RoleName::ADMIN);
    }

    /**
     * The reviewer is the project's PM; if the project has no PM, every Admin is.
     *
     * This is the project-scoped half of the rule whose role half lives in
     * TaskStatus::mayRoleTransition(). A Manager who is a Manager somewhere else is not this
     * project's reviewer, which a role check alone could never express.
     *
     * The resolution itself is TaskReviewers', not this policy's: slice 5's "notify the
     * reviewer" needs the same answer as a set of people, and two copies of the rule is one
     * copy that can be wrong.
     */
    public function review(User $user, Task $task): bool
    {
        return $this->allows($user, Permission::TasksView)
            && $this->reviewers->isReviewer($user, $task);
    }

    /**
     * May this user move this task from where it is to $to?
     *
     * Both halves must pass: the transition must be legal for the user's role
     * (TaskStatus::mayRoleTransition) AND the user must have the standing on this
     * particular task. Neither is sufficient alone, and the map is the enum's business so
     * that the drag endpoints and the edit form cannot disagree about it.
     */
    public function transition(User $user, Task $task, TaskStatus $to): bool
    {
        $from = $task->status;

        if ($from === null || $task->isArchived() || ! $this->view($user, $task)) {
            return false;
        }

        $role = $user->employee?->role?->name;

        if ($role === null || ! $from->mayRoleTransition($role, $to)) {
            return false;
        }

        // A review verdict additionally needs to come from THIS project's reviewer.
        if ($from === TaskStatus::InReview
            && in_array($to, [TaskStatus::Completed, TaskStatus::ChangesRequested], true)) {
            return $this->review($user, $task);
        }

        // Ordinary work on the board is the assignees' and the managers'.
        if ($user->hasRole(RoleName::ADMIN, RoleName::MANAGER)) {
            return true;
        }

        return $this->isAssigned($user, $task);
    }

    /**
     * The user is one of the task's assignees.
     */
    private function isAssigned(User $user, Task $task): bool
    {
        $employee = $user->employee;

        if ($employee === null || $task->getKey() === null) {
            return false;
        }

        // Read the loaded relation when the caller eager-loaded it, so a list of tasks does
        // not turn into a query per row just to fill in `permissions`.
        if ($task->relationLoaded('assignees')) {
            return $task->assignees->contains('id', $employee->id);
        }

        return $task->assignees()->where('employees.id', $employee->id)->exists();
    }
}
