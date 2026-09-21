<?php

namespace Database\Factories;

use App\Models\Client;
use App\Support\ClientStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'contact_info' => $this->contacts(),
            'internal_notes' => fake()->sentence(),
            'status' => ClientStatus::Active,
        ];
    }

    /**
     * One or two invented contact people for the client.
     *
     * @return list<array{name: string, role: string, email: string, phone: string}>
     */
    private function contacts(): array
    {
        return collect(range(1, fake()->numberBetween(1, 2)))
            ->map(fn () => [
                'name' => fake()->name(),
                'role' => fake()->jobTitle(),
                'email' => fake()->unique()->safeEmail(),
                'phone' => fake()->phoneNumber(),
            ])
            ->all();
    }

    /**
     * Indicate that the client is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ClientStatus::Inactive,
        ]);
    }
}
