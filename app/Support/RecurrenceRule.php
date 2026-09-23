<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * One recurrence rule, and every date question the engine can ask of it.
 *
 * A template stores this as `recurring_tasks.recurrence_rule` (its parameters) plus
 * `recurring_tasks.frequency` (the case, kept in a column of its own so a list can filter and
 * a Form Request can validate against it without opening the JSON).
 *
 * ## Four questions, four methods, one answer each
 *
 * Every date the engine needs comes from the period the run date falls in:
 *
 *   - `periodStart()` — when this period began. **Everything else is derived from it**, which is
 *     what makes the three frequencies one scheme rather than three.
 *   - `periodKey()` — the string that identifies the period. It becomes `tasks.recurring_period`
 *     and half of the unique index, so it has to be stable, short and readable by a person.
 *   - `generationDate()` — the day inside the period on which the instance is created. The 1st
 *     of the month by default; a template that wants the 15th says so.
 *   - `dueDate()` — when the generated task is due. The end of the period unless the rule names
 *     an offset from its start.
 *
 * ## The period key scheme
 *
 * The master prompt's own examples are `2026-10` and `2026-W41`, so:
 *
 *   | Frequency | Period          | Key           | Label            |
 *   | --------- | --------------- | ------------- | ---------------- |
 *   | Monthly   | a calendar month| `2026-10`     | October 2026     |
 *   | Weekly    | an ISO week     | `2026-W41`    | Week 41, 2026    |
 *   | Custom    | N days from an  | `2026-10-07`  | 7 Oct–20 Oct 2026|
 *   |           | anchor date     |               |                  |
 *
 * Three shapes, ONE function: `periodKey()` formats whatever `periodStart()` returned. Adding a
 * fourth frequency is a case in two `match`es, not a new key scheme to keep in step with the old
 * ones.
 *
 * Two properties of the scheme are load-bearing and easy to lose:
 *
 *   1. **A key is unique within one template**, which is all the unique index needs — a template
 *      has exactly one frequency, so two of its keys are always the same shape.
 *   2. **Keys of one shape sort lexicographically into chronological order.** `2026-09` <
 *      `2026-10`, `2026-W09` < `2026-W41`, `2026-10-07` < `2026-10-21`. That is why the
 *      previous-open-instance lookup can be a plain `where recurring_period < ?` instead of a
 *      second date column, and why the weekly key uses the ISO YEAR (`o`) rather than the
 *      calendar year: 29 December 2025 is in ISO week 1 of 2026, and `2025-W01` would sort a
 *      year out of place.
 *
 * ## Custom
 *
 * Custom is "every N days from an anchor date". It is the frequency for a cycle that does not
 * line up with a month or a week — a fortnightly report, a ten-day check — and its period key is
 * the date the period started, which is the only reading of "which cycle is this" that stays
 * true when the anchor moves.
 */
final class RecurrenceRule
{
    /** Monthly rules are clamped to this day so that a rule never skips February. */
    public const MAX_DAY_OF_MONTH = 28;

    /** A custom cycle shorter than this is not a cycle, and longer than this is not a retainer. */
    public const MIN_INTERVAL_DAYS = 1;

    public const MAX_INTERVAL_DAYS = 366;

    private function __construct(
        public readonly RecurrenceFrequency $frequency,
        /** Monthly: the day of the month the instance is generated on (1–28). */
        public readonly int $dayOfMonth,
        /** Weekly: the ISO weekday the instance is generated on (1 = Monday … 7 = Sunday). */
        public readonly int $weekday,
        /** Custom: how many days one period lasts. */
        public readonly int $intervalDays,
        /** Custom: the day the very first period began. Every later period is counted from it. */
        public readonly ?Carbon $anchor,
        /** Due date as a number of days after the period START; null means the period's last day. */
        public readonly ?int $dueOffsetDays,
    ) {}

    public static function monthly(int $dayOfMonth = 1, ?int $dueOffsetDays = null): self
    {
        return new self(
            RecurrenceFrequency::Monthly,
            self::clampDayOfMonth($dayOfMonth),
            1,
            1,
            null,
            self::clampOffset($dueOffsetDays),
        );
    }

    public static function weekly(int $weekday = Carbon::MONDAY, ?int $dueOffsetDays = null): self
    {
        return new self(
            RecurrenceFrequency::Weekly,
            1,
            self::clampWeekday($weekday),
            7,
            null,
            self::clampOffset($dueOffsetDays),
        );
    }

    public static function custom(int $intervalDays, Carbon|string $anchor, ?int $dueOffsetDays = null): self
    {
        return new self(
            RecurrenceFrequency::Custom,
            1,
            1,
            self::clampInterval($intervalDays),
            ($anchor instanceof Carbon ? $anchor->copy() : Carbon::parse($anchor))->startOfDay(),
            self::clampOffset($dueOffsetDays),
        );
    }

