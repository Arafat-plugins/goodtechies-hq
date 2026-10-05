<?php

namespace App\Http\Requests\Extension;

use App\Http\Requests\Time\TimerRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

/**
 * An answer to the idle prompt (docs/extension-api.md §6).
 */
class IdleDecisionRequest extends TimerRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'time_entry_id' => ['required', 'integer'],
            'idle_from' => ['required', 'date'],
            'idle_to' => ['required', 'date', 'after:idle_from'],
            'decision' => ['required', 'in:keep,discard,meeting,stop'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['idle_from', 'idle_to'])) {
                    return;
                }

                $from = Carbon::parse((string) $this->input('idle_from'));
                $to = Carbon::parse((string) $this->input('idle_to'));

                if ($from->diffInSeconds($to) > 24 * 3600) {
                    $validator->errors()->add('idle_to', 'An idle stretch may not be longer than 24 hours.');
                }
            },
        ];
    }

    public function timeEntryId(): int
    {
        return (int) $this->validated('time_entry_id');
    }

    public function idleFrom(): Carbon
    {
        return Carbon::parse((string) $this->validated('idle_from'))->setTimezone((string) config('app.timezone'));
    }

    public function idleTo(): Carbon
    {
        return Carbon::parse((string) $this->validated('idle_to'))->setTimezone((string) config('app.timezone'));
    }

    public function decision(): string
    {
        return (string) $this->validated('decision');
    }
}
