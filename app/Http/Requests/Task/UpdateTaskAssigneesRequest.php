<?php

namespace App\Http\Requests\Task;

use App\Models\Task;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Who a task is assigned to. Its own endpoint because it is its own ability and its own audit
 * event (`task.assigned` the first time, `task.reassigned` after that).
 *
 * An empty list is allowed and means "nobody" — unassigning is a thing that happens, and a
 * task with no assignees is a task with no primary, which the completion rule handles.
 */
class UpdateTaskAssigneesRequest extends FormRequest
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
            'assignee_ids' => ['present', 'array', 'max:'.Task::MAX_ASSIGNEES],
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
