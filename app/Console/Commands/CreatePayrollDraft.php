<?php

namespace App\Console\Commands;

use App\Models\PayrollPeriod;
use App\Services\PayrollService;
use App\Support\PayrollStatus;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * On the 1st: one payroll period for the month, and one line per active employee from their
 * current salary (master prompt Part D §14, *"Draft auto-created on the 1st for all active
 * employees from `employee_salaries`"*).
 *
 * ## A command, not a job — the repo had already decided this
 *
 * `routes/console.php` is this application's whole schedule and every recurring thing in it is
 * an `hq:*` command. A queued job would need a scheduled command to dispatch it anyway, so the
 * choice is not *command vs job* but *command, or command plus job*; the extra hop buys
 * something only when the work is long or must survive a worker restart mid-run, and this is
 * one insert plus one per employee on a team of five, on a single VPS. So: a command,
 * `withoutOverlapping()`, like its six siblings.
 *
 * ## How "exactly once, however often this runs" is guaranteed
 *
 * **The rows are the memory.** `hq:remind-meetings` solved the same problem in Phase 7 by asking
 * the notifications table instead of keeping a flag (decision 7-5), and the reasoning transfers
 * whole — except that here it is stronger, because the memory is not a query the command has to
 * remember to run, it is two unique indexes the database enforces against every writer:
 *
 *   - **`payroll_periods.month` is UNIQUE.** A month has one period. This command creates one
 *     with `onlyIfMissing`, so a second run on the 1st finds it and stops.
 *   - **`payroll_items (payroll_period_id, employee_id)` is UNIQUE.** A person has one line in
 *     a month, so nothing can be doubled even if the period creation and the item creation were
 *     somehow to interleave.
 *
 * The three mechanisms that were available, and why this is the third:
 *
 *   1. **A `payroll_periods.drafted_at` column.** Rejected: a second record of a fact the row's
 *      own existence already states, able to disagree with it after a rollback or a restore —
 *      and the fourth stored copy of a derived fact this repo has refused (decisions 7-3, 7-5).
 *   2. **A cache lock or `Cache::add()` key.** Rejected: Redis is a cache here, not a system of
 *      record. A `FLUSHALL`, an eviction or a restart would let the next run draft the month
 *      again — and "again" means a second set of payslips for a month the Accountant may
 *      already have started balancing.
 *   3. **The unique indexes.** Durable, enforced against a seeder, a re-run, a restore and
 *      somebody at `psql` alike, and nothing new is invented.
 *
 * So: running it twice on the same day does nothing the second time, and running it on a month
 * that already has a period does nothing at all — **including when that period has moved on**.
 * A period that is calculated, approved or locked is emphatically not topped up: the whole point
 * of `--month` and of an idempotent draft is that a stray cron run in the middle of the month
 * cannot add a line to a payroll somebody has already signed off.
 *
 * ## What it does NOT do
 *
 * It does not calculate. Part D §14 gives Calculate to the Accountant, as a decision somebody
 * makes after filling in bonuses and deductions; a command that pressed it would write a
 * `leave_impact` from leave approved on the 1st, over figures nobody had entered yet.
 *
 * It does not skip somebody for having no schedule, no attendance or no tasks. The only test is
 * `employees.status = 'active'` (Part D §21) and having a salary on record — and the people it
 * skips for want of a salary are **named in the output**, because a payroll that silently
 * misses somebody is the failure this command exists to prevent.
 */
#[Signature('hq:create-payroll-draft {--month= : The month to draft (default: the current one)}')]
#[Description('Create the month\'s payroll draft with one line per active employee')]
class CreatePayrollDraft extends Command
{
    public function handle(PayrollService $payroll): int
    {
        $month = PayrollPeriod::monthKey($this->month());

        $existing = PayrollPeriod::query()->forMonth($month)->first();

        if ($existing !== null) {
            $this->info(sprintf(
                '%s already has a payroll period (%s); nothing to do.',
                $month->format('F Y'),
                $existing->status?->label() ?? 'unknown',
            ));

            return self::SUCCESS;
        }

        // No actor: a scheduled command is not a person and holds no permissions. The
        // authorization for it is the schedule in routes/console.php. See
        // PayrollService::createDraft().
        $period = $payroll->createDraft(null, $month, onlyIfMissing: true);

        $items = $period->items()->count();
        $skipped = $payroll->employeesWithoutSalaryAt($month);

        $this->info(sprintf(
            'Drafted %s: %d payroll %s created.',
            $month->format('F Y'),
            $items,
            $items === 1 ? 'line' : 'lines',
        ));

        if ($skipped !== []) {
            $this->warn(sprintf(
                'No salary on record as at %s, so no line was created for: %s.',
                $month->toDateString(),
                implode(', ', $skipped),
            ));
        }

        if ($period->status !== PayrollStatus::Draft) {
            // Cannot happen — a period is born Draft — but a payroll command that is wrong
            // about the state it left a month in should say so rather than print success.
            $this->warn('The new period is not in Draft. Check PayrollService::createDraft().');
        }

        return self::SUCCESS;
    }

    /**
     * The month to draft. A parameter rather than a call to `now()` inside the query, for the
     * reason `hq:flag-overdue`'s `--as-of` is one: it is what makes this testable at a fixed
     * month. An unparseable value falls back to the current month rather than throwing — a cron
     * entry with a typo in it should still draft the payroll.
     */
    private function month(): Carbon
    {
        $value = trim((string) ($this->option('month') ?? ''));

        if ($value === '') {
            return Carbon::now();
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            $this->warn(sprintf('Could not read --month=%s; drafting the current month.', $value));

            return Carbon::now();
        }
    }
}
