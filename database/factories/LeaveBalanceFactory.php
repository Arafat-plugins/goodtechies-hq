<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeaveBalance>
 */
class LeaveBalanceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'leave_type_id' => LeaveType::factory(),
            'balance_days' => 10,
        ];
    }

    public function forEmployee(Employee $employee): static
    {
        return $this->state(fn (): array => ['employee_id' => $employee->getKey()]);
    }

    public function ofType(LeaveType $type): static
    {
        return $this->state(fn (): array => ['leave_type_id' => $type->getKey()]);
    }

    public function days(int $days): static
    {
        return $this->state(fn (): array => ['balance_days' => $days]);
    }
}
