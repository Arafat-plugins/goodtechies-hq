<?php

namespace Database\Factories;

use App\Models\Holiday;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Holiday>
 */
class HolidayFactory extends Factory
{
    /**
     * A named day off today. Every test that cares about the date says so with `on()`, so a
     * test reads as the thing it is testing and nothing else.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'date' => Carbon::today()->toDateString(),
            'name' => 'Company holiday',
        ];
    }

    public function on(CarbonInterface $date): static
    {
        return $this->state(fn (): array => ['date' => Carbon::parse($date)->toDateString()]);
    }

    public function named(string $name): static
    {
        return $this->state(fn (): array => ['name' => $name]);
    }
}
