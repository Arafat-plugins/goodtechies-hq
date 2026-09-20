<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Support\Permission as PermissionKey;
use App\Support\RoleName;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    /**
     * Role → permission keys (master prompt Part C §1). Full and scoped cells both grant the key;
     * the scope check lives in the Policies.
     *
     * @var array<string, list<string>>
     */
    public const MATRIX = [
        RoleName::ADMIN->value => [
            PermissionKey::ClientsViewFull->value,
            PermissionKey::ClientsEdit->value,
            PermissionKey::ProjectsView->value,
            PermissionKey::ProjectsViewFinance->value,
            PermissionKey::ProjectsEdit->value,
            PermissionKey::TasksView->value,
            PermissionKey::TasksCreate->value,
            PermissionKey::TasksDelete->value,
            PermissionKey::AttendanceViewOwn->value,
            PermissionKey::AttendanceManageOthers->value,
            PermissionKey::LeaveApply->value,
            PermissionKey::LeaveApprove->value,
            PermissionKey::PayrollViewOwn->value,
            PermissionKey::PayrollViewOthers->value,
            PermissionKey::PayrollDraft->value,
            PermissionKey::PayrollApprove->value,
            PermissionKey::FinanceView->value,
            PermissionKey::FinanceManage->value,
            PermissionKey::AnnouncementsSend->value,
            PermissionKey::RolesManage->value,
            PermissionKey::AuditView->value,
            PermissionKey::SettingsManage->value,
        ],
        RoleName::MANAGER->value => [
            PermissionKey::ClientsViewFull->value,
            PermissionKey::ProjectsView->value,
            PermissionKey::ProjectsEdit->value,
            PermissionKey::TasksView->value,
            PermissionKey::TasksCreate->value,
            PermissionKey::TasksDelete->value,
            PermissionKey::AttendanceViewOwn->value,
            PermissionKey::AttendanceManageOthers->value,
            PermissionKey::LeaveApply->value,
            PermissionKey::LeaveApprove->value,
            PermissionKey::PayrollViewOwn->value,
        ],
        RoleName::EMPLOYEE->value => [
            PermissionKey::ProjectsView->value,
            PermissionKey::TasksView->value,
            PermissionKey::AttendanceViewOwn->value,
            PermissionKey::LeaveApply->value,
            PermissionKey::PayrollViewOwn->value,
        ],
        RoleName::REMOTE_EMPLOYEE->value => [
            PermissionKey::ProjectsView->value,
            PermissionKey::TasksView->value,
            PermissionKey::TimerUse->value,
            PermissionKey::AttendanceViewOwn->value,
            PermissionKey::LeaveApply->value,
            PermissionKey::PayrollViewOwn->value,
        ],
        RoleName::ACCOUNTANT->value => [
            PermissionKey::ProjectsViewFinance->value,
            PermissionKey::AttendanceViewOwn->value,
            PermissionKey::LeaveApply->value,
            PermissionKey::PayrollViewOwn->value,
            PermissionKey::PayrollViewOthers->value,
            PermissionKey::PayrollDraft->value,
            PermissionKey::FinanceView->value,
            PermissionKey::FinanceManage->value,
        ],
    ];

    /**
     * Seed every permission key, the five roles and their permissions.
     */
    public function run(): void
    {
        $permissionIds = [];

        foreach (PermissionKey::cases() as $key) {
            $permissionIds[$key->value] = Permission::firstOrCreate(['key' => $key->value])->id;
        }

        foreach (RoleName::cases() as $roleName) {
            $role = Role::firstOrCreate(['name' => $roleName->value]);

            $role->permissions()->sync(array_map(
                fn (string $key): int => $permissionIds[$key],
                self::MATRIX[$roleName->value],
            ));
        }
    }
}
