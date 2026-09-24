<?php

namespace App\Services;

use App\Events\LeaveApproved;
use App\Events\LeaveCorrectionRequested;
use App\Events\LeaveRejected;
use App\Events\LeaveRequested;
use App\Exceptions\LeaveStateException;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\AttendanceStatus;
use App\Support\AuditEvent;
use App\Support\LeaveStatus;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Leave: applying, deciding, and everything an approval does (master prompt Part D §9, Phase 5).
 *
 * ## The one door
 *
 * Every status a request ever reaches is written here, through `LeaveRequest::applyTransition()`
 * — the model throws if anything else leaves `status` dirty (decision 2-9). That is not a style
 * preference. **An approval is not a word changing colour**: it decrements a balance, writes an
 * `attendance_records` row for every working day in the window and stores the `unpaid_days`
 * Phase 9 will pay from. A second path to `status = 'approved'` would produce a request that
 * says approved with none of that behind it, and nobody would find out until a payslip was
 * wrong. So the transition and its side effects are in one transaction, in one method, and
 * there is no way to reach the first without the second.
 *
 * ## What "days" means, once
 *
 * `leaveDays()` is the single statement of which dates in a window count. It is the employee's
 * OWN schedule — `AttendanceService::isWorkingDay()`, the same predicate the absent sweep and
 * the month grid ask — and never a hard-coded week. Every number in this feature comes from it:
 * the `days` on the request, the balance it spends, the `unpaid_days` Phase 9 reads and the
 * attendance rows the approval writes. A screen that counted dates itself would be a second
 * answer to the same question, and the two would first disagree on the day somebody's schedule
 * changed.
 *
 * ## Three refusals, and where each one really lives
 *
 *   - **Overlap.** Part D §9: pending and approved requests may not overlap. The check here
 *     produces the sentence; the promise is `leave_requests_no_overlap`, a GiST EXCLUDE
 *     constraint (decisions 3-1 and 4-2 — the database enforces what an `if` cannot).
 *   - **Balance.** Only for `has_balance` types. Unpaid and Other are never refused for want of
 *     days, because they have no number to run out of.
 *   - **No working days.** A Friday on a Sunday-to-Thursday week is not leave; there is nothing
 *     to take. Refused with the dates in the sentence rather than accepted as a request for
 *     zero days, which `CHECK (days >= 1)` would have turned into a constraint violation.
 *
 * ## Nothing here compares one person's leave with another's
 *
 * No totals across people, no ranking, no "days taken this year vs the team" (Part H §1). A
 * balance is a number for one person and a request is a record of one absence.
 */
class LeaveService
{
    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly AuditLogger $audit,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Counting days — the single predicate
    |--------------------------------------------------------------------------
    */

    /**
     * The dates in this window that are leave for this employee.
     *
     * The employee's own `schedules.working_days`, through `AttendanceService::isWorkingDay()`,
     * so nothing in this feature hard-codes a week.
     *
     * ## The one asymmetry, and why it is deliberate
     *
     * `isWorkingDay()` answers **false** for an employee with no schedule at all, and that is
     * the right answer to its own question: a missing schedule is an unanswered question, not
     * an empty week, and it is what keeps somebody the office clock does not track out of the
     * 23:55 absent sweep without being named.
     *
     * Asked here it would be the wrong answer, and provably so: Part C §1 gives *Apply for own
     * leave* to **every** role including the ACCOUNTANT, who is `tracking_mode = none` and has
     * no schedule row. Counting their working days as none would make every leave request they
     * ever filed a refusal for zero days — a cell of the permission matrix unreachable because
     * of a predicate written for a different question.
     *
     * So: with a schedule, its working days. Without one, **every day in the window**. Both
     * readings are honest about what is known. The sweep cannot mark that person absent either
     * way, because `markAbsent()` asks `tracking_mode` before it asks anything else.
     *
     * @return list<Carbon>
     */
    public function leaveDays(Employee $employee, CarbonInterface|string $from, CarbonInterface|string $to): array
    {
        $schedule = $employee->schedule;
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();

        $days = [];

        for ($day = $start->copy(); $day->lessThanOrEqualTo($end); $day->addDay()) {
            if ($schedule === null || $this->attendance->isWorkingDay($schedule, $day)) {
                $days[] = $day->copy();
            }
        }

        return $days;
    }

