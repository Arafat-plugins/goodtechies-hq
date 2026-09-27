<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\PayrollItem;
use App\Models\PayrollPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayrollItem>
 */
class PayrollItemFactory extends Factory
{
    /**
     * $1,000 base, $100 allowance, nothing else — so the net is $1,100 and every state a test
     * adds to it is visible in the difference.
     *
     * **`net_salary` is not here and cannot be.** It is a PostgreSQL generated column; writing
     * it is an error from the database. `->refresh()` after any state change is how a test reads
     * the new one, and `PayrollService` does that for its own callers.
     *
     * `admin_notes` defaults to null, because the field is Part D §14's *"personal notes"* and
     * a factory that invented one would make the privacy tests pass on a value nobody chose.
     * `->noted()` sets it where a test is about it.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'payroll_period_id' => PayrollPeriod::factory(),
            'employee_id' => Employee::factory(),
            'base_salary' => '1000.00',
            'allowance' => '100.00',
            'bonus' => '0.00',
            'deduction' => '0.00',
            'advance' => '0.00',
            'leave_impact' => '0.00',
            'admin_notes' => null,
        ];
    }

    public function in(PayrollPeriod $period): static
    {
        return $this->state(fn (): array => ['payroll_period_id' => $period->getKey()]);
    }

    public function forEmployee(Employee $employee): static
    {
        return $this->state(fn (): array => ['employee_id' => $employee->getKey()]);
    }

    /** Money is a string here, never a float — see the migration. */
    public function of(string $baseSalary, string $allowance = '100.00'): static
    {
        return $this->state(fn (): array => [
            'base_salary' => $baseSalary,
            'allowance' => $allowance,
        ]);
    }

    public function noted(string $notes): static
    {
        return $this->state(fn (): array => ['admin_notes' => $notes]);
    }
}
