<?php

namespace App\Support;

use App\Models\TimeEntry;
use App\Services\SettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What each project SHOULD have earned in a month, what it DID earn, what it cost, and the profit
 * (client request: "how much income comes from each project every month, and how").
 *
 * Computed, never stored, like every other money figure here. Four grouped queries whatever the
 * number of projects: income by project, expenses by project, approved hours by project, and the
 * projects themselves with their `project_finance` row. Every amount is handled in **integer
 * cents** parsed from the exact decimal strings PostgreSQL sends — never a float — and goes out
 * as an exact two-decimal string.
 *
 * ## Which projects appear
 *
 * Every project that has a `project_finance` row, plus every project with income or an expense
 * linked to it and dated in the month. Cancelled and Archived projects never get an *expected*
 * figure, but still show what they received and cost.
 *
 * ## The type, from `project_finance`
 *
 *   - **hourly** — `billing_frequency` is `hourly` and `hourly_rate` > 0;
 *   - **recurring** — `billing_frequency` is monthly, quarterly or yearly and
 *     `recurring_amount` > 0;
 *   - **one_time** — otherwise, `price` > 0;
 *   - **none** — nothing to bill from.
 *
 * ## Expected, per type
 *
 *   - **recurring**: every 1, 3 or 12 months counted from the anchor — the project's
 *     `start_date`, or the finance row's `created_at` when there is none — taken as a month.
 *     The amount is expected in a month that is on or after the anchor month and a whole number
 *     of steps from it; in any other month it is null and the basis names the next due month.
 *     **Only Active projects** expect recurring income.
 *   - **hourly**: the month's **approved** hours on the project (`TimeEntry::tracked()`, by
 *     `work_date`) × the hourly rate. Cents are `intdiv(seconds × rateCents + 1800, 3600)` —
 *     rounded half up once, on the total, in integers.
 *   - **one_time**: nothing monthly. The project is listed under `contracts` instead: the price
 *     against all income ever linked to it, and the balance (negative = overpaid).
 *   - **none**: nothing.
 *
 * ## The other columns
 *
 * `received` is income linked to the project dated in the month; `costs` is expenses linked to
 * it dated in the month, and `billable_costs` the part of those flagged billable. `profit` is
 * received − costs. `outstanding` is max(expected − received, 0), and '0.00' when nothing is
 * expected.
 *
 * Rows are sorted by expected, largest first (nothing expected last), then by name.
 */
final class ProjectEarnings
{
    /** Billing frequencies that repeat, and their step in months. */
    private const STEPS = [
        'monthly' => 1,
        'quarterly' => 3,
        'yearly' => 12,
    ];

