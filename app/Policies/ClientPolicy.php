<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\User;
use App\Support\Permission;
use App\Support\RoleName;

/**
 * Client identity and contact people are commercial data (master prompt Part C §1): only
 * roles holding clients.view_full ever see them, and a Manager only for a client whose work
 * they are actually on.
 */
class ClientPolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::ClientsViewFull);
    }

    public function view(User $user, Client $client): bool
    {
        if (! $this->allows($user, Permission::ClientsViewFull)) {
            return false;
        }

        return ! $user->hasRole(RoleName::MANAGER) || $this->hasAssignedProject($user, $client);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::ClientsEdit);
    }

    public function update(User $user, Client $client): bool
    {
        return $this->allows($user, Permission::ClientsEdit);
    }

    /**
     * Clients are never really deleted: ClientService::deactivate sets the status instead.
     */
    public function delete(User $user, Client $client): bool
    {
        return $this->allows($user, Permission::ClientsEdit);
    }

    private function hasAssignedProject(User $user, Client $client): bool
    {
        $employee = $user->employee;

        if ($employee === null || $client->getKey() === null) {
            return false;
        }

        return $client->projects()->forEmployee($employee)->exists();
    }
}
