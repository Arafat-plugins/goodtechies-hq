<?php

namespace App\Http\Requests\Project;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Deleting an archived project for good. The typed name is checked against the project by
 * ProjectService, which answers a mismatch as a flash error rather than a field error.
 */
class ForceDeleteProjectRequest extends FormRequest
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
            'confirm_name' => ['required', 'string', 'max:255'],
        ];
    }

    public function confirmName(): string
    {
        return (string) $this->validated('confirm_name');
    }
}