    /**
     * @return array{
     *     month: string,
     *     rows: list<array{
     *         project: array{id: int, name: string, domain: string|null},
     *         type: string,
     *         type_label: string,
     *         basis: string,
     *         expected: string|null,
     *         hours: string|null,
     *         rate: string|null,
     *         received: string,
     *         costs: string,
     *         billable_costs: string,
     *         profit: string,
     *         outstanding: string,
     *     }>,
     *     contracts: list<array{project: array{id: int, name: string, domain: string|null}, price: string, received_to_date: string, balance: string}>,
     *     totals: array{expected: string, received: string, costs: string, profit: string, outstanding: string},
     * }
     *
     * `$detailed` false is the view for someone who may not see projects (the Accountant): `hours`
     * is null and `basis` never names the project's status, its start date or its hours — only
     * the money figures.
     */
    public static function forMonth(Carbon $month, bool $detailed = true): array
    {
        $start = $month->copy()->startOfMonth()->startOfDay();
        $from = $start->toDateString();
        $to = $start->copy()->endOfMonth()->toDateString();

        // 1. Income per project: this month, and ever (for the one-time contracts).
        $income = DB::table('income')
            ->whereNotNull('project_id')
            ->groupBy('project_id')
            ->selectRaw(
                'project_id, SUM(CASE WHEN date BETWEEN ? AND ? THEN amount ELSE 0 END) as received, SUM(amount) as to_date',
                [$from, $to],
            )
            ->get()
            ->keyBy(fn (object $row): int => (int) $row->project_id);

        // 2. Expenses per project this month, and the billable part.
        $expenses = DB::table('expenses')
            ->whereNotNull('project_id')
            ->whereBetween('date', [$from, $to])
            ->groupBy('project_id')
            ->selectRaw('project_id, SUM(amount) as costs, SUM(CASE WHEN billable THEN amount ELSE 0 END) as billable_costs')
            ->get()
            ->keyBy(fn (object $row): int => (int) $row->project_id);

        // 3. Approved seconds per project this month.
        $seconds = TimeEntry::query()
            ->tracked()
            ->whereNotNull('time_entries.project_id')
            ->whereBetween('time_entries.work_date', [$from, $to])
            ->groupBy('time_entries.project_id')
            ->selectRaw('time_entries.project_id as project_id, SUM(time_entries.duration_seconds) as seconds')
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row): array => [(int) $row->project_id => (int) $row->seconds])
            ->all();

        $activeInMonth = [];

        foreach ($income as $id => $row) {
            if (self::cents((string) $row->received) !== 0) {
                $activeInMonth[] = $id;
            }
        }

        foreach ($expenses as $id => $row) {
            $activeInMonth[] = $id;
        }

        // 4. The projects: every one with a finance row, and every one with money this month.
        $projects = DB::table('projects as project')
            ->leftJoin('project_finance as finance', 'finance.project_id', '=', 'project.id')
            ->where(function ($query) use ($activeInMonth): void {
                $query->whereNotNull('finance.id')
                    ->orWhereIn('project.id', array_values(array_unique($activeInMonth)));
            })
            ->get([
                'project.id as id',
                'project.name as name',
                'project.domain as domain',
                'project.status as status',
                'project.start_date as start_date',
                'finance.id as finance_id',
                'finance.price as price',
                'finance.recurring_amount as recurring_amount',
                'finance.billing_frequency as billing_frequency',
                'finance.hourly_rate as hourly_rate',
                'finance.created_at as finance_created_at',
            ]);

        $rows = [];
        $contracts = [];
        $totals = ['expected' => 0, 'received' => 0, 'costs' => 0, 'profit' => 0, 'outstanding' => 0];

        foreach ($projects as $project) {
            $id = (int) $project->id;
            $payload = [
                'id' => $id,
                'name' => (string) $project->name,
                'domain' => $project->domain === null ? null : (string) $project->domain,
            ];

            $status = (string) $project->status;
            $frequency = $project->billing_frequency === null ? null : (string) $project->billing_frequency;
            $priceCents = self::cents($project->price);
            $recurringCents = self::cents($project->recurring_amount);
            $rateCents = self::cents($project->hourly_rate);

            $type = match (true) {
                $frequency === BillingFrequency::Hourly->value && $rateCents > 0 => 'hourly',
                $frequency !== null && isset(self::STEPS[$frequency]) && $recurringCents > 0 => 'recurring',
                $priceCents > 0 => 'one_time',
                default => 'none',
            };

            $closed = $status === ProjectStatus::Cancelled->value || $status === ProjectStatus::Archived->value;
            $statusLabel = strtolower(ProjectStatus::tryFrom($status)?->label() ?? $status);

            $expected = null;
            $hours = null;
            $rate = null;

            switch ($type) {
                case 'recurring':
                    $typeLabel = 'Recurring ('.(BillingFrequency::tryFrom((string) $frequency)?->label() ?? ucfirst((string) $frequency)).')';
                    $step = self::STEPS[$frequency];
                    $anchorDate = Carbon::parse($project->start_date ?? $project->finance_created_at)->startOfDay();
                    $anchor = $anchorDate->copy()->startOfMonth();
                    $every = $step === 1 ? 'every month' : "every {$step} months";
                    $plan = self::label($recurringCents).' '.$every.' from '.$anchorDate->format('j M Y');
                    $diff = ($start->year * 12 + $start->month) - ($anchor->year * 12 + $anchor->month);

                    $money = self::label($recurringCents).' '.$every;

                    if ($status !== ProjectStatus::Active->value) {
                        $basis = $detailed
                            ? "Project is {$statusLabel} — nothing expected ({$plan})"
                            : 'Project not active — nothing expected';
                    } elseif ($diff >= 0 && $diff % $step === 0) {
                        $expected = $recurringCents;
                        $basis = $detailed ? "{$plan} — due this month" : "Recurring, due this month — {$money}";
                    } else {
                        $next = $diff < 0
                            ? $anchor->copy()
                            : $anchor->copy()->addMonthsNoOverflow((intdiv($diff, $step) + 1) * $step);
                        $basis = $detailed
                            ? 'Not due this month (next: '.$next->format('M Y').") — {$plan}"
                            : "Recurring, not due this month — {$money}";
                    }
                    break;

                case 'hourly':
                    $typeLabel = 'Hourly';
                    $secs = $seconds[$id] ?? 0;
                    $hours = self::fromCents(intdiv($secs * 100 + 1800, 3600));
                    $rate = self::fromCents($rateCents);

                    if ($closed) {
                        $basis = $detailed
                            ? "Project is {$statusLabel} — nothing expected ({$hours} approved hours)"
                            : 'Project not active — nothing expected';
                    } else {
                        $expected = intdiv($secs * $rateCents + 1800, 3600);
                        $basis = $detailed
                            ? "{$hours} approved hours × ".self::label($rateCents).'/hr'
                            : 'Hourly: approved time × '.self::label($rateCents).'/hr';
                    }

                    if (! $detailed) {
                        $hours = null;
                    }
                    break;

                case 'one_time':
                    $typeLabel = 'One-time';
                    $basis = 'One-time price — see contracts';
                    $toDate = self::cents($income[$id]->to_date ?? null);
                    $contracts[] = [
                        'project' => $payload,
                        'price' => self::fromCents($priceCents),
                        'received_to_date' => self::fromCents($toDate),
                        'balance' => self::fromCents($priceCents - $toDate),
                    ];
                    break;

                default:
                    $typeLabel = 'Not set';
                    $basis = $project->finance_id === null ? 'No billing set for this project' : 'No price, rate or recurring amount set';
            }

            $received = self::cents($income[$id]->received ?? null);
            $costs = self::cents($expenses[$id]->costs ?? null);
            $billable = self::cents($expenses[$id]->billable_costs ?? null);
            $outstanding = $expected === null ? 0 : max($expected - $received, 0);

            $totals['expected'] += $expected ?? 0;
            $totals['received'] += $received;
            $totals['costs'] += $costs;
            $totals['profit'] += $received - $costs;
            $totals['outstanding'] += $outstanding;

            $rows[] = [
                'project' => $payload,
                'type' => $type,
                'type_label' => $typeLabel,
                'basis' => $basis,
                'expected' => $expected === null ? null : self::fromCents($expected),
                'hours' => $hours,
                'rate' => $rate,
                'received' => self::fromCents($received),
                'costs' => self::fromCents($costs),
                'billable_costs' => self::fromCents($billable),
                'profit' => self::fromCents($received - $costs),
                'outstanding' => self::fromCents($outstanding),
                '_sort' => $expected,
            ];
        }

        usort($rows, function (array $a, array $b): int {
            if ($a['_sort'] === null || $b['_sort'] === null) {
                $nulls = ($a['_sort'] === null ? 1 : 0) <=> ($b['_sort'] === null ? 1 : 0);

                if ($nulls !== 0) {
                    return $nulls;
                }
            } elseif ($a['_sort'] !== $b['_sort']) {
                return $b['_sort'] <=> $a['_sort'];
            }

            return strcasecmp($a['project']['name'], $b['project']['name']);
        });

        usort($contracts, fn (array $a, array $b): int => strcasecmp($a['project']['name'], $b['project']['name']));

        return [
            'month' => $start->format('Y-m'),
            'rows' => array_map(function (array $row): array {
                unset($row['_sort']);

                return $row;
            }, $rows),
            'contracts' => $contracts,
            'totals' => array_map(fn (int $cents): string => self::fromCents($cents), $totals),
        ];
    }

    /**
     * An exact decimal string (as PostgreSQL sends `numeric`) to integer cents, without ever
     * going through a float. A third decimal, if one ever arrives, rounds half away from zero.
     */
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

    /** Integer cents to the exact two-decimal string every amount travels as. */
    private static function fromCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $abs = abs($cents);

        return $sign.intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Cents as a short money label for the `basis` sentence, e.g. "$1,250.00" — or "BDT 1,250.00"
     * when `settings.currency` is not USD, so the sentence never names the wrong currency.
     */
    private static function label(int $cents): string
    {
        static $symbol = null;
        $symbol ??= (static function (): string {
            $currency = strtoupper((string) app(SettingsService::class)->get('currency'));

            return $currency === '' || $currency === 'USD' ? '$' : $currency.' ';
        })();

        $sign = $cents < 0 ? '-' : '';
        $abs = abs($cents);

        return $sign.$symbol.number_format(intdiv($abs, 100)).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }
}
