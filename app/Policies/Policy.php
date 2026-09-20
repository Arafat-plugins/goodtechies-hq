<?php

namespace App\Policies;

use App\Models\User;
use App\Support\Permission;

/**
 * Base for every policy. Deny by default: there is no before() bypass, not even for Admins.
 */
abstract class Policy
{
    protected function allows(User $user, Permission $permission): bool
    {
        return $user->isActive() && $user->hasPermission($permission);
    }
}
