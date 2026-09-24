<?php

namespace Database\Factories;

use App\Models\LeaveType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LeaveType>
 */
class LeaveTypeFactory extends Factory
{
    /**
     * A capped, paid type — the shape four of the six seeded types have.
     *
     * The name is made unique because `leave_types.name` is unique and a test that creates two
     * types without caring what they are called should not fail on a collision.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Leave '.Str::upper(Str::random(6)),
            'has_balance' => true,
            'is_unpaid' => false,
            'position' => 0,
        ];
    }

    public function named(string $name): static
    {
        return $this->state(fn (): array => ['name' => $name]);
    }

    /** No cap: a request against it is never refused for want of days. */
    public function uncapped(): static
    {
        return $this->state(fn (): array => ['has_balance' => false]);
    }

    /**
     * Unpaid: every day lands in `leave_requests.unpaid_days`.
     *
     * Uncapped as well, because that is what Unpaid is on the seed — but the two are set
     * separately here, exactly as they are two columns, so a test can make an unpaid type that
     * IS capped and prove the code does not infer one from the other.
     */
    public function unpaid(): static
    {
        return $this->state(fn (): array => ['has_balance' => false, 'is_unpaid' => true]);
    }
}
