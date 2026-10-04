<?php

namespace App\Services;

use App\Exceptions\PayrollStateException;
use App\Models\Employee;
use App\Models\EmployeeSalary;
use App\Models\LeaveRequest;
use App\Models\PayrollItem;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Support\AuditEvent;
use App\Support\LeaveStatus;
use App\Support\PayrollStatus;
use App\Support\UserStatus;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Everything that happens to somebody's pay (master prompt Part D §14).
 *
 * One door in, like every other service here. The payroll screens, `hq:create-payroll-draft`,
 * `PayrollSeeder` and Phase 10's Payroll report all come through these methods, so the
 * authorization, the state machine and the audit trail cannot be half-applied by a second path.
 *
 * ## The state machine, and why every refusal is a sentence
 *
 * `DRAFT → CALCULATED → REVIEWED → APPROVED → LOCKED → PAID`, plus the one backward move —
 * `LOCKED → APPROVED`, the ADMIN-only lock reversal. The map is `PayrollStatus::TRANSITIONS`
 * and the only door through it is `PayrollPeriod::applyTransition()`, which the model's guard
 * enforces (decision 2-9, a fifth time).
 *
 * **Nothing here ever refuses by doing nothing.** Every method below either does the thing or
 * throws — `AuthorizationException` when it is about the person, `PayrollStateException` with a
 * sentence when it is about the period's state. A state machine that quietly no-ops produces
 * *"I pressed Approve and nothing happened"*, which cannot be debugged from a bug report,
 * cannot be told apart from a dead button, and leaves no log line behind. The sentences name
 * the status the period is actually in and the moves that are available from it, because that
 * is the one fact the person at the screen does not have.
 *
 * ## Money is never a float, and the one division is integer cents
 *
 * Every column is `decimal(12,2)`, every value travels as a string, and `net_salary` is a
 * PostgreSQL **generated column** — exact `numeric`, computed on write, impossible to
 * contradict its own parts (see the migration). Nothing in PHP computes a net.
 *
 * The one place arithmetic happens in PHP is `leaveImpactCents()`, because it divides, and it
 * divides in **whole cents with integer operations only** — no float appears at any step, not
 * even transiently. `(int) (8.6 * 100)` is 859 on a binary float, and that error, multiplied by
 * a day count, is somebody's pay.
 *
 * ## Privacy: the scope, the 404, and the audit row
 *
 * Part B §3 rule 2 — *"every payroll/salary query defaults to `employee_id =
 * current_user.employee_id` unless the requester's role is explicitly ADMIN or ACCOUNTANT"* —
 * and Phase 9's own security list, which spells out three separate things:
 *
 *   1. **omission** — `PayrollItem::scopeVisibleTo()`, asked by `itemsFor()` below, so another
 *      employee's line is simply not in the list;
 *   2. **404, never 403** — `findItemFor()` resolves through that same scope, so a line the
 *      caller may not see is *not found*; a 403 would confirm it exists (Part B §3 rule 1);
 *   3. **an audit row** — `access.restricted_attempt`, written by `findItemFor()` when the row
 *      exists and is somebody else's, which is the only case where an attempt happened.
 *
 * `PayrollPrivacyTest` has one test per part.
 *
 * ## Nothing here compares one person's pay with another's
 *
 * No totals across people that anybody but an Admin can reach, no ranking, no "cost per
 * employee" league table (Part H §1 — no productivity scoring, and nothing in this service
 * counts tasks, hours or output for any purpose whatsoever). A payroll item is a number for one
 * person for one month, and the only thing that changes it is a salary, a figure an Accountant
 * typed, and approved unpaid leave.
 */
class PayrollService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly LeaveService $leave,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Salaries, with history
    |--------------------------------------------------------------------------
    */

    /**
     * What this employee was being paid on this date — the latest `employee_salaries` row at or
     * before it, or null if they had none yet.
     *
     * **This is the method the "old month recalculates to its old number" promise rests on.** A
     * raise dated 2026-11-01 is invisible to `salaryFor($tapu, '2026-09-01')` because the query
     * never reaches it. See the `employee_salaries` migration for the full argument against the
     * alternative (a mutable current row plus the audit log).
     */
    public function salaryFor(Employee $employee, CarbonInterface|string $date): ?EmployeeSalary
    {
        return EmployeeSalary::query()->inForceOn($employee, $date)->first();
    }

    /**
     * Set somebody's salary from a date. Part D §14's *"salary settings per employee (base
     * salary, allowances — **audit-logged**)"*.
     *
     * A new row per change (see the migration) — except on the same `effective_from`, where
     * `employee_salaries_one_per_day` makes it the same row and this becomes a correction of a
     * figure that has not started applying to a different day yet. Either way one
     * `salary.changed` row is written, with the previous figures as `old` and the new ones as
     * `new`, inside the same transaction as the write: an audit row without its change, or a
     * change without its audit row, are both impossible rather than unlikely.
     *
     * The old value is the salary in force **the day before** this one starts, not the row this
     * insert happens to replace — because *"what were they on before this?"* is the question a
     * reader of the audit log is asking, and on a correction to today's figure the two answers
     * differ.
     *
     * @throws AuthorizationException
     */
    public function setSalary(
        User $actor,
        Employee $employee,
        string $baseSalary,
        string $allowance = '0.00',
        CarbonInterface|string|null $effectiveFrom = null,
    ): EmployeeSalary {
        if (! Gate::forUser($actor)->allows('create', [EmployeeSalary::class, $employee])) {
            throw new AuthorizationException('You are not allowed to set salaries.');
        }

        $from = Carbon::parse($effectiveFrom ?? Carbon::today())->startOfDay();

        return DB::transaction(function () use ($actor, $employee, $baseSalary, $allowance, $from): EmployeeSalary {
            $previous = $this->salaryFor($employee, $from->copy()->subDay());

            $salary = EmployeeSalary::query()->updateOrCreate(
                [
                    'employee_id' => $employee->getKey(),
                    'effective_from' => $from->toDateString(),
                ],
                [
                    'base_salary' => $baseSalary,
                    'allowance' => $allowance,
                    'set_by' => $actor->getKey(),
                ],
            );

            $this->audit->record(
                AuditEvent::SalaryChanged,
                $salary,
                $previous?->auditValues(),
                $salary->fresh(['employee.user'])->auditValues(),
                $actor,
            );

            return $salary;
        });
    }

    /**
     * Remove one salary row entered by mistake (polish 002).
     *
     * A hard delete, audited as `salary.deleted` with the whole row as `old`, in one
     * transaction. Payroll items already drafted copied their figures when they were drafted,
     * so no past month changes; the next draft reads whichever row is now in force.
     */
    public function deleteSalary(User $actor, EmployeeSalary $salary): void
    {
        if (! Gate::forUser($actor)->allows('delete', $salary)) {
            throw new AuthorizationException('You are not allowed to delete salaries.');
        }

        DB::transaction(function () use ($actor, $salary): void {
            $old = $salary->loadMissing('employee.user')->auditValues();
            $id = (int) $salary->getKey();
            $type = $salary->getMorphClass();

            $salary->delete();

            $this->audit->recordFor(AuditEvent::SalaryDeleted, $type, $id, $old, null, $actor);
        });
    }

    /**
     * The month a payroll is drafted for by default — polish 005: the month just finished.
     * Pay follows the work, so on 1 October the payroll to prepare is September's.
     */
    public static function defaultDraftMonth(?CarbonInterface $today = null): Carbon
    {
        $today ??= Carbon::today(config('app.timezone'));

        return PayrollPeriod::monthKey(Carbon::parse($today)->subMonthNoOverflow());
    }

    /**
     * Move a DRAFT to the month it really pays for (polish 005), audited as
     * `payroll.month_changed`. The lines keep their figures; Calculate works the leave impact
     * out for the new month when it is pressed.
     *
     * @throws AuthorizationException
     * @throws PayrollStateException
     */
    public function changeMonth(User $actor, PayrollPeriod $period, CarbonInterface $month): PayrollPeriod
    {
        if (! Gate::forUser($actor)->allows('create', PayrollPeriod::class)) {
            throw new AuthorizationException('You are not allowed to change a payroll month.');
        }

        $key = PayrollPeriod::monthKey($month);

        return DB::transaction(function () use ($actor, $period, $key): PayrollPeriod {
            $period = PayrollPeriod::query()->lockForUpdate()->findOrFail($period->getKey());

            if ($period->status !== PayrollStatus::Draft) {
                throw PayrollStateException::monthIsSettled($period->status);
            }

            $from = PayrollPeriod::monthKey($period->month);

            if ($from->equalTo($key)) {
                return $period;
            }

            if (PayrollPeriod::query()->forMonth($key)->exists()) {
                throw PayrollStateException::monthAlreadyHasAPeriod($key->format('F Y'));
            }

            $period->forceFill(['month' => $key->toDateString()])->save();

            $this->audit->record(
                AuditEvent::PayrollMonthChanged,
                $period,
                ['month' => $from->toDateString()],
                ['month' => $key->toDateString()],
                $actor,
            );

            return $period->refresh();
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Drafting a month
    |--------------------------------------------------------------------------
    */

    /**
     * Create the month's period and one item per **active** employee from their current salary.
     *
     * Part D §14: *"Draft auto-created on the 1st for all active employees from
     * `employee_salaries` (current base + allowances, with history)"*.
     *
     * ## Idempotent twice over, and the database is what makes it so
     *
     * There is no `drafted_at` column and no cache lock. Decision 7-5 made that argument for
     * meeting reminders and every word of it transfers: a column is a second record of a fact
     * the table already holds and can disagree with it after a rollback or a restore, and Redis
     * is a cache here, not a system of record — a `FLUSHALL` would re-draft every month. **The
     * rows are the memory**, held by two unique indexes:
     *
     *   - `payroll_periods.month` is UNIQUE, so a month has exactly one period;
     *   - `payroll_items (payroll_period_id, employee_id)` is UNIQUE, so a person has exactly
     *     one line in it.
     *
     * So running this twice on the 1st adds nothing, and running it on a month that already has
     * a period does nothing at all — `$onlyIfMissing` returns the existing period untouched,
     * which is what the scheduled command passes. A caller that means *"make this month, and
     * tell me if it is already there"* passes `false` and gets a sentence.
     *
     * ## The actor may be null, and only the scheduler may make it so
     *
     * `hq:create-payroll-draft` runs from cron with nobody signed in. A scheduled command is not
     * a person and holds no permissions; the authorization for it is the schedule itself, which
     * is in `routes/console.php` where the client can read it. Every other caller passes an
     * actor and is checked against `payroll.draft`.
     *
     * @throws AuthorizationException
     * @throws PayrollStateException
     */
    public function createDraft(
        ?User $actor,
        CarbonInterface|string $month,
        bool $onlyIfMissing = false,
    ): PayrollPeriod {
        if ($actor !== null && ! Gate::forUser($actor)->allows('create', PayrollPeriod::class)) {
            throw new AuthorizationException('You are not allowed to draft payroll.');
        }

        $key = PayrollPeriod::monthKey($month);

        return DB::transaction(function () use ($key, $onlyIfMissing): PayrollPeriod {
            $existing = PayrollPeriod::query()->forMonth($key)->lockForUpdate()->first();

            if ($existing !== null) {
                if (! $onlyIfMissing) {
                    throw PayrollStateException::monthAlreadyHasAPeriod($key->format('F Y'));
                }

                // Deliberately does NOT top up the items. Part D says the draft is created on
                // the 1st; a re-run that quietly added a line for somebody hired on the 20th
                // would change a month an Accountant may already have balanced. Adding a
                // late joiner is `draftMissingItems()`, which somebody chooses to call.
                return $existing;
            }

            $period = PayrollPeriod::create([
                'month' => $key->toDateString(),
                'status' => PayrollStatus::Draft,
            ]);

            $this->draftMissingItems($period);

            return $period->refresh();
        });
    }

    /**
     * Give every active employee who has none a line on this period, from the salary in force
     * on the month's **first day**.
     *
     * ## Why the first day and not the last
     *
     * A raise dated mid-month applies from the month after. Three reasons, and the third is the
     * one that decides it:
     *
     *   1. The draft is created on the 1st (Part D §14) from *"current base + allowances"* —
     *      so the first day is the day the plan already reads the salary on.
     *   2. This application has no pro-ration, and inventing one here would be building ahead
     *      (Part H). Somewhere between the two dates the month has to pick a single figure, and
     *      *the one it started on* is the one the Accountant has been working with.
     *   3. **It makes drafting and re-drafting agree.** If the figure were read on the last day,
     *      a period drafted on the 1st and topped up on the 20th would hold two different base
     *      salaries for two people raised on the same day, and nothing on the screen would say
     *      why.
     *
     * Employees with no salary on record are **skipped, not defaulted to zero** — a zero would
     * be a payslip saying somebody earns nothing, which is a statement, where a missing line is
     * a question. The caller gets their names back so the command can print them.
     *
     * @return array{created: int, skipped: list<string>}
     */
    public function draftMissingItems(PayrollPeriod $period): array
    {
        $asAt = PayrollPeriod::monthKey($period->month);

        $already = PayrollItem::query()
            ->where('payroll_period_id', $period->getKey())
            ->pluck('employee_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $created = 0;
        $skipped = [];

        foreach ($this->activeEmployees() as $employee) {
            if (in_array((int) $employee->getKey(), $already, true)) {
                continue;
            }

            $salary = $this->salaryFor($employee, $asAt);

            if ($salary === null) {
                $skipped[] = (string) ($employee->user?->name ?? ('employee #'.$employee->getKey()));

                continue;
            }

            PayrollItem::create([
                'payroll_period_id' => $period->getKey(),
                'employee_id' => $employee->getKey(),
                'base_salary' => $salary->base_salary,
                'allowance' => $salary->allowance,
            ]);

            $created++;
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * Active employees with no salary on record as at this date — the people a draft cannot
     * include.
     *
     * A read, so that `hq:create-payroll-draft` can name them without calling the writer again.
     * It asks the same two questions `draftMissingItems()` does, in the same order, which is
     * why the two lists agree.
     *
     * @return list<string>
     */
    public function employeesWithoutSalaryAt(CarbonInterface|string $asAt): array
    {
        $names = [];

        foreach ($this->activeEmployees() as $employee) {
            if ($this->salaryFor($employee, $asAt) === null) {
                $names[] = (string) ($employee->user?->name ?? ('employee #'.$employee->getKey()));
            }
        }

        return $names;
    }

    /**
     * Everybody payroll is run for: Part D §14's *"all active employees"*.
     *
     * `employees.status`, not the role and not `tracking_mode`. Somebody the office clock does
     * not track is still paid — the Accountant is exactly that — and Part D §21 makes leaving
     * the company `status = inactive`, which is what takes somebody off the payroll.
     *
     * @return Collection<int, Employee>
     */
    private function activeEmployees(): Collection
    {
        return Employee::query()
            ->where('employees.status', UserStatus::Active->value)
            ->with('user', 'schedule')
            ->orderBy('employees.id')
            ->get();
    }

    /*
    |--------------------------------------------------------------------------
    | The figures
    |--------------------------------------------------------------------------
    */

    /**
     * Change the figures the Accountant owns: base, allowance, bonus, deduction, advance.
     *
     * `leave_impact` is not on that list — it is Calculate's, from approved leave — and neither
     * is `admin_notes`, which is `annotate()`'s. `net_salary` is not writable by anybody: it is
     * a generated column.
     *
     * The status window is `PayrollItemPolicy::update()`'s, and it differs by role: Part D §14
     * makes the period *"read-only to the Accountant"* after approval, and read-only to
     * everybody once locked. The refusal is a sentence naming the status, not a bare 403, because
     * "why can I not edit this" is answered by the month's state and not by the person.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws AuthorizationException
     * @throws PayrollStateException
     */
    public function adjustItem(User $actor, PayrollItem $item, array $attributes): PayrollItem
    {
        $item->loadMissing('period');

        if (! Gate::forUser($actor)->allows('update', $item)) {
            // Which of the two it is decides the sentence: a status the actor could never edit
            // in is a state problem, anything else is a permission problem.
            $status = $item->period?->status;

            if ($status !== null && ! $status->isOpenToAdmin()) {
                throw PayrollStateException::itemIsReadOnly($status);
            }

            throw new AuthorizationException('You are not allowed to change this payroll item.');
        }

        $values = array_intersect_key($attributes, array_flip(PayrollItem::ADJUSTABLE));

        return DB::transaction(function () use ($item, $values): PayrollItem {
            $item->fill($values)->save();

            // `net_salary` is computed by PostgreSQL on write, so the in-memory model is stale
            // the moment a component changes. Every path that writes an item refreshes.
            return $item->refresh();
        });
    }

    /**
     * Write the personal notes column. ADMIN only — Part D §14's *"the 'personal notes' column
     * the Accountant never receives"*.
     *
     * @throws AuthorizationException
     */
    public function annotate(User $actor, PayrollItem $item, ?string $notes): PayrollItem
    {
        if (! Gate::forUser($actor)->allows('annotate', $item)) {
            throw new AuthorizationException('You are not allowed to write notes on a payroll item.');
        }

        $clean = $notes === null ? null : trim($notes);

        $item->admin_notes = ($clean === null || $clean === '') ? null : $clean;
        $item->save();

        return $item->refresh();
    }

    /**
     * **Calculate.** Recompute `leave_impact` for every line in the period from approved unpaid
     * leave, and move a draft to `calculated`.
     *
     * Part D §14: *"Calculate applies leave-impact rules (`leave_requests.unpaid_days` in the
     * period × daily rate = `leave_impact`)"*.
     *
     * ## Idempotent, and current
     *
     * Pressing it twice gives the same answer, because it computes from the rows rather than
     * accumulating onto what is already there. Pressing it after another unpaid day is approved
     * gives a different one, because it re-reads `leave_requests` every time. Those are the same
     * property stated twice: **nothing about this calculation is remembered between runs**, so
     * there is nothing to drift.
     *
     * It deliberately does **not** re-read `employee_salaries`. The base and allowance on an
     * item are the item's own figures from the moment it was drafted, because Part D §14 has the
     * Accountant *"fill and adjust"* them — re-sourcing them on Calculate would silently undo
     * the correction they were asked to make, and it would do it on the button they press to
     * check their work. A month is re-sourced by being drafted, not by being calculated.
     *
     * @throws AuthorizationException
     * @throws PayrollStateException
     */
    public function calculate(User $actor, PayrollPeriod $period): PayrollPeriod
    {
        if (! Gate::forUser($actor)->allows('calculate', $period)) {
            throw new AuthorizationException('You are not allowed to calculate payroll.');
        }

        if (! $period->status->allowsCalculation()) {
            throw PayrollStateException::notCalculable($period->status);
        }

        return DB::transaction(function () use ($period): PayrollPeriod {
            $items = PayrollItem::query()
                ->where('payroll_period_id', $period->getKey())
                ->with('employee.schedule')
                ->lockForUpdate()
                ->get();

            foreach ($items as $item) {
                if ($item->employee === null) {
                    continue;
                }

                $item->leave_impact = $this->fromCents(
                    $this->leaveImpactCents($item->employee, $period, $item),
                );
                $item->save();
            }

            if ($period->status !== PayrollStatus::Calculated) {
                $period->applyTransition(PayrollStatus::Calculated)->save();
            }

            return $period->refresh();
        });
    }

    /**
     * **The leave-impact rule, in whole cents.** `unpaid days in this month × the daily rate`.
     *
     * ## The daily rate: the month's recurring pay divided by the month's PAYABLE WORKING DAYS
     *
     *     daily rate = (base_salary + allowance) ÷ (working days in the month, on this
     *                                               employee's own schedule)
     *
     * Part D §14 defines `leave_impact` as *"`leave_requests.unpaid_days` in the period × daily
     * rate"* and never says what a daily rate is. So it is decided here, and the argument is
     * worth writing down because **this is the number an employee will one day query**.
     *
     * ### Why the divisor is working days and not 30, and not the calendar length
     *
     * Because of what the numerator counts. `leave_requests.unpaid_days` is **working days** —
     * `LeaveService::leaveDays()` counts dates on the employee's own `schedules.working_days`
     * and nothing else, so a Friday off a Sunday-to-Thursday week was never leave and is never
     * in that number. Divide a monthly salary by 30 and multiply by working days and the two
     * sides do not describe the same thing: somebody who took **every** working day of
     * September unpaid — who did not work one hour all month — would be docked 22/30ths and paid
     * for the other eight, which nobody would sign off and no employee would understand.
     *
     * With working days on both sides the rule closes: **all of the month's working days unpaid
     * deducts all of the month's recurring pay, exactly**, and the assertion is in
     * `PayrollCalculationTest`. A divisor of 30 has exactly one thing going for it — it is the
     * same every month — and this application has no other arithmetic that trades correctness
     * for a round number.
     *
     * The divisor is the **same predicate, for the same person**, that counted the days being
     * deducted: `LeaveService::leaveDays()` over the whole month. Including its one documented
     * asymmetry — somebody with **no schedule row at all** (the Accountant, `tracking_mode =
     * none`) counts every day in the window, on both sides of the division, so their rule closes
     * too. That is why this method calls the leave service rather than counting dates itself:
     * one predicate, asked twice, cannot disagree with itself.
     *
     * ### Why the numerator is base + allowance
     *
     * An allowance in Part D §20 is a **monthly** figure paid beside the salary, and it is paid
     * for the same month's presence the salary is. Deducting only from base would pay a full
     * transport or housing allowance for days nobody travelled or attended, so a month taken
     * entirely unpaid would still pay out the allowance — the rule would not close. Bonus,
     * deduction and advance are deliberately **not** in it: a bonus is for something that
     * happened, an advance is money already handed over, and neither is a rate per day.
     *
     * *(This is the one number in the slice the client has not ruled on, and it is in the
     * report as an open question: some payrolls deduct from base alone. Changing it is this
     * method and its test.)*
     *
     * ## Integer cents, all the way down — there is no float at any step
     *
     *     impact = round( (base + allowance in cents) × unpaid days ÷ payable days )
     *
     * with the rounding done as `intdiv(2·n + d, 2·d)` — half up, integers only. `round()` on a
     * float would be a float, and `(int) (8.6 * 100)` is 859. The result is capped at the
     * month's recurring pay: unpaid days can never exceed payable days (the leave table's
     * EXCLUDE constraint stops two requests covering the same day), so the cap is unreachable
     * arithmetic — it is there because a schedule edited between an approval and a payroll run
     * is the one way it could be reached, and *"we paid you minus more than your salary"* must
     * not be a number this application can produce.
     */
    public function leaveImpactCents(Employee $employee, PayrollPeriod $period, PayrollItem $item): int
    {
        $unpaidDays = $this->unpaidDaysIn($employee, $period);

        if ($unpaidDays <= 0) {
            return 0;
        }

        $payableDays = $this->payableDaysIn($employee, $period);

        if ($payableDays <= 0) {
            // A month in which this person has no working day at all has no daily rate. It
            // cannot happen with a real schedule; zero is the only honest answer if it does.
            return 0;
        }

        $monthlyCents = $this->toCents((string) $item->base_salary) + $this->toCents((string) $item->allowance);

        $numerator = $monthlyCents * min($unpaidDays, $payableDays);

        // Half-up rounding with integers only. Never `round($numerator / $payableDays)`, which
        // is a float division of a figure that is somebody's pay.
        $impact = intdiv(2 * $numerator + $payableDays, 2 * $payableDays);

        return min($impact, $monthlyCents);
    }

    /**
     * The unpaid leave days this employee has in this month.
     *
     * Approved requests only, and only ones whose stored `unpaid_days` is above zero —
     * `LeaveService` writes that as *all* of a request's days on an unpaid type and none on any
     * other, so this is the whole of *"which absences cost pay"* and this method does not
     * re-decide it.
     *
     * A request that crosses a month boundary is split by counting the working days in the part
     * that falls **inside** this month, with the same predicate that counted the request —
     * because the numerator of the daily-rate division has to be days of the same kind as its
     * divisor. It is then **capped at the request's stored `unpaid_days`**, so a schedule edited
     * after an approval can never charge somebody for more unpaid days than they were granted.
     * For a request that lies wholly inside one month and whose schedule has not changed, this
     * is exactly the stored number, which is what Part D §14 asks for — and
     * `PayrollCalculationTest` asserts that equality rather than assuming it.
     */
    public function unpaidDaysIn(Employee $employee, PayrollPeriod $period): int
    {
        [$start, $end] = $this->monthWindow($period);

        $requests = LeaveRequest::query()
            ->where('employee_id', $employee->getKey())
            ->where('status', LeaveStatus::Approved->value)
            ->where('unpaid_days', '>', 0)
            ->where('start_date', '<=', $end->toDateString())
            ->where('end_date', '>=', $start->toDateString())
            ->get();

        $days = 0;

        foreach ($requests as $request) {
            $from = $request->start_date->greaterThan($start) ? $request->start_date : $start;
            $to = $request->end_date->lessThan($end) ? $request->end_date : $end;

            $inMonth = count($this->leave->leaveDays($employee, $from, $to));

            $days += min($inMonth, (int) $request->unpaid_days);
        }

        return $days;
    }

    /**
     * The days in this month this employee is paid for — the divisor of the daily rate.
     *
     * The same `LeaveService::leaveDays()` that counted the unpaid days, asked of the whole
     * month. See `leaveImpactCents()` for why it is this and not 30.
     */
    public function payableDaysIn(Employee $employee, PayrollPeriod $period): int
    {
        [$start, $end] = $this->monthWindow($period);

        return count($this->leave->leaveDays($employee, $start, $end));
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function monthWindow(PayrollPeriod $period): array
    {
        $start = PayrollPeriod::monthKey($period->month);

        return [$start, $start->copy()->endOfMonth()->startOfDay()];
    }

    /*
    |--------------------------------------------------------------------------
    | The state machine
    |--------------------------------------------------------------------------
    */

    /**
     * Submit the calculated month for approval. Admin — Part D §14's *"Admin reviews"*.
     *
     * @throws AuthorizationException
     * @throws PayrollStateException
     */
    public function review(User $actor, PayrollPeriod $period): PayrollPeriod
    {
        return $this->move($actor, $period, 'review', PayrollStatus::Reviewed);
    }

    /**
     * Approve it — and write the `payroll.approved` row Part C §4 names.
     *
     * The audit call is inside the same transaction as the transition, so there is no state in
     * which a month is approved and nothing says who approved it. The values on both sides are
     * the period's status and its month, plus the number of lines and the total they come to:
     * an approval is an approval **of an amount**, and an audit row that does not say what was
     * approved is a row nobody can act on years later.
     *
     * @throws AuthorizationException
     * @throws PayrollStateException
     */
    public function approve(User $actor, PayrollPeriod $period): PayrollPeriod
    {
        if (! Gate::forUser($actor)->allows('approve', $period)) {
            throw new AuthorizationException('You are not allowed to approve payroll.');
        }

        return DB::transaction(function () use ($actor, $period): PayrollPeriod {
            $old = $this->auditValues($period);

            $period->applyTransition(PayrollStatus::Approved)->save();

            $this->audit->record(
                AuditEvent::PayrollApproved,
                $period,
                $old,
                $this->auditValues($period->refresh()),
                $actor,
            );

            return $period;
        });
    }

    /**
     * **Close the month.** From here every income and expense dated in it is refused — created,
     * edited, moved in, moved out or deleted (Part D §13, `FinanceService::assertPeriodIsOpen()`).
     *
     * @throws AuthorizationException
     * @throws PayrollStateException
     */
    public function lock(User $actor, PayrollPeriod $period): PayrollPeriod
    {
        return $this->move($actor, $period, 'lock', PayrollStatus::Locked);
    }

    /**
     * **Reverse a lock. ADMIN only, and a reason is required** (Part D §14, Part C §4).
     *
     * Three things happen together or not at all: the period goes back to `approved`, the
     * reason and the reverser are written onto the row, and `payroll.lock_reversed` is written
     * to the audit log **with the reason in it**. The row's two columns are the most recent
     * reversal; the audit log is every reversal there has ever been, in the one table `hq_app`
     * cannot UPDATE or DELETE.
     *
     * A blank reason is refused with a sentence before anything moves.
     * `payroll_periods_reversal_is_whole` is the database's half of the same rule.
     *
     * @throws AuthorizationException
     * @throws PayrollStateException
     */
    public function reverseLock(User $actor, PayrollPeriod $period, string $reason): PayrollPeriod
    {
        if (! Gate::forUser($actor)->allows('reverseLock', $period)) {
            throw new AuthorizationException('Only an Admin can reverse a payroll lock.');
        }

        if ($period->status !== PayrollStatus::Locked) {
            throw PayrollStateException::notLocked($period->status);
        }

        $clean = trim($reason);

        if ($clean === '') {
            throw PayrollStateException::reversalNeedsAReason();
        }

        return DB::transaction(function () use ($actor, $period, $clean): PayrollPeriod {
            $old = $this->auditValues($period);

            $period->applyTransition(PayrollStatus::Approved);
            $period->lock_reversed_by = $actor->getKey();
            $period->lock_reversal_reason = $clean;
            $period->save();

            $this->audit->record(
                AuditEvent::PayrollLockReversed,
                $period,
                $old,
                $this->auditValues($period->refresh()) + ['reason' => $clean],
                $actor,
            );

            return $period;
        });
    }

    /**
     * Mark the month paid. The payslips are released and the month stays closed to finance.
     *
     * @throws AuthorizationException
     * @throws PayrollStateException
     */
    public function markPaid(User $actor, PayrollPeriod $period): PayrollPeriod
    {
        // Polish 002: the client dropped Lock as a separate step. An Approved month is paid in
        // one press — it passes through `locked` inside the same transaction, so the machine,
        // `locked_at` and the finance close are exactly what two presses would have left.
        if ($period->status === PayrollStatus::Approved) {
            if (! Gate::forUser($actor)->allows('markPaid', $period)) {
                throw new AuthorizationException('You are not allowed to mark paid payroll.');
            }

            return DB::transaction(function () use ($period): PayrollPeriod {
                $period->applyTransition(PayrollStatus::Locked)->save();
                $period->applyTransition(PayrollStatus::Paid)->save();

                return $period->refresh();
            });
        }

        return $this->move($actor, $period, 'markPaid', PayrollStatus::Paid);
    }

    /**
     * One transition, checked and applied. The three moves that need nothing else.
     *
     * @throws AuthorizationException
     * @throws PayrollStateException
     */
    private function move(User $actor, PayrollPeriod $period, string $ability, PayrollStatus $to): PayrollPeriod
    {
        if (! Gate::forUser($actor)->allows($ability, $period)) {
            throw new AuthorizationException(sprintf(
                'You are not allowed to %s payroll.',
                match ($ability) {
                    'review' => 'submit for review',
                    'lock' => 'lock',
                    'markPaid' => 'mark paid',
                    default => $ability,
                },
            ));
        }

        return DB::transaction(function () use ($period, $to): PayrollPeriod {
            $period->applyTransition($to)->save();

            return $period->refresh();
        });
    }

    /**
     * What an audit row says about a period: which month, what state, and **what it is worth**.
     *
     * The total is in it because `payroll.approved` is an approval of an amount, and because
     * after a reversal and a re-run the figures may not be the same — an audit trail that
     * recorded only the status change could not tell you that.
     *
     * @return array<string, mixed>
     */
    private function auditValues(PayrollPeriod $period): array
    {
        $totals = DB::table('payroll_items')
            ->where('payroll_period_id', $period->getKey())
            ->selectRaw('COUNT(*) as lines, COALESCE(SUM(net_salary), 0) as net')
            ->first();

        return [
            'id' => (int) $period->getKey(),
            'month' => PayrollPeriod::monthKey($period->month)->toDateString(),
            'status' => $period->status?->value,
            'locked_at' => $period->locked_at?->toIso8601String(),
            'items' => (int) ($totals->lines ?? 0),
            'net_total' => $this->fromCents($this->toCents((string) ($totals->net ?? '0'))),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Reading — the three halves of the self-scope rule
    |--------------------------------------------------------------------------
    */

    /**
     * The payroll items this user may see, optionally for one period. **Part 1 of the rule:
     * omission.**
     *
     * Somebody else's line is not in the result. Not as null, not as a masked row — absent, by
     * the `WHERE` clause. See `PayrollItem::scopeVisibleTo()`.
     *
     * @return Collection<int, PayrollItem>
     */
    public function itemsFor(User $viewer, ?PayrollPeriod $period = null): Collection
    {
        return $this->itemQuery($viewer, $period)->get();
    }

    /**
     * @return Builder<PayrollItem>
     */
    public function itemQuery(User $viewer, ?PayrollPeriod $period = null): Builder
    {
        return PayrollItem::query()
            ->visibleTo($viewer)
            ->when(
                $period !== null,
                fn (Builder $query) => $query->where('payroll_period_id', $period->getKey()),
            )
            ->with(['period', 'employee.user'])
            ->join('payroll_periods', 'payroll_periods.id', '=', 'payroll_items.payroll_period_id')
            ->orderByDesc('payroll_periods.month')
            ->orderBy('payroll_items.employee_id')
            ->select('payroll_items.*');
    }

    /**
     * One payroll item by id, for this viewer. **Parts 2 and 3 of the rule: 404, and the audit
     * row.**
     *
     * Part B §3 rule 1 and Phase 9's security list:
     *
     * > *"Employee requesting any other employee's payroll item — list endpoints omit it, a
     * > direct id returns **404**, and the attempt is **audit-logged**."*
     *
     * The lookup goes through the same scope the list does, so the 404 is what the query
     * *returns* rather than something this method decides — the shape every other slice in this
     * repo uses, and the reason a 403 can never slip out here: there is no branch that could
     * produce one.
     *
     * **The audit row is written only when the row exists and belongs to somebody else**, which
     * is the only case where an attempt on another employee's payroll actually happened. An id
     * that matches nothing is somebody typing, a stale bookmark or a deleted draft, and logging
     * those would bury the rows Part C §3 asks for — *"every access attempt to another
     * employee's salary … is logged"* — in noise. The response is byte-identical either way, so
     * nothing about what is logged is observable from outside.
     *
     * `AuditEvent::RestrictedAccessAttempt` is the existing event for exactly this and it had no
     * writer until now; a second event meaning the same thing would split every future query
     * about denied access in two.
     *
     * @throws ModelNotFoundException
     */
    public function findItemFor(User $viewer, int $id): PayrollItem
    {
        $item = PayrollItem::query()->visibleTo($viewer)->find($id);

        if ($item !== null) {
            return $item->load(['period', 'employee.user']);
        }

        $actual = PayrollItem::query()->find($id);

        if ($actual !== null && ! $actual->belongsToEmployeeOf($viewer)) {
            $this->audit->record(
                AuditEvent::RestrictedAccessAttempt,
                $actual,
                null,
                [
                    'resource' => 'payroll_item',
                    'payroll_item_id' => (int) $actual->getKey(),
                    // Whose line was asked for — not what it said. An audit row about a refused
                    // read must not carry the figures the read was refused.
                    'employee_id' => (int) $actual->employee_id,
                ],
                $viewer,
            );
        }

        throw (new ModelNotFoundException)->setModel(PayrollItem::class, [$id]);
    }

    /*
    |--------------------------------------------------------------------------
    | Money
    |--------------------------------------------------------------------------
    */

    /**
     * A decimal string to whole cents. `round()` before the cast because `(int) (8.6 * 100)` is
     * 859 on a binary float — which is the entire reason money is never a float here.
     *
     * This is the one place a float appears in the payroll feature, and only to read a value
     * PostgreSQL handed over as an exact decimal string. Everything downstream of it is an int.
     */
    private function toCents(string $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    private function fromCents(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
