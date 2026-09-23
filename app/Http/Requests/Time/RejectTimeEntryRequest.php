<?php

namespace App\Http\Requests\Time;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Refusing a time entry: the reason, which is the whole of the request.
 *
 * **It does not extend `TimerRequest`.** That base's `authorize()` asks `TimeEntryPolicy::track`
 * — "may you run a timer" — and the person refusing somebody else's hours is an Admin, who has
 * no timer at all. Inheriting it would have made every approval 403 for exactly the people the
 * screen is for. Authorisation here is `TimeEntryPolicy::approve` checked in the controller
 * **against the record**, after the entry has been resolved through `TimeEntry::visibleTo()`, so
 * an entry the requester may not see is 404 and one they may not rule on is 403.
 *
 * The reason is required for the same reason an attendance correction's is: this takes hours out
 * of somebody's record, Phase 9 pays from that record, and "no" with no sentence attached is not
 * something the person it happened to can do anything about. It is stored on the row —
 * `time_entries.rejection_reason` — and shown to them, not only written to `audit_logs`.
 */
class RejectTimeEntryRequest extends FormRequest
{
    public const MAX_REASON = 500;

    /**
     * The policy runs in the controller against the resolved record. Returning true here means
     * "this Form Request has no route-wide gate of its own", not "anybody may do this".
     */
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
            'reason' => ['required', 'string', 'max:'.self::MAX_REASON],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Say why these hours are being turned down — the person who recorded them will read it.',
        ];
    }

    public function reason(): string
    {
        return trim((string) $this->validated('reason'));
    }
}
