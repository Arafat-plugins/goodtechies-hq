<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\Project;
use App\Models\RecurringTask;
use App\Support\RecurrenceRule;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<RecurringTask>
 */
class RecurringTaskFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $rule = RecurrenceRule::monthly();

        return [
            'project_id' => Project::factory(),
            // `{period}` by default so a factory-made template produces distinguishable titles
            // across two months, which is what almost every test of this engine is checking.
            'title_template' => ucfirst(fake()->words(3, true)).' — {period}',
            'checklist_template' => null,
            'frequency' => $rule->frequency,
            'recurrence_rule' => $rule->toArray(),
            'default_assignee_id' => null,
            'created_by' => null,
            'active' => true,
            'next_run_at' => $rule->nextRunAt(Carbon::today()),
        ];
    }

    public function rule(RecurrenceRule $rule): static
    {
        return $this->state(fn (array $attributes): array => [
            'frequency' => $rule->frequency,
            'recurrence_rule' => $rule->toArray(),
            'next_run_at' => $rule->nextRunAt(Carbon::today()),
        ]);
    }

    public function assignedTo(Employee $employee): static
    {
        return $this->state(fn (array $attributes): array => ['default_assignee_id' => $employee->id]);
    }

    /**
     * @param  list<string>  $items
     */
    public function withChecklist(array $items): static
    {
        return $this->state(fn (array $attributes): array => ['checklist_template' => $items]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => ['active' => false]);
    }
}
