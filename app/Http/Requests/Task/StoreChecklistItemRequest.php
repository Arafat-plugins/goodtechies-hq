<?php

namespace App\Http\Requests\Task;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A new line on a task's checklist. A checklist item is a title and a tick — it has no
 * assignee, no dates and no status, so there is nothing else to validate.
 */
class StoreChecklistItemRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
        ];
    }

    public function title(): string
    {
        return trim((string) $this->validated('title'));
    }
}
