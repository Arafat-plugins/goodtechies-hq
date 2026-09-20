<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;
use App\Support\Permission;

class EmployeePolicy extends Policy
{
    public function view(User $user, Employee $employee): bool
    {
        return ($user->isActive() && $this->isSelf($user, $employee))
            || $this->allows($user, Permission::RolesManage);
    }

    public function changeRole(User $user, Employee $employee): bool
    {
        return ! $this->isSelf($user, $employee) && $this->allows($user, Permission::RolesManage);
    }

    public function deactivate(User $user, Employee $employee): bool
    {
        return ! $this->isSelf($user, $employee) && $this->allows($user, Permission::RolesManage);
    }

    private function isSelf(User $user, Employee $employee): bool
    {
        return $employee->user_id === $user->id;
    }
}
