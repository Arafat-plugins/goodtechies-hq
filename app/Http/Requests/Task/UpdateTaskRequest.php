<?php

namespace App\Http\Requests\Task;

use App\Models\Task;
use App\Services\TaskService;
use App\Support\TaskPriority;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Editing a task, on either surface.
 *
 * Two rules are written into the rule set rather than left to a controller:
 *
 *  - **`status` is not accepted.** It moves only through the status endpoint, which enforces
 *    the transition map and the role rules. Phase 1 shipped this fix for projects after a
 *    general update endpoint that took `status` let an assigned manager cancel a project
 *    through the edit form; a task must not grow the same hole. A `status` sent here is
 *    ignored, exactly as UpdateProjectRequest ignores one.
 *
 *  - **The plan fields are Admin/Manager only.** Dates, priority, title and which project the
 *    task belongs to are the plan; an assignee edits the work — the description, the work
 *    summary and the estimate. "Date changes by drag are Admin/Manager only" is the same rule
 *    seen from the calendar, and it holds here because this is the only endpoint that writes a
 *    date at all. An employee who sends one is told no, rather than having it silently dropped.
 *
 * Tags are the work rather than the plan, so an employee may assign one. What nobody does here
 * is CREATE one: `tag_ids` names tags that already exist, and a tag scoped to another project is
 * not one of them. The scope is checked against the project the task will be in once this
 * request is done, so moving the task and setting its tags in one go cannot leave it wearing a
 * label from the project it came from.
 */
class UpdateTaskRequest extends FormRequest
{
    /**
     * The fields only a manager may write. For anybody else they are `prohibited`, which is a
     * validation error with a message, not a field that vanishes.
     *
     * @var list<string>
     */
    private const MANAGER_FIELDS = ['project_id', 'title', 'priority', 'start_date', 'due_date'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $mayPlan = $this->mayChangeThePlan();
        $plan = fn (array $rules): array => $mayPlan ? ['nullable', ...$rules] : ['prohibited'];

        return [
            'project_id' => $plan(['integer', 'exists:projects,id']),
            'title' => $mayPlan ? ['sometimes', 'required', 'string', 'max:255'] : ['prohibited'],
            'description' => ['nullable', 'string', 'max:10000'],
            'priority' => $plan([Rule::enum(TaskPriority::class)]),
            'start_date' => $plan(['date']),
            'due_date' => $plan(['date', 'after_or_equal:start_date']),

            // The work, which either assignee may write.
            'estimated_minutes' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'work_summary' => ['nullable', 'string', 'max:10000'],

            // `sometimes`, so a request that says nothing about tags leaves them alone; an
            // empty array is "no tags" and clears them. The exists rule is what stops project
            // A's tag reaching a task in project B — see targetProjectId().
            'tag_ids' => ['sometimes', 'array', 'max:'.Task::MAX_TAGS],
            'tag_ids.*' => [
                'integer',
                Rule::exists('tags', 'id')->where(fn (Builder $query) => $query
                    ->whereNull('project_id')
                    ->orWhere('project_id', $this->targetProjectId()),
                ),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $refused = 'Only an administrator or a manager may change a task\'s :attribute.';

        return array_fill_keys(
            array_map(fn (string $field): string => $field.'.prohibited', self::MANAGER_FIELDS),
            $refused,
        ) + [
            'tag_ids.*.exists' => 'A tag has to be a global one or one of this project\'s own.',
        ];
    }

    /**
     * Not a role check written out here: TaskService::mayPlan() is the one definition, and the
     * Calendar sends the same answer as `can_plan` so its date handles are disabled for exactly
     * the people this makes the date fields `prohibited` for.
     */
    private function mayChangeThePlan(): bool
    {
        return TaskService::mayPlan($this->user());
    }

    /**
     * The project a tag has to be usable on: the one this request moves the task to, or the one
     * it is already in.
     *
     * A requester who may not change the plan cannot move it anywhere, so their `project_id` is
     * not read here — it is already a `prohibited` error of its own, and reading it would let a
     * refused field decide which tags pass.
     */
    private function targetProjectId(): ?int
    {
        $task = $this->route('task');
        $current = $task instanceof Task && $task->project_id !== null ? (int) $task->project_id : null;

        if (! $this->mayChangeThePlan()) {
            return $current;
        }

        $requested = $this->input('project_id');

        return is_numeric($requested) ? (int) $requested : $current;
    }
}
