<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Services\AttendanceService;
use App\Support\TrackingMode;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * 23:55 app time: every office employee whose schedule says today was a working day and who
 * has no record gets one, status Absent (master prompt Part D §8, Phase 4).
 *
 * ## It marks one thing and skips four
 *
 * The rule is `AttendanceService::markAbsent()`'s, not this command's — this is a loop and a
 * report. The four reasons a day is skipped all live there, so the sweep, the roster and the
 * month grid cannot develop different ideas about what a working day is:
 *
 *   1. **Not on the office clock.** The query below asks for `tracking_mode =
 *      office_attendance`, and the service asks again. That is why **Tapu is never absent**:
 *      he is `remote_timer`, his day is derived as Remote, and no role name appears in either
 *      place. The Accountant is `none` and is out for the same reason.
 *   2. **Not a working day.** `schedules.working_days`. Friday and Saturday are off in the
 *      seed, and that is data — an employee whose schedule says Friday is a working day is
 *      marked on a Friday, and one whose schedule has no Sunday is not marked on a Sunday.
 *      An employee with no schedule at all is never marked.
 *   3. **Already has a record.** Somebody clocked in, or an Admin wrote the day. The write is
 *      `insertOrIgnore` against `unique(employee_id, date)`, so a clock-in at 23:54 wins and a
 *      retried run writes nothing twice (decision 3-1).
 *   4. **Covered by approved leave or a holiday** — **the Phase 5 seam**. It is
 *      `AttendanceService::coveredByLeaveOrHoliday()`, returns false today, and is called from
 *      exactly one place: `markAbsent()`. Phase 5 fills that method's body with the
 *      `leave_requests` and `holidays` lookups and changes nothing here — not this loop, not
 *      the query, not a test that does not name leave.
 *
 * ## Why `--as-of` rather than a call to today() inside the query
 *
 * The same reason `hq:flag-overdue` has one: it is what makes the rule testable at a fixed
 * date, and it is what lets an operator sweep a night the scheduler missed. An unparseable
 * value falls back to today rather than throwing, because a cron entry with a typo in it
 * should still mark the day.
 *
 * A **future** date is refused outright. Marking tomorrow absent at 23:55 tonight would put a
 * word on somebody's pay record for a day they have not had yet, and no typo is worth that.
 */
#[Signature('hq:mark-absent {--as-of= : The date to sweep (default: today)}')]
#[Description('Mark scheduled working days with no attendance record as Absent')]
class MarkAbsent extends Command
{
    public function handle(AttendanceService $attendance): int
    {
        $asOf = $this->asOf();

        if ($asOf->greaterThan(Carbon::today())) {
            $this->error(sprintf('%s has not happened yet; nothing swept.', $asOf->toDateString()));

            return self::FAILURE;
        }

        // Only the office clock. The service asks the same question again — this is the query
        // that keeps the sweep from loading the whole agency, not the enforcement.
        $employees = Employee::query()
            ->where('employees.tracking_mode', TrackingMode::OfficeAttendance->value)
            ->tracked()
            ->with(['user', 'schedule'])
            ->orderBy('employees.id')
            ->get();

        $marked = 0;
        $skipped = 0;

        foreach ($employees as $employee) {
            if ($attendance->markAbsent($employee, $asOf)) {
                $marked++;

                continue;
            }

            $skipped++;
        }

        $this->info(sprintf(
            '%s: %d office employee%s considered, %d marked absent, %d left alone.',
            $asOf->toDateString(),
            $employees->count(),
            $employees->count() === 1 ? '' : 's',
            $marked,
            $skipped,
        ));

        return self::SUCCESS;
    }

    private function asOf(): Carbon
    {
        $value = trim((string) ($this->option('as-of') ?? ''));

        if ($value === '') {
            return Carbon::today();
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            $this->warn(sprintf('Could not read --as-of=%s; sweeping today.', $value));

            return Carbon::today();
        }
    }
}
