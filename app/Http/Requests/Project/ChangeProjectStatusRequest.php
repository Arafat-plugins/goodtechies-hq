<?php

namespace App\Http\Requests\Project;

use App\Support\ProjectStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Moving a project along its lifecycle. Archiving is not a status change — it has its own
 * route — so `archived` is not an accepted target here.
 */
class ChangeProjectStatusRequest extends FormRequest
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
            'status' => ['required', Rule::enum(ProjectStatus::class)->except(ProjectStatus::Archived)],
            // Cancelling is the one move nobody may make silently: it has to say why.
            'reason' => ['required_if:status,'.ProjectStatus::Cancelled->value, 'nullable', 'string', 'max:500'],
        ];
    }

    public function status(): ProjectStatus
    {
        return ProjectStatus::from($this->validated('status'));
    }

    public function reason(): ?string
    {
        $reason = $this->validated('reason');

        return is_string($reason) && trim($reason) !== '' ? trim($reason) : null;
    }
}
