<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Support\RoleName;
use App\Support\TrackingMode;
use App\Support\UserStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'employee_number' => 'EMP-'.fake()->unique()->numerify('######'),
            'role_id' => fn (): int => Role::firstOrCreate(['name' => RoleName::EMPLOYEE->value])->id,
            'employment_type' => 'full_time',
            'tracking_mode' => TrackingMode::OfficeAttendance,
            'status' => UserStatus::Active,
        ];
    }

    /**
     * Give the employee a role and the tracking mode that goes with it.
     */
    public function forRole(RoleName $role): static
    {
        return $this->state(fn (array $attributes) => [
            'role_id' => Role::firstOrCreate(['name' => $role->value])->id,
            'tracking_mode' => match ($role) {
                RoleName::REMOTE_EMPLOYEE => TrackingMode::RemoteTimer,
                RoleName::ACCOUNTANT => TrackingMode::None,
                default => TrackingMode::OfficeAttendance,
            },
        ]);
    }
}
