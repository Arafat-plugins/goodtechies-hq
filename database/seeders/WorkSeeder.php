<?php

namespace Database\Seeders;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\HolidayService;
use App\Support\AttendanceStatus;
use App\Support\RoleName;
use App\Support\TimeEntryType;
use App\Support\TrackingMode;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Recorded work for the demo database: office attendance and tracked remote time (Phase 4,
 * master prompt Part D §7 and §8).
 *
 * ## Why this file exists at all
 *
 * Phase 4 shipped Attendance, Time, the Timesheet, the Workload page, the Company dashboard's
 * *Remote time today* card and the Employee dashboard's *Time today* card — and no seeder had
 * ever written a single `attendance_records` or `time_entries` row. Every one of those screens
 * opened on an empty state for everybody, including the client testing in a browser, and
 * Phase 10's Attendance and Time reports opened on *"No attendance was recorded in this
 * window."* This is the same class of gap decision **10-19** records for messages and files.
 *
 * ## The two tables are never merged, and who gets which is `tracking_mode`
 *
 * Part B §3 rule 6: `attendance_records` (office) and `time_entries` (remote) are separate
 * tables. Decision **4-11**: a remote-timer employee has **no attendance rows at all**. So this
 * seeder asks each employee's `tracking_mode` and nothing else — no name is compared here, and
 * moving somebody between the office clock and the timer changes what they are seeded with on
 * the next run without a list being edited. The Accountant, whose mode is `none` and who has no
 * schedule, is seeded neither, which falls out of the same branch rather than out of a special
 * case.
 *
 * ## Never a day that is not a working day
 *
 * `AttendanceService::isWorkingDay()` and `AttendanceService::coveredByLeaveOrHoliday()` are the
 * two predicates, asked of the service rather than restated here (decision 2-37). That is what
 * keeps the seeded week out of this file: the Sunday-to-Thursday week is `schedules.working_days`
 * data, the Eid block is the `holidays` table, and an approved leave window is
 * `leave_requests` — none of the three is spelled out below. Lateness is
 * `AttendanceService::isLate()` against `latestOnTime()`, so there is no literal clock time here
 * either; a Late day is built by arriving after the deadline the service computes, and the
 * status is then **derived** from the same predicate the clock-in path uses.
 *
 * ## Idempotence is keyed on identity
 *
 * `FinanceSeeder` once keyed its idempotence on a note, two notes were later made equal, two
 * rows merged and $560 vanished from a month Part D asserts. So the keys here are structural
 * and could not be made equal by an edit to a sentence:
 *
 *   - attendance is keyed on **`(employee_id, date)`**, which is the table's own unique index
 *     and the whole of its integrity model;
 *   - a time entry is keyed on **`client_uuid`**, the column the table already carries as its
 *     idempotency key and the thing that makes a replayed batch land on the row it created
 *     rather than a second one. It is a UUID v5 over `(employee number, date, slot)`, so it is
 *     the same value on every run of every machine and is not derived from any text.
 *
 * Re-running `db:seed` therefore changes nothing, which the launcher requires: `start-hq.bat`
 * seeds on every start.
 *
 * ## What is deliberately seeded, and why each one
 *
 *   - **Present, Late, Absent and one Half day.** A month of nothing but Present cannot show
 *     that the Attendance report's other columns work. Absent is what the 23:55 sweep writes —
 *     no clock times, nobody's edit on it. Half day is an Admin's word with a reason in `note`
 *     and `edited_by` set, because `half_day_auto` is off by default and that is the only way
 *     the status is ever reached.
 *   - **Approved and pending tracked time.** The `daily_work_summary` view counts
 *     `tracked_minutes` only where `approved_at is not null` and reports the rest as
 *     `pending_minutes` (decision **4-7**). Both are seeded, so the waiting-on-a-sign-off path
 *     renders on the Time report and the approval queue instead of being a code path nobody has
 *     seen. The most recent two days carry the pending entries — a queue is only believable
 *     when the thing waiting is recent.
 *   - **Mostly `auto`, with two `manual` entries.** A manual entry carries the reason it was
 *     typed in and an `approved_by`, because `settings.manual_time_requires_approval` is on by
 *     default and a manual entry therefore waits for a person (Part B §3 rule 5).
 *
 * Nothing here is a score, a ranking or a percentage of a target (Part H §1). Every figure is a
 * duration or a status, and no employee's number is divided by anybody else's.
 */
