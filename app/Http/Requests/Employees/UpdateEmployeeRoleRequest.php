<?php

namespace App\Http\Requests\Employees;

use App\Services\EmployeeAdministrationService;
use App\Support\RoleName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One field: the role this employee is to have from now on (master prompt Part D §2 — *"Users &
 * Roles is the same screen family (Employees list → employee detail → role, schedule,
 * tracking_mode)"*).
 *
 * ## MANAGER is refused here as well, and it is the same list
 *
 * Part C §1: *"MANAGER exists in the roles table from Phase 0 but is **assigned to nobody** and
 * gets no dedicated UI in MVP."* `StoreEmployeeRequest` refuses it on the way in; this refuses it
 * on the way across, against the **same** `EmployeeAdministrationService::ASSIGNABLE_ROLES`
 * constant — because a rule that is stated twice in two lists is a rule that is one edit away
 * from being two rules. A hire who could not be created as a MANAGER but could be promoted into
 * one the next day would be that line broken through the back door.
 *
 * ## Why there is no `update` for the whole record
 *
 * A role is not a field on a form beside a phone number: changing it changes what somebody may
 * reach across the whole agency, it is one of the events Part C §4 requires in `audit_logs`, and
 * it is the one act Part C §1 forbids on your own account. So it is its own endpoint with its own
 * confirmation, the way a project's status is (`POST …/status`) rather than a key in
 * `PUT /admin/projects/{id}` — which that controller ignores by design, for the same reason.
 *
 * Authorization is `EmployeePolicy::changeRole`, asked in the controller, and
 * `EmployeeAdministrationService::guard()` refuses the call as well.
 */
class UpdateEmployeeRoleRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', Rule::in(array_map(
                fn (RoleName $role): string => $role->value,
                EmployeeAdministrationService::ASSIGNABLE_ROLES,
            ))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'role.in' => 'That is not a role this screen can assign.',
        ];
    }

    public function role(): RoleName
    {
        return RoleName::from($this->validated()['role']);
    }
}
