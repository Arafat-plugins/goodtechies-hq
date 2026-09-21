<?php

namespace App\Policies;

use App\Models\Permission as PermissionModel;
use App\Models\Project;
use App\Models\User;
use App\Models\UserProjectPermission;
use App\Support\Permission;
use App\Support\RoleName;

/**
 * Who may see and change a project (master prompt Part C §1).
 *
 * Three separate questions, deliberately kept apart, because ProjectResource answers them
 * field by field: may you see the project at all, may you see who the client is, may you see
 * the money.
 */
class ProjectPolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::ProjectsView);
    }

    /**
     * Admins and Managers see every project; employees only the ones they work on.
     * An archived project is still readable.
     */
    public function view(User $user, Project $project): bool
    {
        if (! $this->allows($user, Permission::ProjectsView)) {
            return false;
        }

        if ($user->hasRole(RoleName::ADMIN, RoleName::MANAGER)) {
            return true;
        }

        return $user->hasRole(RoleName::EMPLOYEE, RoleName::REMOTE_EMPLOYEE)
            && $this->isAssigned($user, $project);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::ProjectsEdit);
    }

    /**
     * An archived project is read-only for everyone; unarchive it first.
     */
    public function update(User $user, Project $project): bool
    {
        if ($project->isArchived() || ! $this->allows($user, Permission::ProjectsEdit)) {
            return false;
        }

        return $user->hasRole(RoleName::ADMIN)
            || ($user->hasRole(RoleName::MANAGER) && $this->isAssigned($user, $project));
    }

    public function archive(User $user, Project $project): bool
    {
        return $this->allows($user, Permission::ProjectsEdit) && $user->hasRole(RoleName::ADMIN);
    }

    public function unarchive(User $user, Project $project): bool
    {
        return $this->allows($user, Permission::ProjectsEdit) && $user->hasRole(RoleName::ADMIN);
    }

    public function cancel(User $user, Project $project): bool
    {
        return $this->allows($user, Permission::ProjectsEdit) && $user->hasRole(RoleName::ADMIN);
    }

    public function manageMembers(User $user, Project $project): bool
    {
        return $this->update($user, $project);
    }

    /**
     * May see the commercial side of the project: which client it belongs to and the
     * internal notes written about them.
     */
    public function viewCommercial(User $user, Project $project): bool
    {
        if (! $this->allows($user, Permission::ClientsViewFull)) {
            return false;
        }

        return ! $user->hasRole(RoleName::MANAGER) || $this->isAssigned($user, $project);
    }

    /**
     * May see the money. The global key is an Admin one; everybody else needs a per-project
     * grant row. The Accountant's finance-only view arrives in Phase 8 with its own resource.
     */
    public function viewFinance(User $user, Project $project): bool
    {
        if (! $user->isActive()) {
            return false;
        }

        if ($user->hasPermission(Permission::ProjectsViewFinance) && $user->hasRole(RoleName::ADMIN)) {
            return true;
        }

        return $this->hasProjectGrant($user, $project, Permission::ProjectsViewFinance);
    }

    /**
     * Writing money needs both halves: seeing it and being allowed to change the project.
     */
    public function updateFinance(User $user, Project $project): bool
    {
        return $this->viewFinance($user, $project) && $this->update($user, $project);
    }

    /**
     * The user is the project's PM or one of its members.
     */
    private function isAssigned(User $user, Project $project): bool
    {
        $employee = $user->employee;

        if ($employee === null) {
            return false;
        }

        return $project->pm_id === $employee->id
            || $project->members()->where('employees.id', $employee->id)->exists();
    }

    private function hasProjectGrant(User $user, Project $project, Permission $permission): bool
    {
        if ($project->getKey() === null) {
            return false;
        }

        return UserProjectPermission::query()
            ->where('user_id', $user->getKey())
            ->where('project_id', $project->getKey())
            ->whereIn('permission_id', PermissionModel::query()
                ->where('key', $permission->value)
                ->select('id'))
            ->exists();
    }
}
