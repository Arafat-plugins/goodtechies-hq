<?php

namespace App\Http\Requests\Task;

use App\Models\Task;
use App\Services\TaskService;
use App\Support\TaskPriority;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creating a task.
 *
 * `status` is accepted here and nowhere else in the write surface, and only as one of the two
 * statuses a task may be born in — a task has to start somewhere, but nobody creates one that
 * is already in review or completed. Every move after this one goes through the status
 * endpoint and the machine behind it.
 */
class StoreTaskRequest extends FormRequest
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
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'status' => ['nullable', Rule::in(TaskService::BIRTH_STATUSES)],
            'priority' => ['required', Rule::enum(TaskPriority::class)],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'estimated_minutes' => ['nullable', 'integer', 'min:0', 'max:1000000'],

            // At most two, and the primary has to be one of them — the database holds the
            // "exactly one primary" half with a partial unique index.
            'assignee_ids' => ['nullable', 'array', 'max:'.Task::MAX_ASSIGNEES],
            'assignee_ids.*' => ['integer', 'exists:employees,id'],
            'primary_assignee_id' => ['nullable', 'integer', 'in_array:assignee_ids.*'],
        ];
    }

    /**
     * @return list<int>
     */
    public function assigneeIds(): array
    {
        return array_values(array_map('intval', (array) ($this->validated('assignee_ids') ?? [])));
    }

    public function primaryAssigneeId(): ?int
    {
        $id = $this->validated('primary_assignee_id');

        return $id === null ? null : (int) $id;
    }
}
