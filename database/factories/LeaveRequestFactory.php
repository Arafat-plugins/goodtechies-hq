<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\LeaveStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<LeaveRequest>
 */
class LeaveRequestFactory extends Factory
{
    /**
     * A two-day pending request — the shape the acceptance walk uses.
     *
     * `days` is 2 rather than computed: a factory has no schedule to consult and must not
     * pretend to be `LeaveService::leaveDays()`. A test that cares about the count sets it, and
     * a test that cares about the counting goes through the service.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = Carbon::today()->addDay();

        return [
            'employee_id' => Employee::factory(),
            'leave_type_id' => LeaveType::factory(),
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->addDay()->toDateString(),
            'days' => 2,
            'unpaid_days' => 0,
            'reason' => 'Family commitment.',
            'status' => LeaveStatus::Pending,
            'approver_id' => null,
            'decided_at' => null,
            'decision_note' => null,
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

    /** A window, inclusive at both ends. `days` follows unless the caller overrides it. */
    public function between(CarbonInterface|string $from, CarbonInterface|string $to): static
    {
        return $this->state(function () use ($from, $to): array {
            $start = Carbon::parse($from)->startOfDay();
            $end = Carbon::parse($to)->startOfDay();

            return [
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'days' => (int) $start->diffInDays($end) + 1,
            ];
        });
    }

    public function on(CarbonInterface|string $date): static
    {
        return $this->between($date, $date)->state(fn (): array => ['days' => 1]);
    }

    /**
     * An approved request.
     *
     * It sets `status` in the INSERT, which the model's guard allows — a row that does not
     * exist yet has no status to move away from. There is no `withoutStatusGuard()` here and
     * there must not be: a test that wants the *approval* wants `LeaveService::approve()`, and
     * one that wants a row in the approved state, so that the absent sweep or the task flag has
     * something to find, wants this.
     */
    public function approved(?User $approver = null): static
    {
        return $this->state(fn (): array => [
            'status' => LeaveStatus::Approved,
            'approver_id' => $approver?->getKey(),
            'decided_at' => Carbon::now(),
        ]);
    }

    public function rejected(?User $approver = null, string $note = 'Too much on that week.'): static
    {
        return $this->state(fn (): array => [
            'status' => LeaveStatus::Rejected,
            'approver_id' => $approver?->getKey(),
            'decided_at' => Carbon::now(),
            'decision_note' => $note,
        ]);
    }

    public function correctionRequested(?User $approver = null, string $note = 'Which dates exactly?'): static
    {
        return $this->state(fn (): array => [
            'status' => LeaveStatus::CorrectionRequested,
            'approver_id' => $approver?->getKey(),
            'decided_at' => Carbon::now(),
            'decision_note' => $note,
        ]);
    }

    /** Unpaid days, as Phase 9 will read them. */
    public function unpaid(int $days): static
    {
        return $this->state(fn (): array => ['unpaid_days' => $days]);
    }
}
