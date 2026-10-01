<?php

namespace App\Http\Requests\Messages;

use App\Models\User;
use App\Support\Permission;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * Renaming a message group or changing its picture (12-81). Only a holder of `messages.manage`
 * may (403); GroupController then 404s a group the actor cannot see.
 */
class UpdateGroupRequest extends FormRequest
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
        return [
            'name' => ['sometimes', 'string', 'max:80'],
            'avatar' => StoreGroupRequest::AVATAR_RULES,
            'remove_avatar' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    public function name(): ?string
    {
        $name = $this->validated('name');

        return is_string($name) ? trim($name) : null;
    }

    public function avatar(): ?UploadedFile
    {
        $file = $this->file('avatar');

        return $file instanceof UploadedFile ? $file : null;
    }

    public function removeAvatar(): bool
    {
        return $this->boolean('remove_avatar');
    }
}
