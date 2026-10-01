<?php

namespace App\Http\Requests\Messages;

use App\Models\User;
use App\Support\Permission;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Adding people to a message group (12-81). Only a holder of `messages.manage` may (403).
 * Removing one person is a DELETE with no body, and uses this request for the same 403.
 */
class GroupMembersRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->hasPermission(Permission::MessagesManage);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        if ($this->isMethod('DELETE')) {
            return [];
        }

        return [
            'user_ids' => ['required', 'array', 'min:1', 'max:100'],
            'user_ids.*' => ['integer', 'distinct', 'exists:users,id'],
        ];
    }

    /**
     * @return list<int>
     */
    public function userIds(): array
    {
        return array_values(array_map('intval', (array) $this->validated('user_ids')));
    }
}