    /**
     * Rebuild a rule from the JSON a template stores.
     *
     * Unreadable input falls back to "monthly on the 1st" rather than throwing. A console
     * command running at five past midnight against a row somebody hand-edited should generate
     * the obvious thing and say so in the log; the Form Request that will own this input on the
     * templates screen is where a bad rule becomes an error a person can see.
     *
     * @param  array<string, mixed>  $rule
     */
    public static function fromArray(array $rule): self
    {
        $frequency = RecurrenceFrequency::tryFrom((string) ($rule['frequency'] ?? ''))
            ?? RecurrenceFrequency::Monthly;

        $dueOffset = isset($rule['due_offset_days']) && is_numeric($rule['due_offset_days'])
            ? (int) $rule['due_offset_days']
            : null;

        return match ($frequency) {
            RecurrenceFrequency::Monthly => self::monthly(
                (int) ($rule['day_of_month'] ?? 1),
                $dueOffset,
            ),
            RecurrenceFrequency::Weekly => self::weekly(
                (int) ($rule['weekday'] ?? Carbon::MONDAY),
                $dueOffset,
            ),
            RecurrenceFrequency::Custom => self::custom(
                (int) ($rule['interval_days'] ?? 14),
                self::readAnchor($rule['anchor'] ?? null),
                $dueOffset,
            ),
        };
    }

    /**
     * The parameters, as they are stored. The frequency rides along inside the JSON as well as in
     * its own column so that a rule read out of the database is self-describing.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $rule = ['frequency' => $this->frequency->value];

        $rule += match ($this->frequency) {
            RecurrenceFrequency::Monthly => ['day_of_month' => $this->dayOfMonth],
            RecurrenceFrequency::Weekly => ['weekday' => $this->weekday],
            RecurrenceFrequency::Custom => [
                'interval_days' => $this->intervalDays,
                'anchor' => $this->anchor?->toDateString(),
            ],
        };

        if ($this->dueOffsetDays !== null) {
            $rule['due_offset_days'] = $this->dueOffsetDays;
        }

        return $rule;
    }

    /*
    |--------------------------------------------------------------------------
    | The period
    |--------------------------------------------------------------------------
    */

    /**
     * The first day of the period the given date falls in. Everything else derives from this.
     */
    public function periodStart(Carbon $at): Carbon
    {
        $at = $at->copy()->startOfDay();

        return match ($this->frequency) {
            RecurrenceFrequency::Monthly => $at->startOfMonth(),
            // ISO weeks, so the key's `o-\WW` format and this agree about which Monday starts
            // the week that 29 December belongs to.
            RecurrenceFrequency::Weekly => $at->startOfWeek(Carbon::MONDAY),
            RecurrenceFrequency::Custom => $this->customPeriodStart($at),
        };
    }

    /**
     * The last day of the period, inclusive.
     */
    public function periodEnd(Carbon $periodStart): Carbon
    {
        $start = $periodStart->copy()->startOfDay();

        return match ($this->frequency) {
            RecurrenceFrequency::Monthly => $start->endOfMonth()->startOfDay(),
            RecurrenceFrequency::Weekly => $start->addDays(6),
            RecurrenceFrequency::Custom => $start->addDays($this->intervalDays - 1),
        };
    }

    /**
     * The period after this one.
     */
    public function nextPeriodStart(Carbon $periodStart): Carbon
    {
        $start = $periodStart->copy()->startOfDay();

        return match ($this->frequency) {
            RecurrenceFrequency::Monthly => $start->addMonthNoOverflow(),
            RecurrenceFrequency::Weekly => $start->addWeek(),
            RecurrenceFrequency::Custom => $start->addDays($this->intervalDays),
        };
    }

    /**
     * The key that identifies the period containing this date — `tasks.recurring_period`, and
     * half of the unique index that makes a duplicate impossible.
     */
    public function periodKey(Carbon $at): string
    {
        $start = $this->periodStart($at);

        return match ($this->frequency) {
            RecurrenceFrequency::Monthly => $start->format('Y-m'),
            // `o` is the ISO year, not the calendar year — see the class docblock.
            RecurrenceFrequency::Weekly => $start->format('o-\WW'),
            RecurrenceFrequency::Custom => $start->toDateString(),
        };
    }