class WorkSeeder extends Seeder
{
    /**
     * The minutes an office day runs, before the half-day correction. Read from the employee's
     * own `schedules.working_hours_per_day`; this is only the lunch the clock stays running
     * through, so a clock-out is a plausible distance from its clock-in.
     */
    private const LUNCH_MINUTES = 45;

    /**
     * How late a Late arrival is, measured from `latestOnTime()` — the deadline the service
     * computes from the schedule and `settings.late_grace_minutes`. Not a clock time.
     */
    private const MINUTES_PAST_THE_DEADLINE = 22;

    /**
     * The lengths a tracked stretch comes in, cycled. Tapu's schedule is five hours a day, so
     * two of these is a plausible day and one is a short one.
     */
    private const STRETCH_MINUTES = [95, 130, 75, 150, 110, 60];

    /** The gap between two stretches on one day. */
    private const BREAK_MINUTES = 45;

    /** The shortest stretch worth seeding when the day has almost no room left in it. */
    private const SHORTEST_STRETCH_MINUTES = 15;

    public function run(): void
    {
        $attendance = app(AttendanceService::class);
        $holidays = app(HolidayService::class);

        $today = Carbon::today(config('app.timezone'))->startOfDay();
        $from = $today->copy()->subMonthNoOverflow()->startOfMonth();

        // One query for the whole window instead of one per employee per day — the service's
        // own pre-load, so `coveredByLeaveOrHoliday()` below is not 200 round trips.
        $holidays->prime($from, $today);

        /** @var Collection<int, Employee> $employees */
        $employees = Employee::query()
            ->with(['schedule', 'user'])
            ->orderBy('employee_number')
            ->get();

        $admin = $this->admin();
        $office = 0;

        foreach ($employees as $employee) {
            $days = $this->workingDays($attendance, $employee, $from, $today);

            if ($days === []) {
                continue;
            }

            // `tracking_mode`, never a name and never a role. See the class docblock.
            match ($employee->tracking_mode) {
                TrackingMode::OfficeAttendance => $this->seedAttendance($attendance, $employee, $days, $office++, $admin),
                TrackingMode::RemoteTimer => $this->seedTrackedTime($employee, $days, $admin),
                default => null,
            };
        }
    }

    /**
     * The days this employee actually works, inside the window.
     *
     * Both predicates come from `AttendanceService`. An off day, a public holiday and a day
     * inside an approved leave window are all absent from this list, so nothing downstream has
     * to remember to check: a seeder that wrote a Present on Eid would be a seeder that put a
     * word on somebody's record no job would ever have written.
     *
     * @return list<Carbon>
     */
    private function workingDays(AttendanceService $attendance, Employee $employee, Carbon $from, Carbon $to): array
    {
        $days = [];

        for ($day = $from->copy(); $day->lessThanOrEqualTo($to); $day->addDay()) {
            if (! $attendance->isWorkingDay($employee->schedule, $day)) {
                continue;
            }

            if ($attendance->coveredByLeaveOrHoliday($employee, $day)) {
                continue;
            }

            $days[] = $day->copy();
        }

        return $days;
    }

    // -----------------------------------------------------------------------------------
    // Office attendance
    // -----------------------------------------------------------------------------------

