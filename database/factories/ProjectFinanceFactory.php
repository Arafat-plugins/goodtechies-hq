<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\ProjectFinance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectFinance>
 */
class ProjectFinanceFactory extends Factory
{
    protected $model = ProjectFinance::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'price' => fake()->randomFloat(2, 500, 10000),
            'recurring_amount' => null,
            'billing_frequency' => null,
            'contract_value' => null,
            'contract_terms' => fake()->sentence(),
            'profitability_snapshot' => null,
        ];
    }
}
