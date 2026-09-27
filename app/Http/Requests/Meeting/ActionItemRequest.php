<?php

namespace App\Http\Requests\Meeting;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * An action item, which is a task (Part D §12: *"action items → **Convert to Task** (tasks get
 * `source_meeting_id`)"*).
 *
 * ## Three fields, because adding and converting are one control
 *
 * There is no `meeting_action_items` table and there must not be one — the
 * `2026_10_01_0003` migration says so in as many words: *"an action item **becomes a task**,
 * and the task is where it lives"*. A list of items waiting to be converted would be a second
 * to-do list, invisible to the board, the calendar, My Tasks and every report.
 *
 * So the screen has one control where a naive design would have two: you type what somebody
 * agreed to do, say who and by when, and a task exists. The already-converted items are the
 * meeting's `actionItemTasks`, read back on the next render.
 *
 * ## What it deliberately does not accept
 *
 *   - **`project_id`.** The project is the meeting's. `MeetingService::convertActionItem()`
 *     forces it and refuses a caller that names a different one
 *     (`MeetingStateException::actionItemProjectMismatch()`), because *Convert to Task* is not
 *     a general task form with a meeting id stapled to it. Prohibiting it here means that
 *     refusal is a field error on the form rather than an exception from the service.
 *   - **`status`, `priority`, `estimated_minutes`, `description`.** The task's own edit form
 *     owns all four, and it is one click away on the task this creates. A convert control that
 *     grew into a second task form is how the one-control rule above gets lost.
 *   - **`source_meeting_id`.** It is a BIRTH_FIELD set by the service from the meeting in the
 *     URL. Nothing a sender types decides where a task came from.
 *
 * Only **one** assignee, and it is optional. Part D's sentence is *"title, assignee, due
 * date"*, singular; a task takes two and the task's own People panel is where a second one is
 * added, with the audit row that goes with it.
 */
class ActionItemRequest extends FormRequest
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

            // See the class docblock. The project is the meeting's, not the sender's.
            'project_id' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'project_id.prohibited' => 'An action item goes on the meeting\'s own project.',
        ];
    }

    public function title(): string
    {
        return trim((string) $this->validated('title'));
    }

    public function dueDate(): ?string
    {
        $value = $this->validated('due_date');

        return $value === null ? null : (string) $value;
    }

    /**
     * The assignee as `TaskService::create()` wants them: a list of zero or one employee id.
     *
     * @return list<int>
     */
    public function assigneeIds(): array
    {
        $id = $this->validated('assignee_id');

        return $id === null ? [] : [(int) $id];
    }
}
