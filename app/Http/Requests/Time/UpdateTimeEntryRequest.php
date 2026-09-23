<?php

namespace App\Http\Requests\Time;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Carbon;

/**
 * Correcting an entry: "I left at one and the timer ran on until six."
 *
 * The reason is required here for the same reason it is required on a manual entry, and with
 * more force: this one changes a number that was already recorded. `TimerService::edit()` writes
 * the change to `audit_logs` with the old and the new values, whoever made it.
 *
 * Authorisation is `TimeEntryPolicy::update` and is checked in the controller **against the
 * record**, not here — the base class's route-wide gate would answer "may you use a timer",
 * which is not the same question as "may you change THIS entry". A record the requester may not
 * see never reaches the policy at all: it is resolved through `TimeEntry::visibleTo()` and
 * answers 404.
 */
class UpdateTimeEntryRequest extends TimerRequest
{
    public const MAX_REASON = 500;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'started_at' => ['required', 'date'],
            'ended_at' => ['required', 'date', 'after:started_at'],
            'reason' => ['required', 'string', 'max:'.self::MAX_REASON],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Say why the times are being changed — this entry has already been recorded.',
            'ended_at.after' => 'The finish time has to be after the start time.',
        ];
    }

    public function startedAt(): Carbon
    {
        return Carbon::parse((string) $this->validated('started_at'));
    }

    public function endedAt(): Carbon
    {
        return Carbon::parse((string) $this->validated('ended_at'));
    }

    public function reason(): string
    {
        return (string) $this->text('reason');
    }
}