    /**
     * The period key as a person reads it, derived from the KEY alone.
     *
     * Deliberately not a method on the rule instance: task detail shows "Generated from:
     * <template> · period <Month YYYY>" and has nothing but the string stored on the task. The
     * shape of the key is enough to tell the three schemes apart, so one function answers all
     * three and a task keeps its label even if its template is later deleted.
     */
    public static function labelForPeriod(?string $period): ?string
    {
        $period = trim((string) $period);

        if ($period === '') {
            return null;
        }

        if (preg_match('/^(\d{4})-W(\d{2})$/', $period, $week) === 1) {
            return sprintf('Week %d, %s', (int) $week[2], $week[1]);
        }

        if (preg_match('/^(\d{4})-(\d{2})$/', $period, $month) === 1) {
            return Carbon::createFromDate((int) $month[1], (int) $month[2], 1)->format('F Y');
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $period) === 1) {
            return 'From '.Carbon::parse($period)->format('j M Y');
        }

        // An unrecognised shape is shown as it was stored rather than swallowed: a period nobody
        // can read is still better than a task that claims to belong to no period at all.
        return $period;
    }

    /*
    |--------------------------------------------------------------------------
    | The two dates the engine writes
    |--------------------------------------------------------------------------
    */

    /**
     * The day inside the period on which the instance is created.
     *
     * The scheduler runs every day; this is what makes "monthly on the 1st" different from
     * "monthly on the 15th" without either of them changing which period is being generated.
     */
    public function generationDate(Carbon $periodStart): Carbon
    {
        $start = $periodStart->copy()->startOfDay();

        return match ($this->frequency) {
            // Clamped at construction to the 28th, so February never silently skips a month.
            RecurrenceFrequency::Monthly => $start->day(min($this->dayOfMonth, $start->daysInMonth)),
            // periodStart is the Monday, so weekday 1 is the Monday itself.
            RecurrenceFrequency::Weekly => $start->addDays($this->weekday - 1),
            RecurrenceFrequency::Custom => $start,
        };
    }

    /**
     * When the generated task is due: the end of the period, or a fixed number of days after it
     * started when the rule names one.
     *
     * One knob rather than three. "Monthly, generated on the 1st, due on the 10th" is
     * `due_offset_days = 9`, and the same nine days mean the same thing on a weekly or a custom
     * rule — which is what stops a due date meaning "day of the month" on one rule type and
     * "days from the start" on another.
     */
    public function dueDate(Carbon $periodStart): Carbon
    {
        if ($this->dueOffsetDays === null) {
            return $this->periodEnd($periodStart);
        }

        return $periodStart->copy()->startOfDay()->addDays($this->dueOffsetDays);
    }

    /**
     * The next moment this rule will produce something, strictly after the given date.
     *
     * This is the templates screen's "next run preview" and the value the engine writes back to
     * `recurring_tasks.next_run_at`. It is DERIVED, never the source of truth: the engine decides
     * what to generate from the period the run date falls in, so a stale `next_run_at` costs a
     * preview and never a missed month.
     */
    public function nextRunAt(Carbon $after): Carbon
    {
        $after = $after->copy()->startOfDay();
        $thisPeriod = $this->generationDate($this->periodStart($after));

        return $thisPeriod->gt($after)
            ? $thisPeriod
            : $this->generationDate($this->nextPeriodStart($this->periodStart($after)));
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Which custom cycle this date falls in: the anchor plus a whole number of intervals.
     *
     * Floor division, so a date BEFORE the anchor lands in a period before it rather than in the
     * anchor's own — a template backdated after the fact then still counts its cycles from the
     * same grid.
     */
    private function customPeriodStart(Carbon $at): Carbon
    {
        $anchor = ($this->anchor ?? $at)->copy()->startOfDay();
        $days = (int) $anchor->diffInDays($at, absolute: false);

        return $anchor->addDays((int) floor($days / $this->intervalDays) * $this->intervalDays);
    }

    private static function readAnchor(mixed $value): Carbon
    {
        if ($value instanceof Carbon) {
            return $value->copy()->startOfDay();
        }

        if (is_string($value) && trim($value) !== '') {
            try {
                return Carbon::parse(trim($value))->startOfDay();
            } catch (\Throwable) {
                // Falls through to today, below.
            }
        }

        return Carbon::today();
    }

    private static function clampDayOfMonth(int $day): int
    {
        return max(1, min($day, self::MAX_DAY_OF_MONTH));
    }

    private static function clampWeekday(int $weekday): int
    {
        return max(1, min($weekday, 7));
    }

    private static function clampInterval(int $days): int
    {
        return max(self::MIN_INTERVAL_DAYS, min($days, self::MAX_INTERVAL_DAYS));
    }

    private static function clampOffset(?int $days): ?int
    {
        return $days === null ? null : max(0, min($days, self::MAX_INTERVAL_DAYS));
    }
}
