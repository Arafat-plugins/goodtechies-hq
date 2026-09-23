<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Support\RoleName;
use App\Support\TimeEntryType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @extends Factory<TimeEntry>
 */
class TimeEntryFactory extends Factory
{
    /**
     * A finished, counted, hour-long auto entry — the ordinary row. Every other shape is a
     * state below, so a test says which of the three states it means rather than assembling the
     * timestamps by hand and getting one of them subtly wrong.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startedAt = Carbon::now()->subHours(2);
        $endedAt = $startedAt->copy()->addHour();

        return [
            'employee_id' => Employee::factory()->forRole(RoleName::REMOTE_EMPLOYEE),
            'task_id' => Task::factory(),
            // Filled from the task in configure() so the two can never point at different
            // projects, which is the whole reason the column is denormalised.
            'project_id' => null,
            'work_date' => $startedAt->toDateString(),
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'duration_seconds' => 3600,
            'entry_type' => TimeEntryType::Auto,
            'client_uuid' => (string) Str::uuid(),
            'last_heartbeat_at' => $endedAt,
            'approved_at' => $endedAt,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (TimeEntry $entry): void {
            if ($entry->project_id === null) {
                $entry->project_id = Task::whereKey($entry->task_id)->value('project_id');
            }
        });
    }

    public function forEmployee(Employee $employee): static
    {
        return $this->state(fn (array $attributes): array => ['employee_id' => $employee->getKey()]);
    }

    public function onTask(Task $task): static
    {
        return $this->state(fn (array $attributes): array => [
            'task_id' => $task->getKey(),
            'project_id' => $task->project_id,
        ]);
    }

    /** Started and not finished, with the clock going. */
    public function running(?Carbon $startedAt = null): static
    {
        return $this->state(function (array $attributes) use ($startedAt): array {
            $startedAt ??= Carbon::now()->subMinutes(30);

            return [
                'started_at' => $startedAt,
                'work_date' => $startedAt->toDateString(),
                'ended_at' => null,
                'paused_at' => null,
                'duration_seconds' => null,
                'approved_at' => null,
                'last_heartbeat_at' => $attributes['last_heartbeat_at'] ?? Carbon::now(),
            ];
        });
    }

    /** Started, still open, and not counting. */
    public function paused(?Carbon $pausedAt = null): static
    {
        return $this->running()->state(fn (array $attributes): array => [
            'paused_at' => $pausedAt ?? Carbon::now()->subMinutes(5),
        ]);
    }

    public function heartbeatAt(?Carbon $at): static
    {
        return $this->state(fn (array $attributes): array => ['last_heartbeat_at' => $at]);
    }

    /** Typed in by hand, and — unless a state says otherwise — waiting for approval. */
    public function manual(string $reason = 'Forgot to start the timer this morning.'): static
    {
        return $this->state(fn (array $attributes): array => [
            'entry_type' => TimeEntryType::Manual,
            'reason' => $reason,
            'approved_at' => null,
            'approved_by' => null,
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes): array => ['approved_at' => Carbon::now()]);
    }

    /**
     * Somebody looked and said no. The hours stay on the row; they do not count.
     *
     * `approved_at` is cleared alongside, because the CHECK constraint
     * `time_entries_not_approved_and_rejected` forbids both at once — a row that both counted
     * and had been turned down is the contradiction decision 4-7 exists to prevent.
     */
    public function rejected(string $reason = 'These hours are already on another entry.'): static
    {
        return $this->state(fn (array $attributes): array => [
            'approved_at' => null,
            'approved_by' => null,
            'rejected_at' => Carbon::now(),
            'rejection_reason' => $reason,
        ]);
    }

    public function flagged(string $reason = 'Stopped automatically: the timer stopped checking in.'): static
    {
        return $this->state(fn (array $attributes): array => [
            'flagged_at' => Carbon::now(),
            'flag_reason' => $reason,
        ]);
    }

    public function on(Carbon $date): static
    {
        return $this->state(function (array $attributes) use ($date): array {
            $startedAt = $date->copy()->setTime(10, 0);

            return [
                'work_date' => $startedAt->toDateString(),
                'started_at' => $startedAt,
                'ended_at' => $startedAt->copy()->addHour(),
            ];
        });
    }
}
