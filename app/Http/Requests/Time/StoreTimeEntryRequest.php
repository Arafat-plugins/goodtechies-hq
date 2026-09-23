<?php

namespace App\Http\Requests\Time;

use App\Models\TimeEntry;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * "Add manual entry" — a stretch of work typed in afterwards.
 *
 * **The reason is required, and that is the whole point of the form.** A manual entry is a claim
 * rather than a measurement, so the one thing it must carry is why it exists. Part D §7 says so
 * and `settings.manual_time_requires_approval` (default on) then decides whether the claim counts
 * before somebody has read that sentence.
 *
 * The range rules here are the ones a person should see under a field. The believability rules —
 * ends after it starts, not in the future, not longer than a day — are `TimerService`'s, because
 * a replay and a later Admin screen reach them without passing through this form.
 */
class StoreTimeEntryRequest extends TimerRequest
{
    public const MAX_REASON = 500;

    public function authorize(): bool
    {
        return Gate::allows('create', TimeEntry::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'task_id' => ['required', 'integer', 'exists:tasks,id'],
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
            'reason.required' => 'Say why this is being added by hand — it is the only record of where the time came from.',
            'ended_at.after' => 'The finish time has to be after the start time.',
        ];
    }

    public function taskId(): int
    {
        return (int) $this->validated('task_id');
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