    /**
     * One row per working day for somebody on the office clock.
     *
     * `$ordinal` is the employee's place in the office list and is the only thing that makes
     * one person's month differ from another's: it shifts which day is Late, which is Absent and
     * which is the Half day, so the roster does not read as five identical columns. It is not a
     * grade and nothing compares one ordinal with another.
     *
     * @param  list<Carbon>  $days
     */
    private function seedAttendance(AttendanceService $attendance, Employee $employee, array $days, int $ordinal, ?User $admin): void
    {
        $schedule = $employee->schedule;
        $hours = (float) ($schedule?->working_hours_per_day ?? 8);
        $now = Carbon::now();

        // One Absent and one Half day **per calendar month**, not one per window. The reports
        // open on the current month, so a window whose only Absent day is in the month before it
        // is a window that still cannot show Absent is different from Present — which is the
        // whole reason either of them is seeded.
        [$absent, $halfDay] = $this->correctionDays($days, $ordinal);

        foreach ($days as $index => $date) {
            $key = ['employee_id' => $employee->getKey(), 'date' => $date->toDateString()];

            if (AttendanceRecord::query()->where($key)->exists()) {
                continue;
            }

            // Absent: what the 23:55 sweep writes. No clock times, nobody's edit on it.
            if (in_array($date->toDateString(), $absent, true)) {
                AttendanceRecord::create([...$key, 'status' => AttendanceStatus::Absent]);

                continue;
            }

            $late = ($index + $ordinal) % 9 === 4;
            $deadline = $attendance->latestOnTime($schedule, $date);

            $clockIn = $late && $deadline !== null
                ? $deadline->copy()->addMinutes(self::MINUTES_PAST_THE_DEADLINE)
                : $this->ordinaryArrival($schedule?->start_time, $date, $index);

            $half = in_array($date->toDateString(), $halfDay, true);

            $minutes = (int) round($hours * 60) + self::LUNCH_MINUTES;
            $clockOut = $clockIn->copy()->addMinutes($half ? (int) round($hours * 60 / 2) : $minutes);

            // A clock-out that has not happened yet is not a fact. Today's row is left open,
            // exactly as it would be at four in the afternoon.
            if ($clockOut->greaterThan($now)) {
                $clockOut = null;
            }

            // Derived, never asserted: the same predicate `clockIn()` asks. If an Admin ever
            // widens `late_grace_minutes`, a re-seeded database reads the new answer.
            $status = $attendance->isLate($schedule, $clockIn)
                ? AttendanceStatus::Late
                : AttendanceStatus::Present;

            AttendanceRecord::create([
                ...$key,
                'clock_in' => $clockIn,
                'clock_out' => $clockOut,
                // Half day is an Admin's word (`half_day_auto` is off by default), so it
                // arrives the only way it can: with a reason and the Admin who wrote it.
                'status' => $half ? AttendanceStatus::HalfDay : $status,
                'note' => $half ? 'Left at midday for a dentist appointment. Agreed in advance.' : null,
                'edited_by' => $half ? $admin?->getKey() : null,
            ]);
        }
    }

    /**
     * The two days in each calendar month that are not an ordinary Present: the Absent day the
     * nightly sweep wrote, and the Half day an Admin corrected.
     *
     * Per month rather than per window, for the reason in the caller. `$ordinal` shifts both, so
     * no two people are away on the same day and the roster does not read as one column copied
     * across. **Today is never either of them**: the 23:55 sweep has not run yet, and a correction
     * to a day somebody is still working is not a correction anybody could have made.
     *
     * @param  list<Carbon>  $days
     * @return array{list<string>, list<string>}
     */
    private function correctionDays(array $days, int $ordinal): array
    {
        $absent = [];
        $halfDay = [];

        $months = [];

        foreach ($days as $day) {
            if ($day->isToday()) {
                continue;
            }

            $months[$day->format('Y-m')][] = $day->toDateString();
        }

        foreach ($months as $inMonth) {
            if (isset($inMonth[3 + $ordinal])) {
                $absent[] = $inMonth[3 + $ordinal];
            }

            if (isset($inMonth[6 + $ordinal])) {
                $halfDay[] = $inMonth[6 + $ordinal];
            }
        }

        return [$absent, $halfDay];
    }

    /**
     * An arrival on an ordinary morning: a few minutes either side of the schedule's own
     * `start_time`, never a literal hour. A schedule with no start time — a flexible one — is
     * arrived at when the day begins, which is the honest reading of a schedule that names no
     * hour, and `isLate()` answers false for it anyway.
     */
    private function ordinaryArrival(?string $startTime, Carbon $date, int $index): Carbon
    {
        $clockIn = $date->copy()->startOfDay();

        if ($startTime === null) {
            return $clockIn;
        }

        return $clockIn
            ->setTimeFrom(Carbon::parse($startTime))
            // -4 to +4 minutes, so no two mornings are the same minute and none of them is a
            // pattern anybody could read something into.
            ->addMinutes($index % 9 - 4);
    }

    // -----------------------------------------------------------------------------------
    // Tracked remote time
    // -----------------------------------------------------------------------------------

