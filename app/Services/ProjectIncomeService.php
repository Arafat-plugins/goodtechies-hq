<?php

namespace App\Services;

use App\Exceptions\FinanceStateException;
use App\Models\FinanceCategory;
use App\Models\Income;
use App\Models\PayrollPeriod;
use App\Models\Project;
use App\Models\ProjectFinance;
use App\Models\ProjectIncomeRun;
use App\Models\TimeEntry;
use App\Models\User;
use App\Support\AuditEvent;
use App\Support\BillingFrequency;
use App\Support\FinanceCategoryKind;
use App\Support\ProjectStatus;
use App\Support\ProjectType;
use App\Support\RoleName;
use App\Support\UserStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Income recorded automatically from each project's billing settings (client request: "income
 * will come from clients project … and if the admin adds extra money that stays too").
 *
 *   - **recurring** (monthly / quarterly / yearly with a recurring amount, Active projects
 *     only): the amount on every due date — every 1, 3 or 12 months from the project's
 *     `start_date` (or the finance row's `created_at`), the same anchor and step as
 *     `ProjectEarnings`;
 *   - **hourly** (an hourly rate, any status but Cancelled or Archived): once a month, as soon
 *     as the month has ended (the first run of the next month), dated the month's last day: the
 *     month's **approved** hours × the rate, in cents by
 *     `intdiv(seconds × rateCents + 1800, 3600)` — again the `ProjectEarnings` rule;
 *   - **one-time** (a price and neither of the above): the price, once, when the project is
 *     marked Completed.
 *
 * Nothing before `project_finance.auto_income_from` is ever billed, and a project with
 * `auto_income` off is never billed at all. Every billed period leaves one `project_income_runs`
 * row, so running this again records nothing new and a deleted auto income is never recreated.
 * A period whose month is locked (payroll locked or paid) is skipped and not retried — the same
 * rule every hand-entered income obeys. Hand-entered income stays exactly as it was
 * (`FinanceService::recordIncome`, `source` 'manual'); both kinds live in the same ledger.
 */
class ProjectIncomeService
{
    /** Billing frequencies that repeat, and their step in months (as ProjectEarnings). */
    private const STEPS = [
        'monthly' => 1,
        'quarterly' => 3,
        'yearly' => 12,
    ];

    /** Hourly billing never reaches further back than this many months. */
    private const HOURLY_MAX_MONTHS = 12;

    public function __construct(
        private readonly FinanceService $finance,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Record every automatic income that has fallen due up to `$today`. Idempotent.
     *
     * @return int the number of income rows recorded
     */
    public function generate(Carbon $today): int
    {
        $today = $today->copy()->startOfDay();
        $recorded = 0;

        $projects = Project::query()
            ->whereHas('finance', fn (Builder $finance) => $finance->where('auto_income', true))
            ->with(['finance', 'pm.user'])
            ->orderBy('id')
            ->get();

        foreach ($projects as $project) {
            /** @var ProjectFinance $finance */
            $finance = $project->finance;
            $type = $this->typeOf($finance);
            $from = $this->autoIncomeFrom($finance);

            // One project that cannot be billed (no income category, no Admin to record as) must
            // not stop every other project's income: report it and carry on.
            try {
                if ($type === 'recurring' && $project->status === ProjectStatus::Active) {
                    $recorded += $this->generateRecurring($project, $finance, $from, $today);
                }

                if (
                    $type === 'hourly'
                    && $project->status !== ProjectStatus::Cancelled
                    && $project->status !== ProjectStatus::Archived
                ) {
                    $recorded += $this->generateHourly($project, $finance, $from, $today);
                }
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        return $recorded;
    }

    /**
     * The one-time price, recorded once when the project is completed. Null when the project is
     * not billed that way, automatic income is off, or a one-time income was already billed.
     */
    public function recordOnCompletion(Project $project): ?Income
    {
        /** @var ProjectFinance|null $finance */
        $finance = $project->finance()->first();

        if ($finance === null || ! $finance->auto_income || $this->typeOf($finance) !== 'one_time') {
            return null;
        }

        $billed = ProjectIncomeRun::query()
            ->where('project_id', $project->getKey())
            ->where('kind', 'one_time')
            ->exists();

        if ($billed) {
            return null;
        }

        $today = Carbon::today(config('app.timezone'));

        return $this->record(
            $project,
            'one_time',
            $today,
            $today,
            self::fromCents(self::cents($finance->price)),
            'One-time price — project completed',
        );
    }

    /**
     * The income category a project's automatic income is filed under, by name; the first
     * income category when that name does not exist.
     */
    public function categoryFor(Project $project): int
    {
        $name = match ($project->project_type) {
            ProjectType::Seo => 'SEO',
            ProjectType::WebsiteMaintenance => 'Maintenance',
            ProjectType::WebsiteDevelopment, ProjectType::WooCommerce, ProjectType::WebApplication => 'Website',
            default => 'Other',
        };

        $id = FinanceCategory::query()
            ->ofKind(FinanceCategoryKind::Income)
            ->where('name', $name)
            ->value('id');

        $id ??= FinanceCategory::query()
            ->ofKind(FinanceCategoryKind::Income)
            ->inOrder()
            ->value('id');

        if ($id === null) {
            throw new RuntimeException('There is no income category to record project income under.');
        }

        return (int) $id;
    }

    /**
     * Who an automatic income is recorded by: the project manager's user, else the first
     * active Admin.
     */
    public function recorderFor(Project $project): int
    {
        $pmUser = $project->pm?->user;

        if ($pmUser !== null) {
            return (int) $pmUser->getKey();
        }

        $admin = User::query()
            ->where('status', UserStatus::Active->value)
            ->whereHas('employee.role', fn (Builder $role) => $role->where('name', RoleName::ADMIN->value))
            ->orderBy('id')
            ->value('id');

        if ($admin === null) {
            throw new RuntimeException('There is no active Admin to record project income as.');
        }

        return (int) $admin;
    }

    private function generateRecurring(Project $project, ProjectFinance $finance, Carbon $from, Carbon $today): int
    {
        $frequency = (string) $finance->billing_frequency;
        $step = self::STEPS[$frequency];
        $anchor = Carbon::parse($project->start_date ?? $finance->created_at)->startOfDay();
        $label = BillingFrequency::tryFrom($frequency)?->label() ?? ucfirst($frequency);
        $amount = self::fromCents(self::cents($finance->recurring_amount));
        $recorded = 0;

        for ($k = 0; ; $k++) {
            $due = $anchor->copy()->addMonthsNoOverflow($k * $step);

            if ($due->gt($today)) {
                break;
            }

            if ($due->lt($from)) {
                continue;
            }

            $income = $this->record(
                $project,
                'recurring',
                $due,
                $due,
                $amount,
                'Recurring — '.$due->format('M Y').' ('.$label.')',
            );

            if ($income !== null) {
                $recorded++;
            }
        }

        return $recorded;
    }

    private function generateHourly(Project $project, ProjectFinance $finance, Carbon $from, Carbon $today): int
    {
        $rateCents = self::cents($finance->hourly_rate);

        // The last month that has ended (owner's rule, 2026-10-14: hourly income is booked at the
        // end of the month, not on the 5th of the next one). Hours approved after that run are
        // not in it and are added by hand as extra income.
        $last = $today->copy()->startOfMonth()->subMonthNoOverflow();

        $first = $from->copy()->startOfMonth();
        $cap = $last->copy()->subMonthsNoOverflow(self::HOURLY_MAX_MONTHS - 1);

        if ($first->lt($cap)) {
            $first = $cap;
        }

        $recorded = 0;

        for ($month = $first->copy(); $month->lte($last); $month->addMonthNoOverflow()) {
            $start = $month->copy()->startOfMonth();
            $end = $month->copy()->endOfMonth()->startOfDay();

            $seconds = (int) TimeEntry::query()
                ->tracked()
                ->where('time_entries.project_id', $project->getKey())
                ->whereBetween('time_entries.work_date', [$start->toDateString(), $end->toDateString()])
                ->sum('time_entries.duration_seconds');

            $cents = intdiv($seconds * $rateCents + 1800, 3600);
            $hours = self::fromCents(intdiv($seconds * 100 + 1800, 3600));

            $income = $this->record(
                $project,
                'hourly',
                $start,
                $end,
                self::fromCents($cents),
                'Hourly — '.$start->format('M Y').": {$hours} approved hours × ".self::fromCents($rateCents).'/hr',
            );

            if ($income !== null) {
                $recorded++;
            }
        }

        return $recorded;
    }

    /**
     * Bill one period once: a zero amount and a locked month are logged and skipped, anything
     * else becomes an `auto` income, audited exactly as `FinanceService::recordIncome` audits.
     */
    private function record(Project $project, string $kind, Carbon $period, Carbon $date, string $amount, string $notes): ?Income
    {
        return DB::transaction(function () use ($project, $kind, $period, $date, $amount, $notes): ?Income {
            $key = [
                'project_id' => $project->getKey(),
                'kind' => $kind,
                'period' => $period->toDateString(),
            ];

            $exists = ProjectIncomeRun::query()
                ->where('project_id', $key['project_id'])
                ->where('kind', $kind)
                ->whereDate('period', $key['period'])
                ->exists();

            if ($exists) {
                return null;
            }

            if (self::cents($amount) === 0) {
                ProjectIncomeRun::create($key + ['income_id' => null, 'outcome' => 'skipped_zero']);

                return null;
            }

            // Lock the month's payroll row (if any) before reading its status, as the recurring
            // expense generator does, so a month locked concurrently is respected.
            DB::table('payroll_periods')
                ->whereDate('month', PayrollPeriod::monthKey($date)->toDateString())
                ->lockForUpdate()
                ->first();

            try {
                $this->finance->assertPeriodIsOpen($date);
            } catch (FinanceStateException) {
                ProjectIncomeRun::create($key + ['income_id' => null, 'outcome' => 'skipped_locked']);

                return null;
            }

            $recorder = User::query()->findOrFail($this->recorderFor($project));

            $income = Income::create([
                'category_id' => $this->categoryFor($project),
                'project_id' => $project->getKey(),
                'amount' => $amount,
                'date' => $date->toDateString(),
                'notes' => $notes,
                'recorded_by' => $recorder->getKey(),
                'source' => 'auto',
            ]);

            $this->audit->record(
                AuditEvent::IncomeCreated,
                $income,
                null,
                $income->fresh(['category', 'project'])->auditValues(),
                $recorder,
            );

            ProjectIncomeRun::create($key + ['income_id' => $income->getKey(), 'outcome' => 'recorded']);

            return $income;
        });
    }

    /** The billing type, by the same rule as ProjectEarnings: hourly, recurring, one_time or none. */
    private function typeOf(ProjectFinance $finance): string
    {
        $frequency = $finance->billing_frequency === null ? null : (string) $finance->billing_frequency;

        return match (true) {
            $frequency === BillingFrequency::Hourly->value && self::cents($finance->hourly_rate) > 0 => 'hourly',
            $frequency !== null && isset(self::STEPS[$frequency]) && self::cents($finance->recurring_amount) > 0 => 'recurring',
            self::cents($finance->price) > 0 => 'one_time',
            default => 'none',
        };
    }

    /** The first day automatic income may bill; the finance row's creation day when unset. */
    private function autoIncomeFrom(ProjectFinance $finance): Carbon
    {
        $from = $finance->auto_income_from ?? $finance->created_at ?? Carbon::today(config('app.timezone'));

        return Carbon::parse($from)->startOfDay();
    }

    /** An exact decimal string to integer cents, never through a float (as ProjectEarnings). */
    private static function cents(mixed $amount): int
    {
        if ($amount === null || $amount === '') {
            return 0;
        }

        $value = trim((string) $amount);
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '+-');

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $fraction = str_pad($fraction, 3, '0');

        $cents = ((int) ($whole === '' ? '0' : $whole)) * 100 + (int) substr($fraction, 0, 2);

        if ((int) $fraction[2] >= 5) {
            $cents++;
        }

        return $negative ? -$cents : $cents;
    }

    /** Integer cents to an exact two-decimal string. */
    private static function fromCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $abs = abs($cents);

        return $sign.intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }
}
