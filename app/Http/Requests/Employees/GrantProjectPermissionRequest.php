<?php

namespace App\Http\Requests\Employees;

use App\Services\EmployeeAdministrationService;
use App\Support\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One project-level permission grant (Part C §1: *"Project-level overrides
 * (`user_project_permissions`) layer on top — e.g. a MANAGER may be granted
 * `projects.view_finance` for one project"*).
 *
 * `permission` is restricted to `EmployeeAdministrationService::GRANTABLE_PERMISSIONS`, which is
 * exactly the keys a policy READS out of that table today. A key no policy reads would store a
 * row that grants nothing while the screen said otherwise, so it is refused at validation rather
 * than written and quietly ignored.
 *
 * The project is validated as existing and is re-resolved through `Project::visibleTo()` in the
 * controller, so a project the requester may not see is **404** rather than a validation message
 * that would confirm it exists (Part C).
 *
 * Authorization is `EmployeePolicy::managePermissions`, asked in the controller.
 */
class GrantProjectPermissionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', Rule::exists('projects', 'id')],
            'permission' => ['required', Rule::in(array_map(
                fn (Permission $permission): string => $permission->value,
                EmployeeAdministrationService::GRANTABLE_PERMISSIONS,
            ))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'project_id' => 'project',
        ];
    }

    public function permission(): Permission
    {
        return Permission::from($this->validated()['permission']);
    }

    public function projectId(): int
    {
        return (int) $this->validated()['project_id'];
    }
}
