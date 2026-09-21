<?php

namespace App\Http\Requests\Task;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Renaming or ticking a checklist line. Both are optional: the tick arrives on its own from a
 * checkbox, the title on its own from an inline rename.
 */
class UpdateChecklistItemRequest extends FormRequest
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
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'is_done' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * What the request actually asks to change. Not named attributes(): that is Laravel's hook
     * for the names validation messages use, and overriding it would rename every field in
     * every error message this request can produce.
     *
     * @return array<string, mixed>
     */
    public function changes(): array
    {
        $attributes = [];

        if ($this->has('title')) {
            $attributes['title'] = trim((string) $this->validated('title'));
        }

        if ($this->has('is_done')) {
            $attributes['is_done'] = $this->boolean('is_done');
        }

        return $attributes;
    }
}
