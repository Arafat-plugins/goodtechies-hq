<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Project;
use App\Models\ProjectFinance;
use App\Support\BillingType;
use App\Support\Priority;
use App\Support\ProjectStatus;
use App\Support\ProjectType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = fake()->dateTimeBetween('now', '+30 days');

        return [
            'client_id' => Client::factory(),
            'name' => fake()->catchPhrase(),
            'domain' => fake()->domainWord().'.com',
            'project_type' => fake()->randomElement(ProjectType::cases()),
            'billing_type' => fake()->randomElement(BillingType::cases()),
            'start_date' => $start,
            'deadline' => fake()->dateTimeBetween($start, '+90 days'),
            'status' => ProjectStatus::Active,
            'priority' => fake()->randomElement(Priority::cases()),
            'internal_notes' => fake()->sentence(),
        ];
    }

    /**
     * Attach the project to a given client.
     */
    public function forClient(Client $client): static
    {
        return $this->state(fn (array $attributes) => [
            'client_id' => $client->id,
        ]);
    }

    /**
     * An internal project: no client, type Internal.
     */
    public function internal(): static
    {
        return $this->state(fn (array $attributes) => [
            'client_id' => null,
            'project_type' => ProjectType::Internal,
        ]);
    }

    /**
     * Indicate that the project is archived.
     */
    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProjectStatus::Archived,
            'archived_at' => now(),
        ]);
    }

    /**
     * Indicate that the project is cancelled.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProjectStatus::Cancelled,
        ]);
    }

    /**
     * Create the project's finance row alongside it.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function withFinance(array $overrides = []): static
    {
        return $this->afterCreating(function (Project $project) use ($overrides) {
            ProjectFinance::factory()->for($project)->create($overrides);
        });
    }

    /**
     * Attach the given employees as project members.
     */
    public function withMembers(Employee ...$employees): static
    {
        return $this->afterCreating(function (Project $project) use ($employees) {
            foreach ($employees as $employee) {
                $project->members()->syncWithoutDetaching([$employee->id]);
            }
        });
    }
}
