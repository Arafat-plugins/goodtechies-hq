<?php

namespace App\Services;

use App\Exceptions\SelfModificationException;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Support\AuditEvent;
use App\Support\Permission;
use App\Support\RoleName;
use App\Support\UserStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Role changes and deactivation. Nobody may change their own role or deactivate themselves.
 */
class EmployeeAdministrationService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * Tracking mode is deliberately left as it is; it is changed separately.
     *
     * @throws SelfModificationException
     * @throws AuthorizationException
     */
    public function changeRole(User $actor, Employee $employee, RoleName $role): void
    {
        $this->guard($actor, $employee, 'change the role of');

        $old = $employee->role?->name;

        if ($old === $role) {
            return;
        }

        DB::transaction(function () use ($actor, $employee, $role, $old): void {
            $employee->role()->associate(Role::where('name', $role->value)->firstOrFail());
            $employee->save();

            $this->audit->record(
                AuditEvent::RoleChanged,
                $employee,
                ['role' => $old?->value],
                ['role' => $role->value],
                $actor,
            );

            $this->activity->record(
                $employee,
                sprintf('Role changed from %s to %s', $old->value ?? 'none', $role->value),
                $actor,
            );
        });
    }

    /**
     * Deactivates the employee and their login, and ends their sessions. The user row is kept.
     *
     * @throws SelfModificationException
     * @throws AuthorizationException
     */
    public function deactivate(User $actor, Employee $employee): void
    {
        $this->guard($actor, $employee, 'deactivate');

        DB::transaction(function () use ($actor, $employee): void {
            $user = $employee->user;
            $old = ['status' => $employee->status?->value, 'user_status' => $user->status?->value];

            $employee->update(['status' => UserStatus::Inactive]);
            $user->update(['status' => UserStatus::Inactive]);

            DB::table('sessions')->where('user_id', $user->id)->delete();

            $this->audit->record(
                AuditEvent::EmployeeDeactivated,
                $employee,
                $old,
                ['status' => UserStatus::Inactive->value, 'user_status' => UserStatus::Inactive->value],
                $actor,
            );

            $this->activity->record($employee, 'Employee deactivated', $actor);
        });
    }

    private function guard(User $actor, Employee $employee, string $action): void
    {
        if ($employee->user_id === $actor->id) {
            throw SelfModificationException::forAction($action);
        }

        if (! $actor->isActive() || ! $actor->hasPermission(Permission::RolesManage)) {
            throw new AuthorizationException('You are not allowed to manage employees.');
        }
    }
}
