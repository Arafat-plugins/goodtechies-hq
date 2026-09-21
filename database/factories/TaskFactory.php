<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\Project;
use App\Models\Task;
use App\Support\TaskPriority;
use App\Support\TaskStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = fake()->dateTimeBetween('-30 days', '+10 days');

        return [
            'project_id' => Project::factory(),
            'title' => ucfirst(fake()->words(4, true)),
            'description' => fake()->sentence(),
            'status' => TaskStatus::Todo,
            'priority' => fake()->randomElement(TaskPriority::cases()),
            'start_date' => $start,
            'due_date' => fake()->dateTimeBetween($start, '+40 days'),
            'estimated_minutes' => fake()->randomElement([30, 60, 120, 240, 480]),
            'tracked_seconds' => 0,
            'position' => 0,
        ];
    }

    public function status(TaskStatus $status): static
    {
        return $this->state(fn (array $attributes): array => ['status' => $status]);
    }

    /**
     * Due before the given date and in a status that still owes work, so the overdue bucket
     * picks it up.
     */
    public function overdue(?Carbon $asOf = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'due_date' => ($asOf ?? Carbon::today())->copy()->subDays(3),
            'status' => TaskStatus::InProgress,
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes): array => ['archived_at' => now()]);
    }

    /**
     * Assign the task. The FIRST employee given is the primary — the one whose work summary
     * completion needs — and a partial unique index makes a second primary impossible.
     */
    public function assignedTo(Employee ...$employees): static
    {
        return $this->afterCreating(function (Task $task) use ($employees): void {
            foreach ($employees as $index => $employee) {
                $task->assignees()->syncWithoutDetaching([
                    $employee->id => ['is_primary' => $index === 0],
                ]);
            }
        });
    }
}
