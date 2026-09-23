<?php

namespace App\Http\Requests\Recurring;

use App\Models\RecurringTask;
use App\Support\UserStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * What a retainer template is, as input: a title, a checklist, a rule, an owner and a switch.
 *
 * Creating one and editing one take the same fields — there is nothing an edit can set that a
 * new template could not, and nothing a new template needs that an edit may not change — so the
 * two requests are this class with a different name. They are still two classes rather than one
 * reused for both, because the controller signature is what says which endpoint is which and a
 * shared request would make that a runtime question.
 *
 * Authorization is NOT here. `RecurringTaskPolicy` answers it and the controller asks, which is
 * what keeps the record-scoped half (a project this requester cannot see) a 404 rather than a
 * validation message that confirms the project exists.
 */
abstract class RecurringTaskRequest extends FormRequest
{
    use BuildsRecurrenceRule;

    /** Matches the `title_template` column, which is a plain `string` (255). */
    public const MAX_TITLE = 255;

    /** One checklist line. The same bound `TaskChecklistItem` puts on a hand-made item. */
    public const MAX_CHECKLIST_ITEM = 255;

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
            // The `{period}`, `{project}` and `{date}` placeholders are NOT required, and not
            // validated for. A template with none of them produces the same title every month,
            // which the engine documents as legal and usually a mistake — the author's mistake
            // to make. The editor says so beside the field instead.
            'title_template' => ['required', 'string', 'max:'.self::MAX_TITLE],

            // Absent, null or [] all mean "no checklist". The engine replays whatever is here
            // through addChecklistItem(), so an empty line would become an empty item; the
            // model's checklistItems() drops them and this refuses the ones a person can fix.
            'checklist_template' => ['nullable', 'array', 'max:'.RecurringTask::MAX_CHECKLIST_ITEMS],
            'checklist_template.*' => ['required', 'string', 'max:'.self::MAX_CHECKLIST_ITEM],

            // Nullable by design — the column is. A template whose assignee has left keeps
            // generating into nobody's plate, and the task shows up unassigned rather than not
            // at all. Choosing a DEACTIVATED employee, though, is a mistake made today, so it is
            // refused today.
            'default_assignee_id' => [
                'nullable',
                'integer',
                Rule::exists('employees', 'id')->where('status', UserStatus::Active->value),
            ],

            'active' => ['required', 'boolean'],

            ...$this->recurrenceRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title_template.required' => 'Give the generated task a title.',
            'checklist_template.max' => 'A checklist template holds at most '.
                RecurringTask::MAX_CHECKLIST_ITEMS.' items.',
            'checklist_template.*.required' => 'Remove the blank checklist line, or write something in it.',
            'default_assignee_id.exists' => 'That person is not an active employee.',
            ...$this->recurrenceMessages(),
        ];
    }

    /**
     * The template's own columns, rule aside — the shape `RecurringTaskService` takes.
     *
     * Not `attributes()`: that name belongs to FormRequest, where it renames fields inside
     * validation messages.
     *
     * @return array{title_template: string, checklist_template: list<string>, default_assignee_id: int|null, active: bool}
     */
    public function templateAttributes(): array
    {
        return [
            'title_template' => trim((string) $this->validated('title_template')),
            'checklist_template' => $this->checklist(),
            'default_assignee_id' => $this->assigneeId(),
            'active' => (bool) $this->validated('active'),
        ];
    }

    /**
     * @return list<string>
     */
    private function checklist(): array
    {
        $items = $this->validated('checklist_template') ?? [];

        return array_values(array_filter(
            array_map(fn (mixed $item): string => trim((string) $item), $items),
            fn (string $item): bool => $item !== '',
        ));
    }

    private function assigneeId(): ?int
    {
        $value = $this->validated('default_assignee_id');

        return $value === null || $value === '' ? null : (int) $value;
    }
}