    /*
    |--------------------------------------------------------------------------
    | Applying
    |--------------------------------------------------------------------------
    */

    /**
     * File a request. It is born `pending` — the one status a row is allowed to be written with
     * directly, because a row that does not exist yet has nothing to transition from.
     *
     * Everything is checked inside the transaction with the employee's holding requests locked,
     * so two taps on *Apply* cannot each decide there is nothing to overlap.
     */
    public function apply(
        User $actor,
        Employee $employee,
        LeaveType $type,
        CarbonInterface|string $from,
        CarbonInterface|string $to,
        string $reason,
    ): LeaveRequest {
        [$start, $end] = $this->window($from, $to);

        return DB::transaction(function () use ($actor, $employee, $type, $start, $end, $reason): LeaveRequest {
            $days = $this->assertBookable($employee, $type, $start, $end);

            $request = new LeaveRequest([
                'employee_id' => $employee->getKey(),
                'leave_type_id' => $type->getKey(),
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'days' => count($days),
                'unpaid_days' => $this->unpaidDays($type, count($days)),
                'reason' => $reason,
            ]);

            $request->status = LeaveStatus::Pending;
            $request->save();

            $request->setRelation('leaveType', $type);
            $request->setRelation('employee', $employee);

            event(new LeaveRequested($request, $actor));

            return $request;
        });
    }

