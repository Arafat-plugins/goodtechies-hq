<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * The seven keys `schedules.working_days` is written in.
 *
 * The column is a jsonb list of these strings and it is seeded `['sun','mon','tue','wed','thu']`
 * — the Bangladesh working week — but that is SEED DATA, not a constant. The schedule is per
 * employee and editable, so nothing anywhere may assume which days are working days; the only
 * thing fixed is the vocabulary those days are spelled in, which is this enum.
 *
 * It exists so that the three places that need the vocabulary — the working-day predicate in
 * `AttendanceService`, the validation rule in `UpdateScheduleRequest`, and the labels the
 * editor prints — read it from one list instead of three arrays that can fall out of order.
 * PHP's `date('D')` would have given the same seven strings, but not the same seven strings
 * every time: it is locale-independent today and a typo away from not being tomorrow.
 */
enum Weekday: string
{
    case Sunday = 'sun';
    case Monday = 'mon';
    case Tuesday = 'tue';
    case Wednesday = 'wed';
    case Thursday = 'thu';
    case Friday = 'fri';
    case Saturday = 'sat';

    /**
     * The weekday a date falls on.
     *
     * Indexed by `dayOfWeek`, which Carbon defines as 0 = Sunday through 6 = Saturday
     * regardless of any locale or `startOfWeek` setting — the one property of a Carbon date
     * that cannot be configured out from under this.
     */
    public static function of(CarbonInterface $date): self
    {
        return self::cases()[$date->dayOfWeek];
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $day): string => $day->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Sunday => 'Sunday',
            self::Monday => 'Monday',
            self::Tuesday => 'Tuesday',
            self::Wednesday => 'Wednesday',
            self::Thursday => 'Thursday',
            self::Friday => 'Friday',
            self::Saturday => 'Saturday',
        };
    }

    /** The three-letter head a column of seven checkboxes wears. */
    public function shortLabel(): string
    {
        return substr($this->label(), 0, 3);
    }
}
