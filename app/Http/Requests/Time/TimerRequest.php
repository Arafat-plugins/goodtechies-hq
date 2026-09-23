<?php

namespace App\Http\Requests\Time;

use App\Models\TimeEntry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * The base every timer request sits on, for one reason: `authorize()`.
 *
 * A Form Request's `authorize()` runs BEFORE its rules. That ordering is the whole point here —
 * an office employee posting to a timer endpoint has to get a **403 about who they are**, not a
 * validation redirect about a field they were never allowed to fill in. Putting the gate in the
 * controller would hide the refusal behind the validator, and the permission matrix would then
 * record a 302 where the plan says 403.
 *
 * `TimeEntryPolicy::track` is the gate: the `timer.use` key AND `tracking_mode = remote_timer`.
 * Never a role name — see the policy.
 */
abstract class TimerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('track', TimeEntry::class);
    }

    /**
     * A validated timestamp as a Carbon, or null. The service decides whether it is believable;
     * this only decides whether it is a date.
     */
    protected function moment(string $key): ?Carbon
    {
        $value = $this->validated($key);

        return is_string($value) && $value !== '' ? Carbon::parse($value) : null;
    }

    protected function text(string $key): ?string
    {
        $value = $this->validated($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
