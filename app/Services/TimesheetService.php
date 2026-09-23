<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Schedule;
use App\Models\TimeEntry;
use App\Support\Weekday;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The weekly timesheet (master prompt Part D §7, Phase 4).
 *
 * A grid: rows are the tasks worked on, columns are the seven days of the employee's week,
 * cells are tracked hours. It is **a view over `time_entries` and nothing else** — no table, no
 * cache, no stored week total. Every number here is summed from the rows the week actually
 * holds, which is why the grid and the Time page cannot disagree about an afternoon.
 *
 * ## Which hours count is not decided here
 *
 * Decision 4-7 settles it once: a total asks `approved_at is not null` and no other question.
 * This service never restates that predicate — it asks the MODEL, through `approvalKey()`, and
 * files each entry's seconds into the bucket that word names. So the four buckets are the
 * model's four words, and an entry can be in exactly one:
 *
 *   - `counted`  — approved, and the only bucket that reaches a total;
 *   - `pending`  — finished, nobody has ruled on it (`awaitsApproval()`);
 *   - `rejected` — finished, somebody refused it;
 *   - `open`     — still going, so it has no agreed length yet.
 *
 * Pending and rejected hours are reported **separately and in words**, beside the total and
 * never folded into it, exactly as the Time page and `BuildsTimerState` report them. Hiding
 * them is how an employee concludes the system lost their afternoon.
 *
 * ## Where the week starts
 *
 * **Never Monday by default, and never a constant.** `schedules.working_days` is per employee;
 * the seeded week is Sun–Thu and an edited one may be anything. The first column is the
 * employee's **first working day**, read in `Weekday`'s own order — which is Carbon's
 * `dayOfWeek`, 0 = Sunday, the one ordering that cannot be configured out from under this
 * (see `Weekday::of()`). So a Sun–Thu schedule starts on Sunday, a Mon–Fri one on Monday, and
 * a Tue–Thu one on Tuesday.
 *
 * An employee with no schedule, or one with no working days ticked, has no week for this to
 * read — the fallback is the calendar week's own first day, Sunday, and the payload says so in
 * words on the screen rather than letting the reader guess. Part D §7's "Mon…Sun" describes a
 * grid of seven days; it is not the order to paste in.
 *
 * All seven days are columns, working or not: work does get tracked on an off day, and a column
 * that silently vanished would take its hours with it. A non-working day is marked as one, in
 * words.
 */
class TimesheetService
{
    /** The columns of any week. Seven, always — the question is only which day is first. */
    public const DAYS_IN_WEEK = 7;

    public function __construct(private readonly TimerService $timer) {}

    /**
     * The weekday this employee's week begins on: their first working day, in calendar order.
     *
     * Falls back to Sunday when there is nothing to read. That is the calendar week's own first
     * day in `Weekday`'s ordering rather than a working-week convention — the honest answer to
     * "this employee has no schedule" is the plain calendar, and the screen prints which it
     * used.
     */
    public function weekStartsOn(?Schedule $schedule): Weekday
    {
        $working = is_array($schedule?->working_days) ? $schedule->working_days : [];

        foreach (Weekday::cases() as $day) {
            if (in_array($day->value, $working, true)) {
                return $day;
            }
        }

        return Weekday::Sunday;
    }

    /**
     * The Monday — or Sunday, or Tuesday — of the week `$anyDay` falls in, for this employee.
     *
     * `Weekday::cases()` is indexed by `dayOfWeek`, so the distance back to the start day is
     * plain modular arithmetic and no weekday name is ever compared as a string.
     */
    public function weekStart(Employee $employee, Carbon $anyDay): Carbon
    {
        $startIndex = (int) array_search($this->weekStartsOn($employee->schedule), Weekday::cases(), true);
        $delta = ($anyDay->dayOfWeek - $startIndex + self::DAYS_IN_WEEK) % self::DAYS_IN_WEEK;

        return $anyDay->copy()->startOfDay()->subDays($delta);
    }

    /**
     * One employee's week, whole: the seven columns, the rows, and every total.
     *
     * One query. A week of one person's entries is tens of rows, so the grouping is done in PHP
     * against the model's own predicates rather than as four SQL aggregates that would each
     * have to restate them.
     *
     * @return array<string, mixed>
     */
    public function week(Employee $employee, Carbon $anyDayInWeek, ?Carbon $today = null): array
    {
        $today ??= Carbon::today();
        $start = $this->weekStart($employee, $anyDayInWeek);
        $end = $start->copy()->addDays(self::DAYS_IN_WEEK - 1);

        $entries = $this->entries($employee, $start, $end);
        $dates = $this->dates($start);

        $rows = $this->rows($entries, $dates);
        $days = $this->days($employee, $dates, $entries, $today);

        return [
            'week' => $this->weekPayload($employee, $start, $end, $today),
            'days' => $days,
            'rows' => $rows,
            'totals' => $this->totals($days, $rows, $employee),
        ];
    }

