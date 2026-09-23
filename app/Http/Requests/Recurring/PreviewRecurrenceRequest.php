<?php

namespace App\Http\Requests\Recurring;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The next-run preview: a rule that has not been saved, and may never be.
 *
 * The editor asks this while somebody is still choosing — "monthly, the 15th" — and prints the
 * answer under the controls: *the next run is 15 October 2026, for period October 2026*. The
 * point of the endpoint is that the answer comes from `RecurrenceRule`, the same object the
 * 00:05 run uses, rather than from a date calculation written a second time in Vue. A preview
 * that disagreed with the engine would be worse than no preview at all.
 *
 * It therefore validates the rule fields and **nothing else**: there is no title here, no
 * checklist and no assignee, because none of them changes when the template fires.
 */
class PreviewRecurrenceRequest extends FormRequest
{
    use BuildsRecurrenceRule;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->recurrenceRules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->recurrenceMessages();
    }
}