    /**
     * Answer a correction request: amend the dates, the type or the reason and send it back.
     *
     * It is the SAME request moving `correction_requested → pending`, not a new one, which is
     * the whole point of the status existing — the approver's note, the original filing date
     * and the thread of it all survive. Everything is re-checked, because the dates may have
     * moved onto a week that is now spoken for or past a balance that has since been spent.
     *
     * The previous decision is cleared as the request re-enters the queue: a pending row
     * carrying a `decided_at` is a row `leave_requests_decision_is_whole` refuses outright, and
     * a queue showing "decided by Shahadat" against something waiting for Shahadat would be a
     * screen contradicting itself.
     */
    public function resubmit(
        User $actor,
        LeaveRequest $request,
        LeaveType $type,
        CarbonInterface|string $from,
        CarbonInterface|string $to,
        string $reason,
    ): LeaveRequest {
        [$start, $end] = $this->window($from, $to);

        return DB::transaction(function () use ($actor, $request, $type, $start, $end, $reason): LeaveRequest {
            $request = $this->lock($request);
            $employee = $request->employee;

            $days = $this->assertBookable($employee, $type, $start, $end, $request);

            $request->applyTransition(LeaveStatus::Pending);

            $request->fill([
                'leave_type_id' => $type->getKey(),
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'days' => count($days),
                'unpaid_days' => $this->unpaidDays($type, count($days)),
                'reason' => $reason,
            ]);

            $request->approver_id = null;
            $request->decided_at = null;
            $request->decision_note = null;

            $request->save();

            $request->setRelation('leaveType', $type);

            event(new LeaveRequested($request, $actor, resubmitted: true));

            return $request;
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Deciding
    |--------------------------------------------------------------------------
    */

    /**
     * Approve, and do everything an approval does. **"With no manual step" is the acceptance
     * sentence**, so all of it happens here, in one transaction, or none of it does.
     *
     *   1. The status moves through the machine.
     *   2. The balance is decremented — capped types only, locked, and refused rather than
     *      driven negative if the days have been spent since the request was filed.
     *   3. `attendance_records` gets a **Leave** row for each working day in the window.
     *   4. `unpaid_days` is re-affirmed from the type, so a request filed before a type was
     *      re-flagged is granted on today's terms rather than yesterday's.
     *   5. The decision — who, when, and the optional note — is stamped, audited with old and
     *      new values, and announced.
     *
     * ## What step 3 writes, and what it deliberately does not
     *
     * **For a remote-timer employee it writes nothing at all**, and that is decision 4-11 held
     * rather than worked around: both `AttendanceRecordPolicy::update()` and
     * `AttendanceService::edit()` refuse to put an `attendance_records` row on somebody the
     * office clock does not track, so "Tapu is never Absent" is structural — and an approval
     * quietly becoming the one hand that could write him a row would have taken that apart from
     * the inside. His leave is the request; his days are the timer's; the calendar, the flag,
     * the balance and `unpaid_days` all work exactly the same for him. The count of rows
     * written rides on the event so the notification can say *"2 days"* without claiming
     * attendance rows that do not exist.
     *
     * **A day somebody actually clocked into is left alone.** The upsert claims a day with no
     * row and a day whose row has no `clock_in` — which is the Absent the 23:55 sweep wrote,
     * and the case retro-approved leave exists for. A row with a clock-in is evidence that the
     * person was at work, and leave must not erase it; the approval reports how many days it
     * could not claim rather than overwriting them.
     *
     * **A day already covered by a holiday is skipped**, asked through
     * `AttendanceService::coveredByLeaveOrHoliday()` — the Phase 5 seam — **before** the status
     * moves. Before, because after it the request itself would answer that question true. At
     * that moment the only things that can make it true are a company holiday or another
     * approved request, and another approved request over these dates is what the overlap rule
     * has already refused.
     */
    public function approve(User $actor, LeaveRequest $request, ?string $note = null): LeaveRequest
    {
        return DB::transaction(function () use ($actor, $request, $note): LeaveRequest {
            $request = $this->lock($request);
            $this->assertNotOwn($actor, $request);

            $old = $request->auditValues();
            $employee = $request->employee;
            $type = $request->leaveType;

            // Asked while the request is still pending — see the docblock.
            $dates = $this->attendanceDates($employee, $request);

            $request->applyTransition(LeaveStatus::Approved);

            $request->unpaid_days = $this->unpaidDays($type, (int) $request->days);
            $request->approver_id = $actor->getKey();
            $request->decided_at = Carbon::now();
            $request->decision_note = $this->clean($note);
            $request->save();

            $this->spendBalance($employee, $type, (int) $request->days);

            $written = $this->writeAttendance($employee, $dates);

            $this->audit->record(
                AuditEvent::LeaveApproved,
                $request,
                $old,
                $request->auditValues(),
                $actor,
            );

            event(new LeaveApproved($request, $actor, $written));

            return $request;
        });
    }

    /**
     * Turn it down, with the reason the Form Request required.
     *
     * Nothing is spent, nothing is written to the calendar and nothing is deleted — the
     * request, its dates and the employee's own words stay exactly where they were with the
     * decision beside them. The same choice decision 4-18 made for a refused time entry, and
     * for the same reason: a refusal that removed the row would leave the person who asked with
     * no trace of what happened to their request.
     */
    public function reject(User $actor, LeaveRequest $request, string $note): LeaveRequest
    {
        return DB::transaction(function () use ($actor, $request, $note): LeaveRequest {
            $request = $this->lock($request);
            $this->assertNotOwn($actor, $request);

            $old = $request->auditValues();

            $request->applyTransition(LeaveStatus::Rejected);
            $request->approver_id = $actor->getKey();
            $request->decided_at = Carbon::now();
            $request->decision_note = $note;
            $request->save();

            $this->audit->record(
                AuditEvent::LeaveRejected,
                $request,
                $old,
                $request->auditValues(),
                $actor,
            );

            event(new LeaveRejected($request, $actor));

            return $request;
        });
    }

    /**
     * Send it back with a question. Part D §9's third verb.
     *
     * Not a decision and not audited as one: nothing was granted and nothing was refused, so
     * there is no change to somebody's record for a log to hold. `decided_at` is still stamped,
     * because `leave_requests_decision_is_whole` reads it as "this row is not waiting in the
     * queue any more" — it is waiting on the employee — and because the screen has to be able
     * to say when the question was asked.
     */
    public function requestCorrection(User $actor, LeaveRequest $request, string $note): LeaveRequest
    {
        return DB::transaction(function () use ($actor, $request, $note): LeaveRequest {
            $request = $this->lock($request);
            $this->assertNotOwn($actor, $request);

            $request->applyTransition(LeaveStatus::CorrectionRequested);
            $request->approver_id = $actor->getKey();
            $request->decided_at = Carbon::now();
            $request->decision_note = $note;
            $request->save();

            event(new LeaveCorrectionRequested($request, $actor));

            return $request;
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Balances
    |--------------------------------------------------------------------------
    */

    /**
     * An Admin sets a balance, with the reason, audit-logged with old and new.
     *
     * Part D §9's *"seeded per employee by Admin; no accrual logic in MVP — Admin adjusts
     * balances, audit-logged"*, and this method is that sentence. It is a **set**, not an
     * adjustment by a delta: the Admin types the number that should be there, which is what
     * they are actually deciding, and a delta would need a second screen to show what it was
     * being applied to.
     *
     * A day with no row yet is an upsert, for the reason `AttendanceService::edit()` upserts:
     * seeding somebody's first balance and correcting one they already have are the same act to
     * the Admin making it, and two endpoints would be two places for the audit row to be
     * forgotten. The `old_value` is null on a first write, which is what makes "created" and
     * "changed" different rows in the log rather than the same one.
     *
     * An uncapped type is refused: there is no number for Unpaid to hold.
     */
    public function setBalance(
        User $actor,
        Employee $employee,
        LeaveType $type,
        int $days,
        string $reason,
    ): LeaveBalance {
        if (! $type->has_balance) {
            throw LeaveStateException::typeHasNoBalance($type->name);
        }

        if ($days < 0) {
            throw LeaveStateException::balanceCannotBeNegative();
        }

        return DB::transaction(function () use ($actor, $employee, $type, $days, $reason): LeaveBalance {
            $balance = LeaveBalance::lockFor((int) $employee->getKey(), (int) $type->getKey());
            $creating = $balance === null;

            $balance ??= new LeaveBalance([
                'employee_id' => $employee->getKey(),
                'leave_type_id' => $type->getKey(),
            ]);

            $balance->setRelation('leaveType', $type);

            // Read BEFORE the write, and null on a type this employee had no row for.
            $old = $creating ? null : $balance->auditValues();

            $balance->balance_days = $days;
            $balance->save();

            $this->audit->record(
                AuditEvent::LeaveBalanceAdjusted,
                $balance,
                $old,
                $balance->auditValues() + ['reason' => $reason],
                $actor,
            );

            return $balance;
        });
    }

    /**
     * One employee's balances, one row per capped type — **including the types they have no row
     * for, as zero**.
     *
     * Zero is an answer, not an empty state. An employee whose Sick balance has never been set
     * has no days of it, and a screen that simply omitted the row would read as "Sick leave
     * does not exist here" rather than "you have none left". It is also what makes the apply
     * form honest before an Admin has been anywhere near it.
     *
     * @return list<array{type: array<string, mixed>, balance_days: int|null, updated_at: string|null}>
     */
    public function balancesFor(Employee $employee): array
    {
        $balances = LeaveBalance::query()
            ->where('employee_id', $employee->getKey())
            ->get()
            ->keyBy('leave_type_id');

        return LeaveType::query()
            ->capped()
            ->inOrder()
            ->get()
            ->map(fn (LeaveType $type): array => [
                'type' => $type->toPayload(),
                'balance_days' => (int) ($balances->get($type->getKey())?->balance_days ?? 0),
                'updated_at' => $balances->get($type->getKey())?->updated_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * Days left of one capped type. Zero for a type with no row — see `balancesFor()`.
     */
    public function balanceDays(Employee $employee, LeaveType $type): int
    {
        if (! $type->has_balance) {
            return 0;
        }

        return (int) (LeaveBalance::query()
            ->where('employee_id', $employee->getKey())
            ->where('leave_type_id', $type->getKey())
            ->value('balance_days') ?? 0);
    }

    /*
    |--------------------------------------------------------------------------
    | Reads
    |--------------------------------------------------------------------------
    */

    /**
     * Every approved leave day inside a window, for the calendar.
     *
     * Keyed by date, so a month grid looks up a cell without a query per cell and without
     * comparing dates in JavaScript. One query for the month, and the per-request working-day
     * expansion happens here because the schedule that decides it is this employee's — a
     * calendar that painted every date between two ends would show Friday as leave for a team
     * that does not work Fridays.
     *
     * @param  Collection<int, LeaveRequest>  $requests
     * @return array<string, list<array<string, mixed>>>
     */
    public function daysByDate(Collection $requests, CarbonInterface|string $from, CarbonInterface|string $to): array
    {
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();
        $byDate = [];

        foreach ($requests as $request) {
            $employee = $request->employee;

            if ($employee === null) {
                continue;
            }

            foreach ($this->leaveDays($employee, $request->start_date, $request->end_date) as $day) {
                if ($day->lessThan($start) || $day->greaterThan($end)) {
                    continue;
                }

                $byDate[$day->toDateString()][] = [
                    'request_id' => (int) $request->getKey(),
                    'employee_id' => (int) $employee->getKey(),
                    'name' => $employee->user?->name ?? 'Unknown',
                    'type' => $request->leaveType?->name,
                    'status' => $request->status->value,
                    'status_label' => $request->status->label(),
                    'tone' => $request->status->tone(),
                ];
            }
        }

        return $byDate;
    }

    /**
     * How many people are on approved leave on a date. The Admin dashboard's *On leave* card.
     *
     * A count of distinct people and nothing else — no percentage of the agency, no comparison
     * with last week, nothing that reads as a judgement (Part H §1). It counts **requests whose
     * window contains the date**, not attendance rows, so it is the same number for Tapu, who
     * has no attendance rows at all (decision 4-11).
     */
    public function onLeaveCount(CarbonInterface|string $date): int
    {
        return LeaveRequest::query()
            ->approved()
            ->covering($date)
            ->distinct()
            ->count('employee_id');
    }

    /*
    |--------------------------------------------------------------------------
    | The rules behind the writes
    |--------------------------------------------------------------------------
    */

    /**
     * Every reason a window cannot be booked, asked in one place, returning the days it holds.
     *
     * `$ignore` is the request being resubmitted: its own row is pending and therefore holds
     * its own dates, so it would overlap itself.
     *
     * @return list<Carbon>
     */
    private function assertBookable(
        Employee $employee,
        LeaveType $type,
        Carbon $start,
        Carbon $end,
        ?LeaveRequest $ignore = null,
    ): array {
        $days = $this->leaveDays($employee, $start, $end);

        if ($days === []) {
            throw LeaveStateException::noWorkingDays($start->toDateString(), $end->toDateString());
        }

        $clash = LeaveRequest::query()
            ->forEmployee($employee)
            ->holding()
            ->overlapping($start, $end)
            ->when($ignore !== null, fn ($query) => $query->whereKeyNot($ignore->getKey()))
            ->lockForUpdate()
            ->first();

        if ($clash !== null) {
            throw LeaveStateException::overlaps(
                $clash->start_date->toDateString(),
                $clash->end_date->toDateString(),
                $clash->status->label(),
            );
        }

        // Only a capped type can run out. Unpaid and Other never refuse (Part D §9).
        if ($type->has_balance) {
            $available = $this->balanceDays($employee, $type);

            if (count($days) > $available) {
                throw LeaveStateException::insufficientBalance($type->name, count($days), $available);
            }
        }

        return $days;
    }

    /**
     * Take the days off the balance, or refuse.
     *
     * Checked again here and not only at apply time, because the two are minutes or weeks
     * apart: another request of the same person's can have been approved in between, and the
     * balance is spent at the moment of approval rather than reserved at the moment of asking.
     * Reserving it would have been a second number to keep in step with this one.
     *
     * Uncapped types spend nothing and have no row to spend it from.
     */
    private function spendBalance(Employee $employee, LeaveType $type, int $days): void
    {
        if (! $type->has_balance) {
            return;
        }

        $balance = LeaveBalance::lockFor((int) $employee->getKey(), (int) $type->getKey());
        $available = (int) ($balance?->balance_days ?? 0);

        if ($days > $available) {
            throw LeaveStateException::insufficientBalance($type->name, $days, $available);
        }

        $balance ??= new LeaveBalance([
            'employee_id' => $employee->getKey(),
            'leave_type_id' => $type->getKey(),
        ]);

        $balance->balance_days = $available - $days;
        $balance->save();
    }

    /**
     * The dates an approval will write attendance rows for.
     *
     * Empty for anybody the office clock does not track — decision 4-11, and the whole of it:
     * there is no hand, this one included, that can put an `attendance_records` row on a
     * remote-timer employee.
     *
     * Days already covered by a holiday are dropped here, through the seam. See `approve()`.
     *
     * @return list<Carbon>
     */
    private function attendanceDates(Employee $employee, LeaveRequest $request): array
    {
        if (! $this->attendance->clocks($employee)) {
            return [];
        }

        return array_values(array_filter(
            $this->leaveDays($employee, $request->start_date, $request->end_date),
            fn (Carbon $day): bool => ! $this->attendance->coveredByLeaveOrHoliday($employee, $day),
        ));
    }

    /**
     * Write the Leave rows, and say how many went in.
     *
     * `insertIfAbsent()` first — the same `insertOrIgnore` against `unique(employee_id, date)`
     * the 23:55 sweep uses, so a retried approval writes nothing twice. Where a row is already
     * there, it is claimed only if nobody clocked into it; a day with a `clock_in` is left
     * exactly as it is. See `approve()` for why.
     *
     * @param  list<Carbon>  $dates
     */
    private function writeAttendance(Employee $employee, array $dates): int
    {
        $written = 0;

        foreach ($dates as $date) {
            $inserted = AttendanceRecord::insertIfAbsent([
                'employee_id' => $employee->getKey(),
                'date' => $date->toDateString(),
                'status' => AttendanceStatus::Leave->value,
            ]);

            if ($inserted) {
                $written++;

                continue;
            }

            // A row was already there. Claim it only if nobody was in that day — which is the
            // Absent the sweep wrote, and the case retro-approved leave is for.
            $claimed = AttendanceRecord::query()
                ->where('employee_id', $employee->getKey())
                ->whereDate('date', $date->toDateString())
                ->whereNull('clock_in')
                ->update([
                    'status' => AttendanceStatus::Leave->value,
                    'updated_at' => Carbon::now(),
                ]);

            $written += $claimed > 0 ? 1 : 0;
        }

        return $written;
    }

    /**
     * Unpaid days: all of them on an unpaid type, none on any other.
     *
     * The one statement of it. `is_unpaid` is a fact about the TYPE and is deliberately not
     * inferred from `has_balance` — Other is uncapped and still paid, so a rule that read
     * "uncapped means unpaid" would dock somebody's pay for a day of Other leave and nothing
     * would say so until a payslip did. See the `leave_types` migration.
     */
    private function unpaidDays(LeaveType $type, int $days): int
    {
        return $type->is_unpaid ? $days : 0;
    }

    /**
     * The request, re-read and locked, with the relations every decision path needs.
     *
     * Locked because a decision reads the status, checks it and writes it back, and two Admins
     * pressing Approve and Reject on the same row in the same second must not both pass their
     * own check. The second one to arrive finds an approved request and the machine refuses the
     * move — a sentence, not a silent double decision.
     */
    private function lock(LeaveRequest $request): LeaveRequest
    {
        return LeaveRequest::query()
            ->whereKey($request->getKey())
            ->with(['employee.schedule', 'employee.user', 'leaveType'])
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Nobody rules on their own request.
     *
     * The same rule `TimerService` keeps for a time entry (decision 4-21), and for the same
     * reason: the refusal is about ownership, not about a missing key, so it is asked here as
     * well as in `LeaveRequestPolicy::decide()` — a future command or console call reaches this
     * and not the policy. There are two Admins, so it is a rule somebody can actually work
     * with; an agency with one would want a different one, and that is a client decision.
     */
    private function assertNotOwn(User $actor, LeaveRequest $request): void
    {
        if ((int) ($actor->employee?->getKey() ?? 0) === (int) $request->employee_id) {
            throw LeaveStateException::cannotDecideOwn();
        }
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function window(CarbonInterface|string $from, CarbonInterface|string $to): array
    {
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();

        if ($end->lessThan($start)) {
            throw LeaveStateException::endBeforeStart();
        }

        return [$start, $end];
    }

    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
