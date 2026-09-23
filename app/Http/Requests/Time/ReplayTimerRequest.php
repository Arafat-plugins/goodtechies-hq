<?php

namespace App\Http\Requests\Time;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Carbon;

/**
 * What a browser sends when it comes back from being offline.
 *
 * The batch is the widget's `localStorage` state: the uuid it generated before the session
 * started, the task, when it started, every heartbeat it could not deliver, the pause it has
 * accumulated, and — if the session ended while it was offline — the stop it believes in.
 *
 * Nothing here is trusted as an account of what happened. `TimerService::replay()` measures the
 * whole batch against its heartbeats and the server's own record, and the server wins. This
 * request's only job is to make sure the shape is a shape.
 *
 * The heartbeat list is capped. A browser shut for a fortnight has 20 000 buffered minutes and
 * the service only ever reads the latest one, so an unbounded array is a request body nobody
 * needs and a parse loop somebody could aim at us.
 */
class ReplayTimerRequest extends TimerRequest
{
    /** A whole day of once-a-minute pings, with room to spare. */
    public const MAX_HEARTBEATS = 2000;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'task_id' => ['required', 'integer', 'exists:tasks,id'],
            'client_uuid' => ['required', 'uuid'],
            'started_at' => ['required', 'date'],
            'heartbeats' => ['nullable', 'array', 'max:'.self::MAX_HEARTBEATS],
            'heartbeats.*' => ['nullable', 'string', 'max:64'],
            // The client's accumulated pause. The service keeps whichever of the two sides knows
            // about more pausing, and a pause can never exceed the session containing it.
            'paused_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'stopped_at' => ['nullable', 'date'],
        ];
    }

    public function taskId(): int
    {
        return (int) $this->validated('task_id');
    }

    public function clientUuid(): string
    {
        return (string) $this->validated('client_uuid');
    }

    public function startedAt(): Carbon
    {
        return Carbon::parse((string) $this->validated('started_at'));
    }

    /**
     * @return list<string>
     */
    public function heartbeats(): array
    {
        $values = $this->validated('heartbeats') ?? [];

        return array_values(array_filter(
            array_map(fn (mixed $value): string => is_string($value) ? $value : '', $values),
            fn (string $value): bool => $value !== '',
        ));
    }

    public function pausedSeconds(): int
    {
        return (int) ($this->validated('paused_seconds') ?? 0);
    }

    public function stoppedAt(): ?Carbon
    {
        return $this->moment('stopped_at');
    }
}
