<?php

namespace App\Support;

use App\Models\TimeEntry;
use App\Services\ExchangeRateService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What each project SHOULD have earned in a month, what it DID earn, and what is still owed
 * (client request: "how much income comes from each project every month, and how").
 *
 * **Reported in BDT (D7).** Every project is computed in its own pricing currency (the currency
 * of its `project_finance` row) and every row also carries the BDT figures (`*_bdt`), converted
 * at the stored USD→BDT rate (`ExchangeRateService`). The original figures stay beside them so
 * a screen can show "$350.00" under the taka. Totals are BDT only.
 *
 * Computed, never stored, like every other money figure here. Three grouped queries whatever the
 * number of projects: income by project and currency, approved hours by project, and the
 * projects themselves with their `project_finance` row. Every amount is handled in **integer
 * cents** parsed from the exact decimal strings PostgreSQL sends — never a float — and goes out
 * as an exact two-decimal string.
 *
 * ## Which projects appear
 *
 * Every project with a `project_finance` row, plus every project with income linked to it and
 * dated in the month. A project with no finance row appears only through that income: expected
 * null. Cancelled and Archived projects never get an *expected* figure, but still show what
 * they received.
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
 * `received` is income in the project's own currency linked to it and dated in the month;
 * `received_bdt` is ALL income linked to it in the month (any currency) converted to BDT.
 * `outstanding` is max(expected − received, 0), and '0.00' when nothing is expected; the BDT
 * one is the same sum on the BDT figures.
 *
 * Rows are sorted by expected BDT, largest first (nothing expected last), then by name.
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
     *     currency: string,
     *     rows: list<array{
     *         project: array{id: int, name: string, domain: string|null},
     *         currency: string,
     *         type: string,
     *         type_label: string,
     *         basis: string,
     *         expected: string|null,
     *         expected_bdt: string|null,
     *         hours: string|null,
     *         rate: string|null,
     *         received: string,
     *         received_bdt: string,
     *         outstanding: string,
     *         outstanding_bdt: string,
     *     }>,
     *     contracts: list<array{project: array{id: int, name: string, domain: string|null}, currency: string, price: string, price_bdt: string, received_to_date: string, received_to_date_bdt: string, balance: string, balance_bdt: string}>,
     *     totals: array{expected: string, received: string, outstanding: string},
     * }
     *
     * `$detailed` false is the view for someone who may not see projects (the Accountant): `hours`
     * is null and `basis` never names the project's status, its start date or its hours — only
     * the money figures. `$currency` is accepted for backwards compatibility and ignored.
     */
    public static function forMonth(Carbon $month, bool $detailed = true, ?string $currency = null): array
    {
        $rates = app(ExchangeRateService::class);

        $start = $month->copy()->startOfMonth()->startOfDay();
        $from = $start->toDateString();
        $to = $start->copy()->endOfMonth()->toDateString();

        // 1. Income per project and currency: this month, and ever (for the one-time contracts).
        $incomeRows = DB::table('income')
            ->whereNotNull('project_id')
            ->groupBy('project_id', 'currency')
            ->selectRaw(
                'project_id, currency, SUM(CASE WHEN date BETWEEN ? AND ? THEN amount ELSE 0 END) as received, SUM(amount) as to_date',
                [$from, $to],
            )
            ->get();

        /** @var array<int, array<string, array{received: int, to_date: int}>> $income */
        $income = [];
        $activeInMonth = [];

        foreach ($incomeRows as $row) {
            $id = (int) $row->project_id;
            $income[$id][(string) $row->currency] = [
                'received' => self::cents((string) $row->received),
                'to_date' => self::cents((string) $row->to_date),
            ];

            if (self::cents((string) $row->received) !== 0) {
                $activeInMonth[] = $id;
            }
        }

        // 2. Approved seconds per project this month.
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

        // 3. The projects: every one with pricing, and every one with income this month.
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
                'finance.currency as finance_currency',
                'finance.price as price',
                'finance.recurring_amount as recurring_amount',
                'finance.billing_frequency as billing_frequency',
                'finance.hourly_rate as hourly_rate',
                'finance.created_at as finance_created_at',
            ]);

        // Sums one project's income buckets in BDT cents.
        $inBdt = function (array $buckets, string $field) use ($rates): int {
            $total = 0;

            foreach ($buckets as $code => $bucket) {
                $total += $rates->toBdtCents(self::fromCents($bucket[$field]), (string) $code);
            }

            return $total;
        };

        $rows = [];
        $contracts = [];
        $totals = ['expected' => 0, 'received' => 0, 'outstanding' => 0];

        foreach ($projects as $project) {
            $id = (int) $project->id;
            $payload = [
                'id' => $id,
                'name' => (string) $project->name,
                'domain' => $project->domain === null ? null : (string) $project->domain,
            ];

            $code = $project->finance_id === null ? 'BDT' : strtoupper((string) $project->finance_currency);
            $buckets = $income[$id] ?? [];

            $status = (string) $project->status;
            $frequency = $project->billing_frequency === null ? null : (string) $project->billing_frequency;
            $priceCents = self::cents($project->price);
            $recurringCents = self::cents($project->recurring_amount);
            $rateCents = self::cents($project->hourly_rate);

            $priced = $project->finance_id !== null;

            $type = match (true) {
                ! $priced => 'none',
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
                    $plan = self::label($recurringCents, $code).' '.$every.' from '.$anchorDate->format('j M Y');
                    $diff = ($start->year * 12 + $start->month) - ($anchor->year * 12 + $anchor->month);

                    $money = self::label($recurringCents, $code).' '.$every;

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
                            ? "{$hours} approved hours × ".self::label($rateCents, $code).'/hr'
                            : 'Hourly: approved time × '.self::label($rateCents, $code).'/hr';
                    }

                    if (! $detailed) {
                        $hours = null;
                    }
                    break;

                case 'one_time':
                    $typeLabel = 'One-time';
                    $basis = 'One-time price — see contracts';
                    $toDate = $buckets[$code]['to_date'] ?? 0;
                    $toDateBdt = $inBdt($buckets, 'to_date');
                    $priceBdt = $rates->toBdtCents(self::fromCents($priceCents), $code);
                    $contracts[] = [
                        'project' => $payload,
                        'currency' => $code,
                        'price' => self::fromCents($priceCents),
                        'price_bdt' => self::fromCents($priceBdt),
                        'received_to_date' => self::fromCents($toDate),
                        'received_to_date_bdt' => self::fromCents($toDateBdt),
                        'balance' => self::fromCents($priceCents - $toDate),
                        'balance_bdt' => self::fromCents($priceBdt - $toDateBdt),
                    ];
                    break;

                default:
                    $typeLabel = 'Not set';
                    $basis = $project->finance_id === null ? 'No billing set for this project' : 'No price, rate or recurring amount set';
            }

            $received = $buckets[$code]['received'] ?? 0;
            $receivedBdt = $inBdt($buckets, 'received');
            $outstanding = $expected === null ? 0 : max($expected - $received, 0);
            $expectedBdt = $expected === null ? null : $rates->toBdtCents(self::fromCents($expected), $code);
            $outstandingBdt = $expectedBdt === null ? 0 : max($expectedBdt - $receivedBdt, 0);

            $totals['expected'] += $expectedBdt ?? 0;
            $totals['received'] += $receivedBdt;
            $totals['outstanding'] += $outstandingBdt;

            $rows[] = [
                'project' => $payload,
                'currency' => $code,
                'type' => $type,
                'type_label' => $typeLabel,
                'basis' => $basis,
                'expected' => $expected === null ? null : self::fromCents($expected),
                'expected_bdt' => $expectedBdt === null ? null : self::fromCents($expectedBdt),
                'hours' => $hours,
                'rate' => $rate,
                'received' => self::fromCents($received),
                'received_bdt' => self::fromCents($receivedBdt),
                'outstanding' => self::fromCents($outstanding),
                'outstanding_bdt' => self::fromCents($outstandingBdt),
                '_sort' => $expectedBdt,
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
            'currency' => 'BDT',
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
     * Cents as a short money label for the `basis` sentence in the report's currency, e.g.
     * "$1,250.00" for USD or "BDT 1,250.00" for BDT, so the sentence never names the wrong currency.
     */
    private static function label(int $cents, string $currency): string
    {
        $symbol = $currency === 'USD' ? '$' : $currency.' ';

        $sign = $cents < 0 ? '-' : '';
        $abs = abs($cents);

        return $sign.$symbol.number_format(intdiv($abs, 100)).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }
}
