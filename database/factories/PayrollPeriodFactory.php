<?php

namespace Database\Factories;

use App\Models\PayrollPeriod;
use App\Support\PayrollStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<PayrollPeriod>
 */
class PayrollPeriodFactory extends Factory
{
    /**
     * The current month, in Draft.
     *
     * **A period is born Draft here, exactly as it is in the application.** The status guard on
     * the model allows `status` to be written only on a row that does not exist yet, so a
     * factory state that set `locked` would work — and a test using it would be asserting
     * against a month that never went through the machine. `->at()` below is how a test gets a
     * locked period: it walks the transitions, so the row it leaves behind is one the
     * application could have produced, `locked_at` and all.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'month' => Carbon::today()->startOfMonth(),
            'status' => PayrollStatus::Draft,
        ];
    }

    public function forMonth(CarbonInterface|string $month): static
    {
        return $this->state(fn (): array => [
            'month' => PayrollPeriod::monthKey($month),
        ]);
    }

    /**
     * A period that has been walked to this status through the real state machine.
     *
     * Every intermediate transition is applied in order, so `locked_at` is stamped by
     * `applyTransition()` and `payroll_periods_lock_is_whole` is satisfied by the same code path
     * the application uses. A status that is not reachable forwards from Draft throws, which is
     * the factory telling a test that it is asking for a row that cannot exist.
     */
    public function at(PayrollStatus $status): static
    {
        return $this->afterCreating(function (PayrollPeriod $period) use ($status): void {
            $forward = [
                PayrollStatus::Draft,
                PayrollStatus::Calculated,
                PayrollStatus::Reviewed,
                PayrollStatus::Approved,
                PayrollStatus::Locked,
                PayrollStatus::Paid,
            ];

            foreach ($forward as $step) {
                if ($period->status === $status) {
                    return;
                }

                if ($period->status->canTransitionTo($step)) {
                    $period->applyTransition($step)->save();
                }
            }

            if ($period->status !== $status) {
                throw new \RuntimeException(sprintf(
                    '%s is not reachable from Draft by going forwards.',
                    $status->value,
                ));
            }
        });
    }
}
