<?php

namespace App\Http\Requests\Messages;

use App\Models\User;
use App\Support\Permission;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * Creating a message group (12-81): a name, the people, an optional picture. Only a holder of
 * `messages.manage` may (403). Whether each person may be in a group at all — active, holding
 * `messages.use` — is GroupService's check, because it is a rule about the user, not the shape.
 */
class StoreGroupRequest extends FormRequest
{
    public const AVATAR_RULES = ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:max_width=4096,max_height=4096'];

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
        return [
            'name' => ['required', 'string', 'max:80'],
            'member_ids' => ['required', 'array', 'min:1', 'max:100'],
            'member_ids.*' => ['integer', 'distinct', 'exists:users,id'],
            'avatar' => self::AVATAR_RULES,
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    public function name(): string
    {
        return trim((string) $this->validated('name'));
    }

    /**
     * @return list<int>
     */
    public function memberIds(): array
    {
        return array_values(array_map('intval', (array) $this->validated('member_ids')));
    }

    public function avatar(): ?UploadedFile
    {
        $file = $this->file('avatar');

        return $file instanceof UploadedFile ? $file : null;
    }
}
