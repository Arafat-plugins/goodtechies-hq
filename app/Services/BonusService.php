<?php

namespace App\Services;

use App\Exceptions\PayrollStateException;
use App\Models\Employee;
use App\Models\EmployeeBonus;
use App\Models\PayrollItem;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Support\AuditEvent;
use App\Support\BonusPayout;
use App\Support\BonusStatus;
use App\Support\BonusType;
use App\Support\Currency;
use App\Support\PayrollStatus;
use App\Support\Permission;
use App\Support\UserStatus;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Bonuses, and the one writer of `payroll_items.bonus`.
 *
 * A bonus is created pending or approved, can be edited or cancelled while pending or approved,
 * and becomes paid when its payroll month is marked paid. An approved bonus is linked to the
 * payroll period of its payment month, and `syncPeriodFor()` writes the sum of the linked
 * approved/paid bonuses into each item of that period. The sum is recomputed from the records
 * every time, so running it twice changes nothing, and a locked or paid month is never touched.
 */
class BonusService
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * One bonus per employee. An identical non-cancelled bonus already on record for an
     * employee (same type, title, amount and payment date) skips that employee.
     *
     * @param  list<int>  $employeeIds
     * @param  array<string, mixed>  $attributes
     * @return array{created: Collection<int, EmployeeBonus>, skipped: list<string>}
     *
     * @throws AuthorizationException
     * @throws PayrollStateException
     */
    public function create(User $actor, array $employeeIds, array $attributes): array
    {
        if (! Gate::forUser($actor)->allows('create', EmployeeBonus::class)) {
            throw new AuthorizationException('You are not allowed to add bonuses.');
        }

        $ids = array_values(array_unique(array_map('intval', $employeeIds)));

        $employees = Employee::query()->with('user')->whereIn('id', $ids)->get()->keyBy('id');

        foreach ($ids as $id) {
            $employee = $employees->get($id);

            if ($employee === null || $employee->status !== UserStatus::Active) {
                throw PayrollStateException::bonusEmployeeNotActive(
                    $employee?->user?->name ?? ('Employee #'.$id),
                );
            }
        }

        $paymentDate = Carbon::parse((string) $attributes['payment_date'])->startOfDay();
        $payout = BonusPayout::tryFrom((string) ($attributes['payout'] ?? '')) ?? BonusPayout::WithPayroll;

        // A bonus paid separately never touches a payroll month, so a closed month does not stop it.
        if ($payout === BonusPayout::WithPayroll) {
            $this->assertMonthIsOpen($paymentDate, $actor);
        }

        $type = BonusType::from((string) $attributes['type']);
        $title = $type === BonusType::Custom ? $this->cleanTitle($attributes['title'] ?? null) : null;
        $amount = (string) $attributes['amount'];
        $mayApprove = Gate::forUser($actor)->allows('approve', EmployeeBonus::class);
        $asked = $attributes['status'] ?? null;

        $status = match (true) {
            $payout === BonusPayout::Separate && $asked === BonusStatus::Paid->value && $mayApprove => BonusStatus::Paid,
            $asked === BonusStatus::Approved->value && $mayApprove => BonusStatus::Approved,
            default => BonusStatus::Pending,
        };
        $approveNow = $status !== BonusStatus::Pending;

        return DB::transaction(function () use ($actor, $ids, $employees, $attributes, $paymentDate, $type, $title, $amount, $payout, $status, $approveNow): array {
            $created = new Collection;
            $skipped = [];

            // Serialise simultaneous identical submits so the duplicate check sees the first.
            Employee::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get(['id']);

            foreach ($ids as $id) {
                $duplicate = EmployeeBonus::query()
                    ->where('employee_id', $id)
                    ->where('type', $type->value)
                    ->where(fn ($query) => $title === null ? $query->whereNull('title') : $query->where('title', $title))
                    ->where('amount', $amount)
                    ->whereDate('payment_date', $paymentDate->toDateString())
                    ->where('payout', $payout->value)
                    ->where('status', '!=', BonusStatus::Cancelled->value)
                    ->exists();

                if ($duplicate) {
                    $skipped[] = (string) ($employees->get($id)?->user?->name ?? ('Employee #'.$id));

                    continue;
                }

                $bonus = EmployeeBonus::create([
                    'employee_id' => $id,
                    'type' => $type,
                    'title' => $title,
                    'amount' => $amount,
                    'currency' => strtoupper((string) $attributes['currency']),
                    'payment_date' => $paymentDate->toDateString(),
                    'reason' => $this->cleanText($attributes['reason'] ?? null),
                    'payout' => $payout,
                    'status' => $status,
                    'created_by' => $actor->getKey(),
                    'approved_by' => $approveNow ? $actor->getKey() : null,
                    'approved_at' => $approveNow ? now() : null,
                    'paid_at' => $status === BonusStatus::Paid ? now() : null,
                ]);

                $this->audit->record(AuditEvent::BonusCreated, $bonus, null, $bonus->auditValues(), $actor);

                $created->push($bonus);
            }

            if ($payout === BonusPayout::WithPayroll) {
                $this->syncPeriodFor($paymentDate);
            }

            return [
                'created' => $created->map(fn (EmployeeBonus $bonus): EmployeeBonus => $bonus->refresh()),
                'skipped' => $skipped,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws AuthorizationException
     * @throws PayrollStateException
     */
    public function update(User $actor, EmployeeBonus $bonus, array $attributes): EmployeeBonus
    {
        if (! Gate::forUser($actor)->allows('update', $bonus)) {
            throw new AuthorizationException('You are not allowed to edit this bonus.');
        }

        $oldDate = Carbon::parse($bonus->payment_date)->startOfDay();
        $newDate = Carbon::parse((string) $attributes['payment_date'])->startOfDay();

        $oldPayout = $bonus->payout ?? BonusPayout::WithPayroll;
        $newPayout = BonusPayout::tryFrom((string) ($attributes['payout'] ?? '')) ?? $oldPayout;
        $oldOnPayroll = $oldPayout === BonusPayout::WithPayroll;
        $newOnPayroll = $newPayout === BonusPayout::WithPayroll;

        if ($oldOnPayroll) {
            $this->assertMonthIsOpen($oldDate, $actor);
        }

        if ($newOnPayroll) {
            $this->assertMonthIsOpen($newDate, $actor);
        }

        $type = BonusType::from((string) $attributes['type']);

        return DB::transaction(function () use ($actor, $bonus, $attributes, $oldDate, $newDate, $type, $newPayout, $oldOnPayroll, $newOnPayroll): EmployeeBonus {
            Employee::query()->whereKey($bonus->employee_id)->lockForUpdate()->get(['id']);

            $old = $bonus->auditValues();

            $bonus->fill([
                'type' => $type,
                'title' => $type === BonusType::Custom ? $this->cleanTitle($attributes['title'] ?? null) : null,
                'amount' => (string) $attributes['amount'],
                'currency' => strtoupper((string) $attributes['currency']),
                'payment_date' => $newDate->toDateString(),
                'reason' => $this->cleanText($attributes['reason'] ?? null),
                'payout' => $newPayout,
            ]);

            if (! $newOnPayroll) {
                $bonus->payroll_period_id = null;
            }

            $bonus->save();

            $this->audit->record(AuditEvent::BonusEdited, $bonus, $old, $bonus->auditValues(), $actor);

            if ($oldOnPayroll) {
                $this->syncPeriodFor($oldDate);
            }

            if ($newOnPayroll && (! $oldOnPayroll || ! PayrollPeriod::monthKey($oldDate)->equalTo(PayrollPeriod::monthKey($newDate)))) {
                $this->syncPeriodFor($newDate);
            }

            return $bonus->refresh();
        });
    }

    /**
     * @throws AuthorizationException
     * @throws PayrollStateException
     */
    public function approve(User $actor, EmployeeBonus $bonus): EmployeeBonus
    {
        if (! Gate::forUser($actor)->allows('approve', $bonus)) {
            throw new AuthorizationException('You are not allowed to approve bonuses.');
        }

        if ($bonus->status !== BonusStatus::Pending) {
            throw PayrollStateException::bonusNotPending($bonus->status->label());
        }

        $date = Carbon::parse($bonus->payment_date)->startOfDay();
        $onPayroll = $bonus->payout !== BonusPayout::Separate;

        if ($onPayroll) {
            $this->assertMonthIsOpen($date, $actor);
        }

        return DB::transaction(function () use ($actor, $bonus, $date, $onPayroll): EmployeeBonus {
            $old = $bonus->auditValues();

            $bonus->fill([
                'status' => BonusStatus::Approved,
                'approved_by' => $actor->getKey(),
                'approved_at' => now(),
            ])->save();

            $this->audit->record(AuditEvent::BonusApproved, $bonus, $old, $bonus->auditValues(), $actor);

            if ($onPayroll) {
                $this->syncPeriodFor($date);
            }

            return $bonus->refresh();
        });
    }

    /**
     * @throws AuthorizationException
     * @throws PayrollStateException
     */
    public function cancel(User $actor, EmployeeBonus $bonus, string $reason): EmployeeBonus
    {
        if (! Gate::forUser($actor)->allows('cancel', $bonus)) {
            throw new AuthorizationException('You are not allowed to cancel this bonus.');
        }

        $clean = trim($reason);

        if ($clean === '') {
            throw PayrollStateException::bonusCancellationNeedsReason();
        }

        $date = Carbon::parse($bonus->payment_date)->startOfDay();
        $onPayroll = $bonus->payout !== BonusPayout::Separate;

        if ($onPayroll) {
            $this->assertMonthIsOpen($date, $actor);
        }

        return DB::transaction(function () use ($actor, $bonus, $clean, $date, $onPayroll): EmployeeBonus {
            $old = $bonus->auditValues();

            $bonus->fill([
                'status' => BonusStatus::Cancelled,
                'cancelled_by' => $actor->getKey(),
                'cancelled_at' => now(),
                'cancellation_reason' => $clean,
                'payroll_period_id' => null,
            ])->save();

            $this->audit->record(AuditEvent::BonusCancelled, $bonus, $old, $bonus->auditValues(), $actor);

            if ($onPayroll) {
                $this->syncPeriodFor($date);
            }

            return $bonus->refresh();
        });
    }

    /**
     * Pay an approved bonus that is paid separately, today. It never touches a payroll month.
     *
     * @throws AuthorizationException
     * @throws PayrollStateException
     */
    public function markPaid(User $actor, EmployeeBonus $bonus): EmployeeBonus
    {
        if ($bonus->payout !== BonusPayout::Separate || $bonus->status !== BonusStatus::Approved) {
            throw PayrollStateException::bonusNotPayableSeparately();
        }

        if (! Gate::forUser($actor)->allows('markPaid', $bonus)) {
            throw new AuthorizationException('You are not allowed to mark this bonus paid.');
        }

        return DB::transaction(function () use ($actor, $bonus): EmployeeBonus {
            $old = $bonus->auditValues();

            $bonus->fill([
                'status' => BonusStatus::Paid,
                'paid_at' => now(),
            ])->save();

            $this->audit->record(AuditEvent::BonusPaid, $bonus, $old, $bonus->auditValues(), $actor);

            return $bonus->refresh();
        });
    }

    /**
     * Link this month's approved bonuses to its payroll period and write each item's bonus as
     * the sum of its linked approved/paid bonuses. Idempotent; a missing, locked or paid month
     * is left alone.
     */
    public function syncPeriodFor(CarbonInterface $date): void
    {
        $key = PayrollPeriod::monthKey($date);

        DB::transaction(function () use ($key): void {
            $period = PayrollPeriod::query()->whereDate('month', $key->toDateString())->lockForUpdate()->first();

            if ($period === null || in_array($period->status->value, PayrollStatus::closingValues(), true)) {
                return;
            }

            PayrollItem::query()
                ->where('payroll_period_id', $period->getKey())
                ->lockForUpdate()
                ->get(['id']);

            $start = $key->toDateString();
            $end = $key->copy()->endOfMonth()->toDateString();
            $currency = Currency::default()->value;
            $periodId = (int) $period->getKey();

            // Link every approved bonus in this month, in the payroll currency, for an employee
            // with a line on this period.
            DB::update(
                "UPDATE employee_bonuses b
                    SET payroll_period_id = ?, updated_at = now()
                  WHERE b.status = ?
                    AND b.payout = 'with_payroll'
                    AND b.payment_date BETWEEN ? AND ?
                    AND b.currency = ?
                    AND b.payroll_period_id IS DISTINCT FROM ?
                    AND EXISTS (SELECT 1 FROM payroll_items i WHERE i.payroll_period_id = ? AND i.employee_id = b.employee_id)",
                [$periodId, BonusStatus::Approved->value, $start, $end, $currency, $periodId, $periodId],
            );

            // Unlink approved bonuses linked here that no longer belong.
            DB::update(
                "UPDATE employee_bonuses b
                    SET payroll_period_id = NULL, updated_at = now()
                  WHERE b.payroll_period_id = ?
                    AND b.status = ?
                    AND (b.payment_date NOT BETWEEN ? AND ?
                         OR b.payout <> 'with_payroll'
                         OR NOT EXISTS (SELECT 1 FROM payroll_items i WHERE i.payroll_period_id = ? AND i.employee_id = b.employee_id))",
                [$periodId, BonusStatus::Approved->value, $start, $end, $periodId],
            );

            DB::update(
                "UPDATE payroll_items i
                    SET bonus = COALESCE((
                        SELECT SUM(b.amount) FROM employee_bonuses b
                         WHERE b.payroll_period_id = i.payroll_period_id
                           AND b.employee_id = i.employee_id
                           AND b.status IN (?, ?)
                           AND b.payout = 'with_payroll'
                    ), 0),
                        updated_at = now()
                  WHERE i.payroll_period_id = ?",
                [BonusStatus::Approved->value, BonusStatus::Paid->value, $periodId],
            );
        });
    }

    /** The month was paid: its approved bonuses are paid with it. */
    public function markPaidForPeriod(PayrollPeriod $period): void
    {
        DB::update(
            "UPDATE employee_bonuses SET status = ?, paid_at = now(), updated_at = now() WHERE payroll_period_id = ? AND status = ? AND payout = 'with_payroll'",
            [BonusStatus::Paid->value, $period->getKey(), BonusStatus::Approved->value],
        );
    }

    /**
     * @throws PayrollStateException
     */
    private function assertMonthIsOpen(CarbonInterface $date, User $actor): void
    {
        $period = PayrollPeriod::query()
            ->whereDate('month', PayrollPeriod::monthKey($date)->toDateString())
            ->first();

        if ($period !== null && in_array($period->status->value, PayrollStatus::closingValues(), true)) {
            throw PayrollStateException::bonusMonthClosed($period);
        }

        if ($period !== null
            && ! $actor->hasPermission(Permission::PayrollApprove)
            && ! $period->status->isOpenToAccountant()) {
            throw PayrollStateException::bonusMonthApproved($period);
        }
    }

    private function cleanTitle(mixed $title): ?string
    {
        $clean = trim((string) $title);

        return $clean === '' ? null : $clean;
    }

    private function cleanText(mixed $text): ?string
    {
        $clean = trim((string) $text);

        return $clean === '' ? null : $clean;
    }
}
