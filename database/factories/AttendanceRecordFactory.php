<?php

namespace Database\Factories;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Support\AttendanceStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<AttendanceRecord>
 */
class AttendanceRecordFactory extends Factory
{
    /**
     * A plain on-time office day. Every state below changes one thing about it, so a test that
     * says `->late()` is saying exactly what it is testing and nothing else.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $date = Carbon::today();

        return [
            'employee_id' => Employee::factory(),
            'date' => $date->toDateString(),
            'clock_in' => $date->copy()->setTime(8, 58),
            'clock_out' => $date->copy()->setTime(17, 2),
            'status' => AttendanceStatus::Present,
            'note' => null,
            'edited_by' => null,
        ];
    }

    public function forEmployee(Employee $employee): static
    {
        return $this->state(fn (): array => ['employee_id' => $employee->getKey()]);
    }

    public function on(CarbonInterface $date): static
    {
        return $this->state(function (array $attributes) use ($date): array {
            $day = Carbon::parse($date)->startOfDay();

            return [
                'date' => $day->toDateString(),
                'clock_in' => $attributes['clock_in'] === null
                    ? null
                    : $day->copy()->setTimeFrom(Carbon::parse($attributes['clock_in'])),
                'clock_out' => $attributes['clock_out'] === null
                    ? null
                    : $day->copy()->setTimeFrom(Carbon::parse($attributes['clock_out'])),
            ];
        });
    }

    /** Still in the office: clocked in, no clock-out. */
    public function open(): static
    {
        return $this->state(fn (): array => ['clock_out' => null]);
    }

    public function late(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => AttendanceStatus::Late,
            'clock_in' => Carbon::parse($attributes['date'])->setTime(9, 40),
        ]);
    }

    /** The sweep's row: a working day with nobody in it and no times at all. */
    public function absent(): static
    {
        return $this->state(fn (): array => [
            'status' => AttendanceStatus::Absent,
            'clock_in' => null,
            'clock_out' => null,
        ]);
    }
}
