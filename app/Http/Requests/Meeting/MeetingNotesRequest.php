<?php

namespace App\Http\Requests\Meeting;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * What was said, and what was settled (Part D §12: *"notes + decisions recorded"*).
 *
 * Two fields and one save, because they are one pad: `MeetingService::recordNotes()` takes both
 * in a single `updateOrCreate` behind a unique index on `meeting_id`, so two people writing up
 * the same meeting at the same moment end with one pad rather than two.
 *
 * **Both are nullable and an empty string is not an error.** A meeting can have notes with no
 * decisions ("we talked it through, nothing settled") and decisions with no notes ("approved
 * the sitemap"); the migration makes both columns nullable for exactly that reason. Emptying
 * both is also a legitimate act — the service deletes the pad, so *"has this meeting been
 * minuted?"* stays a question the presence of a row answers.
 *
 * Who may send this is the `update` ability (the organiser or an Admin), asked by the service.
 * It is not asked here, because a Form Request has no meeting yet: the id is resolved through
 * `Meeting::visibleTo()` in the controller, and that lookup's 404 is the privacy rule.
 */
class MeetingNotesRequest extends FormRequest
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
            // The same ceiling `StoreTaskRequest` puts on a description: long enough for a
            // page of minutes, short enough that a paste accident is refused on the form
            // rather than stored.
            'notes' => ['nullable', 'string', 'max:10000'],
            'decisions' => ['nullable', 'string', 'max:10000'],
        ];
    }

    public function notes(): ?string
    {
        $value = $this->validated('notes');

        return $value === null ? null : (string) $value;
    }

    public function decisions(): ?string
    {
        $value = $this->validated('decisions');

        return $value === null ? null : (string) $value;
    }
}
