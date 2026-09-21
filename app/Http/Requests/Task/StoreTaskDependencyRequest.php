<?php

namespace App\Http\Requests\Task;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * "This task waits for that one."
 *
 * Whether the other task exists is a validation question and lives here. Whether it is in the
 * same project, whether it is this same task, and whether the link would close a cycle are
 * questions about the graph, and they live in TaskService — a rule that needs a walk of the
 * dependency table is not something a validator can hold.
 */
class StoreTaskDependencyRequest extends FormRequest
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
            'depends_on_task_id' => ['required', 'integer', 'exists:tasks,id'],
        ];
    }

    public function dependsOnTaskId(): int
    {
        return (int) $this->validated('depends_on_task_id');
    }
}
