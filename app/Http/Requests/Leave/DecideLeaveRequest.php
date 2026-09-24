<?php

namespace App\Http\Requests\Leave;

use Illuminate\Foundation\Http\FormRequest;

/**
 * An approver's decision note.
 *
 * One request class for all three verbs, because they take the same one field and differ only
 * in whether it is required — and that difference is the rule worth stating:
 *
 *   - **Approving needs no note.** "Yes" explains itself, and a required box on the common case
 *     is a box people fill with a full stop.
 *   - **Rejecting and requesting a correction do.** Both take something away from the person
 *     who asked — the days, or the wait — and both are the sentence they will read on My Leave.
 *     A refusal nobody can read the reason for is the complaint decision 4-18 records about a
 *     refused time entry, answered before it can be made again.
 *
 * `noteRequired()` is the routed action rather than a flag in the body, so a client cannot
 * choose which rules apply to it.
 */
class DecideLeaveRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'note' => $this->noteRequired()
                ? ['required', 'string', 'min:5', 'max:1000']
                : ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['note' => 'reason'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'note.required' => 'Say why. The person who asked reads this on their own leave page.',
        ];
    }

    public function note(): ?string
    {
        $note = trim((string) ($this->validated()['note'] ?? ''));

        return $note === '' ? null : $note;
    }

    /**
     * Rejecting and requesting a correction need a sentence; approving does not.
     *
     * Read off the route's NAME, not off the body: the three verbs are three endpoints, and
     * which one was called is a fact about the URL.
     */
    private function noteRequired(): bool
    {
        return in_array($this->route()?->getName(), [
            'admin.leave.reject',
            'admin.leave.correction',
        ], true);
    }
}