    /**
     * Stretches of tracked work for somebody the timer tracks. **No attendance row is written
     * here and none anywhere else** — decision 4-11.
     *
     * @param  list<Carbon>  $days
     */
    private function seedTrackedTime(Employee $employee, array $days, ?User $admin): void
    {
        $tasks = Task::query()
            ->notArchived()
            ->whereNotNull('project_id')
            ->whereHas('assignees', fn ($q) => $q->where('employees.id', $employee->getKey()))
            ->orderBy('id')
            ->get(['id', 'project_id']);

        if ($tasks->isEmpty()) {
            // Time is always tracked against a task (`task_id` is not nullable), so an employee
            // with no tasks gets no entries rather than a row with an invented one.
            $this->command?->warn(sprintf(
                'WorkSeeder: %s has no tasks, so no tracked time was seeded for them.',
                $employee->user?->name ?? $employee->employee_number,
            ));

            return;
        }

        $last = count($days) - 1;

        foreach ($days as $index => $date) {
            // The last two days always run two stretches, so the newest day has one signed-off
            // figure for the dashboard card AND one waiting for an Admin below it.
            $count = $index >= $last - 1 || $index % 3 !== 2 ? 2 : 1;

            foreach ($this->stretches($date, $count, $index) as $slot => [$startedAt, $endedAt]) {
                $uuid = $this->entryKey($employee, $date, $slot);

                if (TimeEntry::query()->where('client_uuid', $uuid)->exists()) {
                    continue;
                }

                $task = $tasks[($index * 2 + $slot) % $tasks->count()];

                // The two manual entries: an afternoon the timer was not running, typed in
                // afterwards and signed off by an Admin the next morning.
                $manual = $slot === 1 && ($index === 6 || $index === 15);

                // Waiting for a decision: the newest two days' second stretch. Nothing has
                // rejected it — an entry nobody has ruled on has neither timestamp.
                $pending = $slot === 1 && $index >= $last - 1;

                TimeEntry::create([
                    'employee_id' => $employee->getKey(),
                    'task_id' => $task->getKey(),
                    'project_id' => $task->project_id,
                    'work_date' => $date->toDateString(),
                    'started_at' => $startedAt,
                    'ended_at' => $endedAt,
                    'duration_seconds' => (int) $startedAt->diffInSeconds($endedAt),
                    'entry_type' => $manual ? TimeEntryType::Manual : TimeEntryType::Auto,
                    'client_uuid' => $uuid,
                    // Only a running timer pings. A typed entry never did.
                    'last_heartbeat_at' => $manual ? null : $endedAt,
                    // Rule 5: a manual entry carries the reason it was typed in.
                    'reason' => $manual
                        ? 'Worked from the client office and the timer was not running. Hours taken from my own notes.'
                        : null,
                    ...$this->approval($manual, $pending, $endedAt, $admin),
                ]);
            }
        }

        // Every task that HAS entries, not only the ones this run inserted. `TaskSeeder`
        // rewrites `tracked_seconds` from its own hard-coded list on every reseed — the column
        // is refreshed there deliberately, like the dates beside it — so a WorkSeeder that only
        // recomputed on the run that inserted the rows would leave the second run of
        // `db:seed` reading a figure with no entries behind it. Re-running must change nothing,
        // and that includes the caches of the rows this seeder owns.
        $withEntries = TimeEntry::query()
            ->whereIn('task_id', $tasks->modelKeys())
            ->distinct()
            ->pluck('task_id');

        foreach ($withEntries as $taskId) {
            $this->refreshTaskTotal((int) $taskId);
        }
    }

    /**
     * Where a decision on these hours stands. Decision 4-7: `approved_at` is the one predicate
     * every total asks, and `approved_by` says who — null means the system signed off an auto
     * entry the moment it stopped, and a name means a person did.
     *
     * A pending entry has neither timestamp, which is what "nobody has looked at it yet" is.
     *
     * @return array<string, mixed>
     */
    private function approval(bool $manual, bool $pending, Carbon $endedAt, ?User $admin): array
    {
        if ($pending) {
            return ['approved_at' => null, 'approved_by' => null];
        }

        if (! $manual) {
            return ['approved_at' => $endedAt, 'approved_by' => null];
        }

        // `settings.manual_time_requires_approval` is on by default, so a typed entry waits for
        // a person. This one waited until the next morning.
        return [
            'approved_at' => $endedAt->copy()->addDay()->setTime(9, 30),
            'approved_by' => $admin?->getKey(),
        ];
    }

