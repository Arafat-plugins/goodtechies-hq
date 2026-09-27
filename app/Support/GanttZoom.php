<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * How coarsely the Gantt's timeline is ruled: one column per day, per week, or per month.
 *
 * Zoom is not a filter. It changes nothing about WHICH tasks are in the payload — that is
 * `TaskService::filters()`' business and it is shared with the List, the Board and the
 * Calendar — it changes only how wide a window the screen opens on by default, and how the
 * header above the bars is labelled. It lives in the URL because a Gantt at a particular zoom
 * over a particular quarter is a thing somebody bookmarks and sends to somebody else.
 *
 * Two numbers belong to each case and to nothing else:
 *
 *  - `defaultWindow()` — the window a request that named none gets. It always opens a little
 *    way BEFORE today, because a plan is read to see what is late as much as what is next, and
 *    a timeline whose first column is today hides every overrun.
 *  - `maxDays()` — the widest window this zoom will draw. A hand-edited `date_from=1970-01-01`
 *    must not turn into twenty thousand columns and a query over the whole table; it is
 *    clamped, and the payload reports the window it actually used so the clamp is visible
 *    rather than mysterious.
 *
 * The axis underneath is always DAYS. A unit here is a heading and a gridline, not a unit of
 * arithmetic — a bar's position is its day offset over the window's day count at every zoom,
 * so there is one geometry and not three.
 */
enum GanttZoom: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';

    /** The default when a request names no zoom, or names one that no longer exists. */
    public static function fallback(): self
    {
        return self::Week;
    }

    /**
     * @return list<self>
     */
    public static function all(): array
    {
        return self::cases();
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $zoom): string => $zoom->value, self::cases());
    }

    /** An unrecognised zoom is the default rather than an error — a stale link is not a 500. */
    public static function resolve(mixed $value): self
    {
        return self::tryFrom(is_string($value) ? $value : '') ?? self::fallback();
    }

    public function label(): string
    {
        return match ($this) {
            self::Day => 'Day',
            self::Week => 'Week',
            self::Month => 'Month',
        };
    }

    /** What the zoom control's option says to a screen reader, where "Day" alone is not a sentence. */
    public function description(): string
    {
        return match ($this) {
            self::Day => 'One column per day',
            self::Week => 'One column per week',
            self::Month => 'One column per month',
        };
    }

    /**
     * The window this zoom opens on when the request named none.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function defaultWindow(Carbon $asOf): array
    {
        $day = $asOf->copy()->startOfDay();

        return match ($this) {
            // Four weeks, starting the Monday of last week: enough to see what slipped and
            // what is next without scrolling.
            self::Day => [
                $day->copy()->startOfWeek()->subWeek(),
                $day->copy()->startOfWeek()->addWeeks(3)->endOfWeek()->startOfDay(),
            ],
            // A quarter, ruled in weeks.
            self::Week => [
                $day->copy()->startOfWeek()->subWeeks(2),
                $day->copy()->startOfWeek()->addWeeks(9)->endOfWeek()->startOfDay(),
            ],
            // Half a year, ruled in months.
            self::Month => [
                $day->copy()->startOfMonth()->subMonth(),
                $day->copy()->startOfMonth()->addMonths(4)->endOfMonth()->startOfDay(),
            ],
        };
    }

    /**
     * The widest window this zoom draws, in days, inclusive of both ends.
     *
     * The numbers are "about a quarter of days", "about a year of weeks", "about three years
     * of months" — each one roughly a hundred columns, which is as much as a timeline can be
     * scrolled through before it stops being readable.
     */
    public function maxDays(): int
    {
        return match ($this) {
            self::Day => 120,
            self::Week => 735,
            self::Month => 1_830,
        };
    }

    /**
     * The columns the header draws, covering the whole window.
     *
     * Generated on the server for the reason the Calendar's window is: a grid that infers which
     * dates it is drawing is a grid that will one day draw the wrong ones. The first and last
     * unit are clipped to the window, so a week-ruled window that starts on a Wednesday has a
     * five-day first column and says so in `days`.
     *
     * @return list<array{key: string, start: string, end: string, days: int, label: string, short_label: string, boundary: bool}>
     */
    public function units(Carbon $from, Carbon $to): array
    {
        $units = [];
        $cursor = $from->copy()->startOfDay();
        $last = $to->copy()->startOfDay();

        while ($cursor->lte($last)) {
            $end = match ($this) {
                self::Day => $cursor->copy(),
                self::Week => $cursor->copy()->endOfWeek()->startOfDay(),
                self::Month => $cursor->copy()->endOfMonth()->startOfDay(),
            };

            if ($end->gt($last)) {
                $end = $last->copy();
            }

            $units[] = [
                'key' => $cursor->toDateString(),
                'start' => $cursor->toDateString(),
                'end' => $end->toDateString(),
                'days' => (int) $cursor->diffInDays($end) + 1,
                'label' => match ($this) {
                    self::Day => $cursor->format('D j M'),
                    self::Week => 'Week of '.$cursor->format('j M Y'),
                    self::Month => $cursor->format('F Y'),
                },
                'short_label' => match ($this) {
                    self::Day => $cursor->format('j'),
                    self::Week => $cursor->format('j M'),
                    self::Month => $cursor->format('M'),
                },
                // Whether this column opens a bigger period — the 1st of a month at day zoom,
                // January at month zoom — so the header can rule it more strongly without the
                // screen doing date arithmetic to find out.
                'boundary' => match ($this) {
                    self::Day => $cursor->day === 1,
                    self::Week => $cursor->day <= 7,
                    self::Month => $cursor->month === 1,
                },
            ];

            $cursor = match ($this) {
                self::Day => $cursor->copy()->addDay(),
                self::Week => $cursor->copy()->endOfWeek()->startOfDay()->addDay(),
                self::Month => $cursor->copy()->endOfMonth()->startOfDay()->addDay(),
            };
        }

        return $units;
    }
}
