<?php

namespace App\Http\Requests\Task;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A new subtask under a task (decision 12-71): the drawer's "Add subtask" row, as in Asana —
 * a title, and optionally one person and a due date. Everything else a subtask has (status,
 * project, position) is decided by TaskService::createSubtask(), not by the request.
 *
 * Who may add one, and whether this task may have subtasks at all (one level deep), is
 * TaskPolicy::createSubtask's answer, not this class's.
 */
class StoreSubtaskRequest extends FormRequest
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
            'assignee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'due_date' => ['nullable', 'date'],
        ];
    }

    public function title(): string
    {
        return trim((string) $this->validated('title'));
    }

    public function assigneeId(): ?int
    {
        $id = $this->validated('assignee_id');

        return $id === null ? null : (int) $id;
    }

    public function dueDate(): ?string
    {
        $date = $this->validated('due_date');

        return $date === null ? null : (string) $date;
    }
}
