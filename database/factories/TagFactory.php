<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tag>
 */
class TagFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Global by default: the common case is a tag every project can use.
            'project_id' => null,
            'name' => fake()->unique()->word(),
            // A token name, never a hex value — colour lives in app.css.
            'colour' => fake()->randomElement(['progress', 'review', 'done', 'waiting', 'backlog', 'changes']),
        ];
    }

    /**
     * Scope the tag to one project.
     */
    public function forProject(Project $project): static
    {
        return $this->state(fn (array $attributes): array => [
            'project_id' => $project->id,
        ]);
    }
}
