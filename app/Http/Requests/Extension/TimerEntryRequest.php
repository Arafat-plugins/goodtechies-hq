<?php

namespace App\Http\Requests\Extension;

use App\Http\Requests\Time\TimerRequest;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Pause, resume or stop one entry from the extension (docs/extension-api.md §2). Whose entry it
 * is, is decided by resolving it through the employee in the controller (404 otherwise).
 */
class TimerEntryRequest extends TimerRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'time_entry_id' => ['required', 'integer'],
        ];
    }

    public function timeEntryId(): int
    {
        return (int) $this->validated('time_entry_id');
    }
}