    /**
     * The day's stretches, laid out **backwards** from the end of the working stretch.
     *
     * Backwards, because the one day that cannot be laid out forwards is today: an entry must
     * never end in the future, and `db:seed` runs on every launch — including at nine in the
     * morning. Anchoring on the last moment that has actually happened means today is seeded the
     * same way as every other day rather than through a special case, and a day with no room
     * left in it simply yields fewer stretches.
     *
     * @return list<array{Carbon, Carbon}>
     */
    private function stretches(Carbon $date, int $count, int $index): array
    {
        $dayStart = $date->copy()->startOfDay();
        $anchor = $date->isToday()
            ? Carbon::now()->copy()->subMinutes(10)
            : $date->copy()->setTime(17, 30);

        if ($anchor->lessThanOrEqualTo($dayStart->copy()->addMinutes(self::SHORTEST_STRETCH_MINUTES))) {
            return [];
        }

        $stretches = [];
        $cursor = $anchor->copy();

        for ($slot = $count - 1; $slot >= 0; $slot--) {
            $minutes = self::STRETCH_MINUTES[($index * 2 + $slot) % count(self::STRETCH_MINUTES)];
            $startedAt = $cursor->copy()->subMinutes($minutes);

            if ($startedAt->lessThan($dayStart)) {
                // What is left of the day, if it is worth anything at all. Never a stretch that
                // began yesterday wearing today's `work_date`.
                if ($cursor->diffInMinutes($dayStart, true) < self::SHORTEST_STRETCH_MINUTES) {
                    break;
                }

                $startedAt = $dayStart->copy();
            }

            $stretches[$slot] = [$startedAt, $cursor->copy()];
            $cursor = $startedAt->copy()->subMinutes(self::BREAK_MINUTES);
        }

        ksort($stretches);

        return array_values($stretches);
    }

    /**
     * The entry's identity: a UUID v5 over the employee's number, the day and the slot.
     *
     * Not random, so a re-seed finds the row it wrote last time; not derived from any sentence,
     * so no later edit to a reason can make two keys equal — which is the whole of rule 6 and
     * the whole of what went wrong in `FinanceSeeder`.
     */
    private function entryKey(Employee $employee, Carbon $date, int $slot): string
    {
        return Uuid::uuid5(
            Uuid::NAMESPACE_URL,
            sprintf('goodtechies-hq/work-seeder/%s/%s/%d', $employee->employee_number, $date->toDateString(), $slot),
        )->toString();
    }

    /**
     * `tasks.tracked_seconds` is a CACHE of `time_entries`, recomputed and never incremented —
     * TimerService says so, and its rule is that the first real entry on a task takes the column
     * over from whatever Phase 2 seeded on it. These are the first real entries, so the column
     * follows them; leaving TaskSeeder's hand-written figure beside four real hours would be a
     * second, drifting statement of a number this table now holds.
     *
     * The predicate is not restated: `stopped()` and `counted()` are the model's own scopes, the
     * same two TimerService asks. Written with the query builder because a task's status guard
     * (decision 2-9) fires on a model save and a time entry is not a status move.
     */
    private function refreshTaskTotal(int $taskId): void
    {
        $seconds = (int) TimeEntry::query()
            ->where('task_id', $taskId)
            ->stopped()
            ->counted()
            ->sum('duration_seconds');

        DB::table('tasks')->where('id', $taskId)->update(['tracked_seconds' => $seconds]);
    }

    /**
     * Whoever signs off a manual entry and writes a half-day correction. The first Admin by
     * employee number, asked of the role rather than named: a database with a different Admin in
     * it seeds the same way, and one with no Admin at all seeds the rows with nobody on them
     * rather than failing.
     */
    private function admin(): ?User
    {
        return User::query()
            ->whereHas('employee.role', fn ($q) => $q->where('name', RoleName::ADMIN->value))
            ->join('employees', 'employees.user_id', '=', 'users.id')
            ->orderBy('employees.employee_number')
            ->select('users.*')
            ->first();
    }
}
