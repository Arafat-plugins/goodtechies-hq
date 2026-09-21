<?php

namespace App\Http\Requests\Project;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The full member list for a project: what is sent is what the project ends up with, so an
 * empty array is a valid way to clear it.
 */
class UpdateProjectMembersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'members' => ['present', 'array'],
            'members.*' => ['integer', 'exists:employees,id'],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['nullable', 'string', 'max:60'],
        ];
    }

    /**
     * The members as integer ids.
     *
     * @return list<int>
     */
    public function memberIds(): array
    {
        return array_values(array_map('intval', $this->validated('members', [])));
    }

    /**
     * The per-member role on the project, keyed by employee id.
     *
     * @return array<int, string|null>
     */
    public function rolesById(): array
    {
        $roles = [];

        foreach ($this->validated('roles', []) as $employeeId => $role) {
            $roles[(int) $employeeId] = $role === null ? null : (string) $role;
        }

        return $roles;
    }
}
