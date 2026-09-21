<?php

namespace App\Http\Requests\Task;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The explicit hand-off: move "primary assignee" to the other person on the task, so that
 * their work summary is the one completion accepts.
 *
 * The reason is required, and not out of ceremony: the hand-off is the single exception the
 * completion rule allows, so it has to leave something behind that says who decided it and
 * why. It writes `task.reassigned` to audit_logs.
 */
class HandOffTaskRequest extends FormRequest
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
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    public function employeeId(): int
    {
        return (int) $this->validated('employee_id');
    }

    public function reason(): string
    {
        return trim((string) $this->validated('reason'));
    }
}
