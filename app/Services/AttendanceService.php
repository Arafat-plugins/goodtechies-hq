<?php

namespace App\Services;

use App\Exceptions\AttendanceStateException;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Schedule;
use App\Models\User;
use App\Support\AttendanceDay;
use App\Support\AttendanceStatus;
use App\Support\AuditEvent;
use App\Support\TrackingMode;
use App\Support\Weekday;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Office attendance: clocking in and out, and what a day is (master prompt Part D §8, Phase 4).
 *
 * ## Every predicate is stated once, here
 *
 * A day's status is derived from the employee's own schedule and never assumed (decision 2-37).
 * There are four predicates and this file holds all four:
 *
 *   - `isWorkingDay()` — `schedules.working_days`. Off Day is its negation, and nothing
 *     anywhere hard-codes a working week. The seed's Sunday-to-Thursday is data.
 *   - `isLate()` — `schedules.start_time` plus `settings.late_grace_minutes`. **An employee
 *     with no `start_time` cannot be late**, which is the whole of the rule for a remote
 *     schedule and half of it for an office one with flexible hours.
 *   - `isHalfDay()` — less than half of `schedules.working_hours_per_day`, and only when
 *     `settings.half_day_auto` is on (it is off by default, so an Admin marks it).
 *   - `coveredByLeaveOrHoliday()` — Phase 5's skip rule, and the seam it plugs into. See below.
 *
 * None of them is repeated in a controller, a command or a Vue computed. `AttendanceDay` is
 * the one shape they answer in, so the roster row, the month cell and the clock-in widget
 * cannot disagree about Friday.
 *
 * ## Two seams, deliberately left unwired
 *
 * **Leave and holidays (Phase 5).** `coveredByLeaveOrHoliday()` returns false and is called
 * from exactly one place — `markAbsent()`. Phase 5 replaces its body with a lookup in
 * `leave_requests` (approved, covering the date) and `holidays`, and nothing else in this
 * phase changes: not the command, not the query that feeds it, not a test that does not name
 * leave. That is the seam, and it is one method because the plan says the skip "is a filter
 * added in one place".
 *
 * **The remote timer's minutes.** `trackedMinutes()` returns null — "not known here" — because
 * `time_entries` belongs to the other half of Phase 4 and may not exist yet. The roster prints
 * the minutes clause only when the value is non-null, so Tapu reads *"Remote"* today and
 * *"Remote — 2h 10m tracked"* the day the method is given its query. It returns null rather
 * than 0 on purpose: 0 is a measurement and this half of the phase has not taken one.
 *
 * ## Tapu is never absent, and it is `tracking_mode` that says so
 *
 * `markAbsent()` is called only for `tracking_mode = office_attendance` employees, and Remote
 * is derived for `remote_timer` ones. Nowhere in this file is a role name compared: it is the
 * tracking mode that decides who clocks in, who is swept and who reads as Remote, exactly as
 * it decides who gets the widget. A remote employee moved onto the office clock starts being
 * swept the same day, with no list of names edited.
 */
