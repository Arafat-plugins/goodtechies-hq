<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\EmployeeSalary;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<EmployeeSalary>
 */
class EmployeeSalaryFactory extends Factory
{
    /**
     * $1,000 base and $100 allowance, effective from the first of the current month.
     *
     * **The default date is the first of a month on purpose.** The one question this table
     * answers is *"what was in force on this date"*, and a factory whose rows landed on random
     * days would make every history test read as arithmetic about the 17th rather than about
     * September. `->effectiveFrom()` is one call away for the tests that are about a mid-month
     * change.
     *
     * Money is a string here, never a float — see the migration.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'base_salary' => '1000.00',
            'allowance' => '100.00',
            'effective_from' => Carbon::today()->startOfMonth(),
            'set_by' => User::factory(),
        ];
    }

    public function forEmployee(Employee $employee): static
    {
        return $this->state(fn (): array => ['employee_id' => $employee->getKey()]);
    }

    public function of(string $baseSalary, string $allowance = '100.00'): static
    {
        return $this->state(fn (): array => [
            'base_salary' => $baseSalary,
            'allowance' => $allowance,
        ]);
    }

    public function effectiveFrom(CarbonInterface|string $date): static
    {
        return $this->state(fn (): array => [
            'effective_from' => $date instanceof CarbonInterface ? Carbon::instance($date) : Carbon::parse($date),
        ]);
    }

    public function setBy(User $user): static
    {
        return $this->state(fn (): array => ['set_by' => $user->getKey()]);
    }
}
