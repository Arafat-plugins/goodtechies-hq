<?php

namespace Database\Factories;

use App\Models\User;
use App\Support\UserStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'timezone' => 'Asia/Dhaka',
            'status' => UserStatus::Active,
        ];
    }

    /**
     * Indicate that the user has confirmed two-factor authentication with the given secret.
     */
    public function withTwoFactor(string $secret): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => array_map(fn (): string => Hash::make(Str::random(10)), range(1, 8)),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