class AttendanceService
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    // -----------------------------------------------------------------------------------
    // The predicates
    // -----------------------------------------------------------------------------------

    /**
     * Does this employee's schedule say they work on this date?
     *
     * An employee with no schedule works no days. That is the honest reading — a missing
     * schedule is not an empty week, it is an unanswered question — and it is what keeps the
     * Accountant, who has no schedule at all, out of the absent sweep without being named.
     */
    public function isWorkingDay(?Schedule $schedule, CarbonInterface $date): bool
    {
        if ($schedule === null) {
            return false;
        }

        $days = $schedule->working_days;

        return is_array($days) && in_array(Weekday::of($date)->value, $days, true);
    }

    /**
     * The last moment an arrival still counts as Present, on the given date.
     *
     * Null when the schedule names no `start_time`, which is the one thing that makes lateness
     * unanswerable rather than false — the caller turns that into "cannot be late". The grace
     * is `settings.late_grace_minutes`, read through SettingsService; there is no literal 15
     * anywhere in this file.
     */
    public function latestOnTime(?Schedule $schedule, CarbonInterface $date): ?Carbon
    {
        if ($schedule?->start_time === null) {
            return null;
        }

        $start = Carbon::parse($date)->startOfDay()->setTimeFrom(Carbon::parse($schedule->start_time));

        return $start->addMinutes((int) $this->settings->get('late_grace_minutes'));
    }

    /**
     * Was this arrival late? Part D §8: "Late when clock-in is later than the schedule's
     * `start_time` + `settings.late_grace_minutes`".
     *
     * An employee with no `start_time` cannot be late — not "is late at midnight", not
     * "is never late because we guessed 09:00". There is nothing to be late for.
     */
    public function isLate(?Schedule $schedule, CarbonInterface $clockIn): bool
    {
        $deadline = $this->latestOnTime($schedule, $clockIn);

        return $deadline !== null && $clockIn->greaterThan($deadline);
    }

    /**
     * Was this a half day? Part D §8: "clock-out before half of `working_hours_per_day`, when
     * the setting `half_day_auto` is on; default off".
     *
     * With the setting off — which is the default, and how the agency runs today — this is
     * always false and Half Day is only ever an Admin's word. That is deliberate: a rule that
     * silently rewrites somebody's day from a clock-out they made at lunchtime for a dentist
     * appointment is a rule an Admin should have opted into.
     */
    public function isHalfDay(?Schedule $schedule, ?int $workedMinutes): bool
    {
        if ($schedule === null || $workedMinutes === null) {
            return false;
        }

        if (! (bool) $this->settings->get('half_day_auto')) {
            return false;
        }

        return $workedMinutes < (float) $schedule->working_hours_per_day * 60 / 2;
    }

    /**
     * **Phase 5 seam.** Is this day already covered by an approved leave request or a holiday?
     *
     * Part D §8 makes the absent sweep skip "days covered by an approved leave, a holiday, or
     * an off day". The off-day half is `isWorkingDay()` and is live now; the other two need
     * `leave_requests` and `holidays`, which Phase 5 creates.
     *
     * When they exist, this method's body becomes the two lookups and **nothing else in this
     * phase moves**: `markAbsent()` already calls it for every candidate, the command already
     * reports what it skipped, and the sweep's tests already pass a fixed date. Phase 5 should
     * prefer a set-based pre-load over a query per employee — the caller loops over four people
     * today and a hundred one day — which is why this takes the employee and the date rather
     * than being buried inside a `whereNotExists` that would have to be rewritten instead of
     * filled in.
     */
    public function coveredByLeaveOrHoliday(Employee $employee, CarbonInterface $date): bool
    {
        return false;
    }

    /**
     * **Timer seam.** Minutes the remote timer recorded for this employee on this date.
     *
     * Null means not known here. `time_entries` is the other half of Phase 4 and the roster is
     * built so that this half is a clean seam: every consumer prints the minutes clause only
     * when the value is non-null, so wiring it up is this method's body and no screen change.
     *
     * When it lands it is `time_entries` summed by `employee_id` and `date`. It must stay a
     * duration and nothing else — no target percentage, no ranking, no score (Part H §1).
     */
    public function trackedMinutes(Employee $employee, CarbonInterface $date): ?int
    {
        return null;
    }

    // -----------------------------------------------------------------------------------
    // Writes
    // -----------------------------------------------------------------------------------

    /**
     * Clock in. Writes Present or Late from the employee's own schedule.
     *
     * The whole thing is one transaction with the day's row locked, because the realistic
     * failure is not two people racing — it is one person on a phone at the door tapping twice
     * because the first tap did not obviously land. The second tap must read the first tap's
     * row, and then be refused with a sentence that names the time, not with a duplicate-key
     * error and not with a second row.
     */
    public function clockIn(Employee $employee, ?CarbonInterface $at = null): AttendanceRecord
    {
        $this->assertClocks($employee);

        $at = $at === null ? Carbon::now() : Carbon::parse($at);
        $date = $at->copy()->startOfDay();
        $schedule = $employee->schedule;

        if ($schedule === null) {
            throw AttendanceStateException::noSchedule();
        }

        if (! $this->isWorkingDay($schedule, $date)) {
            throw AttendanceStateException::notAWorkingDay(Weekday::of($date)->label());
        }

        return DB::transaction(function () use ($employee, $schedule, $at, $date): AttendanceRecord {
            $existing = AttendanceRecord::lockForDay((int) $employee->getKey(), $date);

            if ($existing?->clock_in !== null) {
                throw AttendanceStateException::alreadyClockedIn($existing->clock_in->format('H:i'));
            }

            // A row with no clock-in can exist: the 23:55 sweep wrote an Absent, or an Admin
            // marked the day. Clocking in corrects it rather than colliding with it — the
            // unique index leaves no other option, and the correction is the right answer.
            $record = $existing ?? new AttendanceRecord([
                'employee_id' => $employee->getKey(),
                'date' => $date->toDateString(),
            ]);

            $record->clock_in = $at;
            $record->status = $this->isLate($schedule, $at)
                ? AttendanceStatus::Late
                : AttendanceStatus::Present;

            $record->save();

            return $record;
        });
    }

    /**
     * Clock out. Only `half_day_auto` may change the status here, and only downwards from a
     * day that was Present or Late — a clock-out never turns a Late morning into a Present one.
     */
    public function clockOut(Employee $employee, ?CarbonInterface $at = null): AttendanceRecord
    {
        $this->assertClocks($employee);

        $at = $at === null ? Carbon::now() : Carbon::parse($at);
        $date = $at->copy()->startOfDay();

        return DB::transaction(function () use ($employee, $at, $date): AttendanceRecord {
            $record = AttendanceRecord::lockForDay((int) $employee->getKey(), $date);

            if ($record === null || $record->clock_in === null) {
                throw AttendanceStateException::notClockedIn();
            }

            if ($record->clock_out !== null) {
                throw AttendanceStateException::alreadyClockedOut($record->clock_out->format('H:i'));
            }

            if ($at->lessThan($record->clock_in)) {
                throw AttendanceStateException::clockOutBeforeClockIn();
            }

            $record->clock_out = $at;

            if ($this->isHalfDay($employee->schedule, $record->workedMinutes())) {
                $record->status = AttendanceStatus::HalfDay;
            }

            $record->save();

            return $record;
        });
    }

    /**
     * An Admin's correction, with the reason it was made, audit-logged with old and new.
     *
     * An attendance record is the basis of somebody's pay in Phase 9, so a silent edit to one
     * is precisely the thing an audit log exists for. Three properties this method has, each
     * for that reason:
     *
     *   - the **reason is required** by the Form Request and stored in `note`, which Part D
     *     §20 gives this table as its one free-text column;
     *   - the audit row carries `old_value` and `new_value` in the same shape
     *     (`AttendanceRecord::auditValues()`), so a reader diffs them by eye;
     *   - a day with **no row yet** is an upsert, because an Admin marking an absence that the
     *     sweep has not swept yet is the same act as correcting one it has, and two endpoints
     *     for one act is two places for the audit row to be forgotten.
     *
     * `edited_by` is stamped on the row as well as recorded in the log. The log is the history;
     * the column is what lets a month grid mark a corrected day without a query per cell.
     *
     * @param  array{status: AttendanceStatus, clock_in: ?CarbonInterface, clock_out: ?CarbonInterface, note: string}  $attributes
     */
    public function edit(Employee $employee, CarbonInterface $date, array $attributes, User $actor): AttendanceRecord
    {
        $date = Carbon::parse($date)->startOfDay();

        // The same rule `AttendanceRecordPolicy::update()` refuses on, asserted here as well so
        // that no caller — a future command, a seeder, a console one-liner — can put an
        // attendance row on somebody the office clock does not track. This is the sentence
        // "Tapu is never Absent" is written in: with no row possible, no derivation and no
        // sweep can produce one.
        if (! $this->clocks($employee)) {
            throw AttendanceStateException::notOfficeAttendance($employee->user?->name ?? 'That employee');
        }

        if ($date->isFuture()) {
            throw AttendanceStateException::futureDay();
        }

        $clockIn = $attributes['clock_in'] === null ? null : $date->copy()->setTimeFrom(Carbon::parse($attributes['clock_in']));
        $clockOut = $attributes['clock_out'] === null ? null : $date->copy()->setTimeFrom(Carbon::parse($attributes['clock_out']));

        if ($clockIn !== null && $clockOut !== null && $clockOut->lessThan($clockIn)) {
            throw AttendanceStateException::clockOutBeforeClockIn();
        }

        return DB::transaction(function () use ($employee, $date, $attributes, $clockIn, $clockOut, $actor): AttendanceRecord {
            $record = AttendanceRecord::lockForDay((int) $employee->getKey(), $date);
            $creating = $record === null;

            $record ??= new AttendanceRecord([
                'employee_id' => $employee->getKey(),
                'date' => $date->toDateString(),
            ]);

            // Read BEFORE the write, and null on a day that had no row — which is what makes
            // "the Admin invented this day" and "the Admin changed this day" different rows in
            // the log rather than the same one.
            $old = $creating ? null : $record->auditValues();

            $record->status = $attributes['status'];
            $record->clock_in = $clockIn;
            $record->clock_out = $clockOut;
            $record->note = $attributes['note'];
            $record->edited_by = $actor->getKey();
            $record->save();

            $this->audit->record(
                AuditEvent::AttendanceEdited,
                $record,
                $old,
                $record->auditValues(),
                $actor,
            );

            return $record->fresh(['editor']) ?? $record;
        });
    }

    /**
     * The sweep's one write: mark a scheduled working day with no record Absent.
     *
     * Returns whether a row went in, so `hq:mark-absent` can report what it did without a
     * second query. Every reason to skip is asked here rather than in the command, so the rule
     * is one statement and the command is a loop: not tracked by the office clock, not a
     * working day, already has a record, or — the Phase 5 seam — covered by approved leave or a
     * holiday.
     */
    public function markAbsent(Employee $employee, CarbonInterface $date): bool
    {
        $date = Carbon::parse($date)->startOfDay();

        if ($employee->tracking_mode !== TrackingMode::OfficeAttendance) {
            return false;
        }

        if (! $this->isWorkingDay($employee->schedule, $date)) {
            return false;
        }

        if ($this->coveredByLeaveOrHoliday($employee, $date)) {
            return false;
        }

        // insertOrIgnore against unique(employee_id, date): somebody clocking in at 23:54 wins,
        // and a retried job writes nothing twice (decision 3-1).
        return AttendanceRecord::insertIfAbsent([
            'employee_id' => $employee->getKey(),
            'date' => $date->toDateString(),
            'status' => AttendanceStatus::Absent->value,
        ]);
    }

    // -----------------------------------------------------------------------------------
    // Reads
    // -----------------------------------------------------------------------------------

    /**
     * What this day was, for this employee. The single derivation.
     *
     * The order of the four questions is the rule, and it is not arbitrary:
     *
     *   1. **A record wins.** Somebody clocked in, an Admin wrote it, or the sweep did. Nothing
     *      derived may overrule a row that says what happened.
     *   2. **Off Day**, from the schedule. It comes before Remote so that Tapu's Friday reads
     *      as the day off it is, rather than as a remote day with no time on it: the schedule
     *      is the calendar for everybody, however their work is tracked.
     *   3. **Remote**, from `tracking_mode`. A remote-timer employee never clocks in, so a
     *      scheduled working day of theirs is Remote — and never Absent. The minutes beside it
     *      are `trackedMinutes()`, the seam.
     *   4. **No status.** A scheduled working day with no row yet: today before anybody
     *      arrived, and a past day the 23:55 sweep has not reached. It is printed as *No
     *      record*, never as Absent, because Absent is a word the sweep writes down and
     *      guessing it at 10 a.m. would put it on a pay record with nothing behind it.
     */
    public function dayFor(Employee $employee, CarbonInterface $date, ?AttendanceRecord $record = null): AttendanceDay
    {
        $date = Carbon::parse($date)->startOfDay();
        $today = Carbon::today();
        $scheduled = $this->isWorkingDay($employee->schedule, $date);

        $status = match (true) {
            $record !== null => $record->status,
            ! $scheduled => AttendanceStatus::OffDay,
            $employee->tracking_mode === TrackingMode::RemoteTimer => AttendanceStatus::Remote,
            default => null,
        };

        return new AttendanceDay(
            date: $date,
            status: $status,
            record: $record,
            workedMinutes: $record?->workedMinutes(),
            trackedMinutes: $employee->tracking_mode === TrackingMode::RemoteTimer
                ? $this->trackedMinutes($employee, $date)
                : null,
            scheduled: $scheduled,
            isToday: $date->isSameDay($today),
            isFuture: $date->greaterThan($today),
        );
    }

    /**
     * Every day of the month containing `$anyDayInMonth`, in order.
     *
     * One query for the month's rows and then one `dayFor()` per day — never a query per cell.
     * Days after today are included and carry `is_future`: a month grid that stopped at today
     * would reflow every morning, and the schedule already has something true to say about
     * next Tuesday.
     *
     * @return Collection<int, AttendanceDay>
     */
    public function month(Employee $employee, CarbonInterface $anyDayInMonth): Collection
    {
        $start = Carbon::parse($anyDayInMonth)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $records = AttendanceRecord::keyByDate(
            $employee->attendanceRecords()
                ->between($start, $end)
                ->with('editor')
                ->get()
                ->toBase(),
        );

        $days = new Collection;

        for ($day = $start->copy(); $day->lessThanOrEqualTo($end); $day->addDay()) {
            $days->push($this->dayFor($employee, $day, $records[$day->toDateString()] ?? null));
        }

        return $days;
    }

    /**
     * Today's roster: one row per tracked employee, in one query plus one per-employee
     * derivation.
     *
     * Scoped by `Employee::attendanceVisibleTo()`, which is the same scope the month page uses
     * — so a Manager's roster is their team and an Admin's is the agency, without this method
     * knowing either word.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function roster(?User $viewer, CarbonInterface $date): Collection
    {
        $date = Carbon::parse($date)->startOfDay();

        $employees = Employee::query()
            ->attendanceVisibleTo($viewer)
            ->tracked()
            ->with(['user', 'role', 'schedule'])
            ->join('users', 'users.id', '=', 'employees.user_id')
            ->orderBy('users.name')
            ->select('employees.*')
            ->get();

        $records = AttendanceRecord::query()
            ->forDate($date)
            ->whereIn('employee_id', $employees->modelKeys())
            ->with('editor')
            ->get()
            ->keyBy('employee_id');

        return $employees->map(function (Employee $employee) use ($date, $records, $viewer): array {
            $day = $this->dayFor($employee, $date, $records[$employee->getKey()] ?? null);

            return [
                'employee' => [
                    'id' => $employee->getKey(),
                    'name' => $employee->user?->name ?? 'Unknown',
                    'role' => $employee->role?->name,
                    'employee_number' => $employee->employee_number,
                    'tracking_mode' => $employee->tracking_mode->value,
                ],
                // Per row, resolved by the policy on the server — so the roster does not draw
                // an Edit control on a remote employee's Remote row, which is a control the
                // endpoint would refuse (DESIGN.md §5.11).
                'can_edit' => $viewer !== null
                    && Gate::forUser($viewer)->allows('update', [AttendanceRecord::class, $employee]),
                ...$day->toArray(),
            ];
        })->values();
    }

    /**
     * How many of the roster's rows carry each status, in Part D §8's order.
     *
     * Counts, and nothing but counts. It says four people are in and one is late; it does not
     * say who is doing well (Part H §1).
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return list<array{key: string, label: string, count: int}>
     */
    public function rosterSummary(Collection $rows): array
    {
        $counts = $rows->countBy(fn (array $row): string => (string) ($row['status'] ?? 'none'));

        return array_map(fn (AttendanceStatus $status): array => [
            'key' => $status->value,
            'label' => $status->label(),
            'count' => (int) $counts->get($status->value, 0),
        ], AttendanceStatus::cases());
    }

    /**
     * Only somebody on the office clock may press it. `TrackingMode::OfficeAttendance` is the
     * fact that decides, not the role name: both Admins, and Yaseen, clock in; Tapu's day is
     * the timer's and the Accountant's is tracked by nothing.
     */
    public function clocks(?Employee $employee): bool
    {
        return $employee?->tracking_mode === TrackingMode::OfficeAttendance;
    }

    private function assertClocks(Employee $employee): void
    {
        if (! $this->clocks($employee)) {
            throw AttendanceStateException::notClocked();
        }
    }
}