    /**
     * Every entry of this employee's that lands in the window.
     *
     * By `work_date`, which is the day the entry BELONGS to — a session started at 23:40 is
     * that day's work however far past midnight it ran, and grouping by `started_at` would
     * silently move it into the next column.
     *
     * @return Collection<int, TimeEntry>
     */
    private function entries(Employee $employee, Carbon $start, Carbon $end): Collection
    {
        return TimeEntry::query()
            ->where('employee_id', $employee->getKey())
            ->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])
            ->with(['task:id,title,project_id', 'project:id,name'])
            ->orderBy('started_at')
            ->get();
    }

    /**
     * The seven dates of the week, in column order.
     *
     * @return list<Carbon>
     */
    private function dates(Carbon $start): array
    {
        return array_map(
            fn (int $offset): Carbon => $start->copy()->addDays($offset),
            range(0, self::DAYS_IN_WEEK - 1),
        );
    }

    /**
     * The rows: one per task worked on, each with seven cells and its own totals.
     *
     * Ordered by project then task, alphabetically — **not** by hours. A grid sorted by who or
     * what accumulated the most is a ranking wearing a table's clothes, and Part H forbids it.
     * Alphabetical also means the rows do not jump about between two visits to the same week.
     *
     * @param  Collection<int, TimeEntry>  $entries
     * @param  list<Carbon>  $dates
     * @return list<array<string, mixed>>
     */
    private function rows(Collection $entries, array $dates): array
    {
        $rows = $entries
            ->groupBy(fn (TimeEntry $entry): string => 'task-'.($entry->task_id ?? 0))
            ->map(function (Collection $ofTask, string $key) use ($dates): array {
                /** @var TimeEntry $first */
                $first = $ofTask->first();

                $cells = array_map(function (Carbon $date) use ($ofTask): array {
                    $onDay = $ofTask->filter(
                        fn (TimeEntry $entry): bool => $entry->work_date->isSameDay($date),
                    );

                    return $this->cell($date, $onDay);
                }, $dates);

                return [
                    'key' => $key,
                    'task' => $first->task === null ? null : [
                        'id' => (int) $first->task->id,
                        'title' => (string) $first->task->title,
                    ],
                    'project' => $first->project === null ? null : [
                        'id' => (int) $first->project->id,
                        'name' => (string) $first->project->name,
                    ],
                    'cells' => $cells,
                    ...$this->sumOf($ofTask),
                ];
            })
            ->values()
            ->all();

        usort($rows, function (array $a, array $b): int {
            $byProject = strcasecmp(
                (string) ($a['project']['name'] ?? ''),
                (string) ($b['project']['name'] ?? ''),
            );

            return $byProject !== 0
                ? $byProject
                : strcasecmp((string) ($a['task']['title'] ?? ''), (string) ($b['task']['title'] ?? ''));
        });

        return $rows;
    }

    /**
     * One cell: the day, its four buckets of seconds, and whether a timer is going in it.
     *
     * `is_running` exists so that a cell an employee is tracking into right now does not read
     * as a flat zero. The seconds of an open entry are deliberately NOT in the total: its
     * length is a question about `now()`, and a grid that answered it would print a different
     * number every second (see `TimeEntry::elapsedSeconds()`).
     *
     * @param  Collection<int, TimeEntry>  $onDay
     * @return array<string, mixed>
     */
    private function cell(Carbon $date, Collection $onDay): array
    {
        return [
            'date' => $date->toDateString(),
            'entry_count' => $onDay->count(),
            'is_running' => $onDay->contains(fn (TimeEntry $entry): bool => $entry->isOpen()),
            ...$this->sumOf($onDay),
        ];
    }

    /**
     * Seconds per bucket over a set of entries, filed by the model's own word for each one.
     *
     * This is the single place this service adds any duration up, so the cell total, the row
     * total, the day total and the week total are four callers of one sum and cannot drift.
     *
     * @param  Collection<int, TimeEntry>  $entries
     * @return array{counted_seconds: int, pending_seconds: int, rejected_seconds: int}
     */
    private function sumOf(Collection $entries): array
    {
        $buckets = ['counted' => 0, 'pending' => 0, 'rejected' => 0, 'open' => 0];

        foreach ($entries as $entry) {
            $buckets[$entry->approvalKey()] += (int) $entry->duration_seconds;
        }

        return [
            'counted_seconds' => $buckets['counted'],
            'pending_seconds' => $buckets['pending'],
            'rejected_seconds' => $buckets['rejected'],
        ];
    }

    /**
     * The seven column headings, each with its own totals and its own target.
     *
     * A day's target is the schedule's daily hours on a working day and null on an off day —
     * null rather than zero, because "nothing is expected of you today" and "you were expected
     * to do nothing" are different sentences and only the first is true.
     *
     * @param  list<Carbon>  $dates
     * @param  Collection<int, TimeEntry>  $entries
     * @return list<array<string, mixed>>
     */
    private function days(Employee $employee, array $dates, Collection $entries, Carbon $today): array
    {
        $schedule = $employee->schedule;
        $working = is_array($schedule?->working_days) ? $schedule->working_days : [];
        $target = $this->timer->targetSecondsFor($employee);

        return array_map(function (Carbon $date) use ($entries, $working, $target, $today): array {
            $weekday = Weekday::of($date);
            $isWorkingDay = in_array($weekday->value, $working, true);
            $onDay = $entries->filter(fn (TimeEntry $entry): bool => $entry->work_date->isSameDay($date));

            return [
                'date' => $date->toDateString(),
                'weekday' => $weekday->value,
                // Both spellings, because the grid head has ~48 px at a phone width and the
                // list below it has a whole row. Neither derives the other in Vue.
                'short_label' => $weekday->shortLabel(),
                'label' => $date->isoFormat('ddd D MMM'),
                'long_label' => $date->isoFormat('dddd D MMMM'),
                'is_working_day' => $isWorkingDay,
                'is_today' => $date->isSameDay($today),
                'is_future' => $date->greaterThan($today),
                'target_seconds' => $isWorkingDay ? $target : null,
                ...$this->sumOf($onDay),
            ];
        }, $dates);
    }

    /**
     * The week's footer: the sum of the day columns, and the week's target.
     *
     * The week target is the daily target times the number of WORKING days in these seven —
     * five days at 5 h is Tapu's 25 h — so a week containing a public holiday's off day does
     * not quietly raise the bar. Null when the employee has no daily target, because a total
     * measured against a number nobody set is not a measurement.
     *
     * @param  list<array<string, mixed>>  $days
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function totals(array $days, array $rows, Employee $employee): array
    {
        $target = $this->timer->targetSecondsFor($employee);
        $workingDays = count(array_filter($days, fn (array $day): bool => $day['is_working_day']));

        return [
            'counted_seconds' => (int) array_sum(array_column($days, 'counted_seconds')),
            'pending_seconds' => (int) array_sum(array_column($days, 'pending_seconds')),
            'rejected_seconds' => (int) array_sum(array_column($days, 'rejected_seconds')),
            'target_seconds' => $target === null ? null : $target * $workingDays,
            'working_days' => $workingDays,
            'row_count' => count($rows),
        ];
    }

    /**
     * Which week this is, where to go next, and — said out loud — why it starts where it does.
     *
     * The sentence is on the payload rather than in the template because it is the ANSWER to
     * the rule: a reader who expected Monday can read back why they got Sunday instead of
     * filing a bug.
     *
     * @return array<string, mixed>
     */
    private function weekPayload(Employee $employee, Carbon $start, Carbon $end, Carbon $today): array
    {
        $schedule = $employee->schedule;
        $startsOn = $this->weekStartsOn($schedule);
        $hasWorkingDays = is_array($schedule?->working_days) && $schedule->working_days !== [];

        return [
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'label' => $start->isoFormat('D MMM').' – '.$end->isoFormat('D MMM YYYY'),
            'previous' => $start->copy()->subDays(self::DAYS_IN_WEEK)->toDateString(),
            'next' => $start->copy()->addDays(self::DAYS_IN_WEEK)->toDateString(),
            'current' => $this->weekStart($employee, $today)->toDateString(),
            'is_current' => $start->isSameDay($this->weekStart($employee, $today)),
            'starts_on' => $startsOn->value,
            'starts_on_label' => $startsOn->label(),
            'starts_on_reason' => $hasWorkingDays
                ? $startsOn->label().' is the first working day on this work schedule.'
                : 'There is no work schedule for this employee yet, so the week is shown from '
                    .$startsOn->label().'.',
        ];
    }
}
