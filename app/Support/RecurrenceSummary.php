<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * A recurrence rule as a sentence: *"On the 1st of every month, due on the last day"*.
 *
 * It is a **presenter over RecurrenceRule and nothing else**. It reads the rule's parameters and
 * spells them; it computes no dates, decides no periods, and has no opinion about when anything
 * fires. Every date on the templates screen — the next run, the period it belongs to, the due
 * date of the instance that run would make — comes from `RecurrenceRule` itself, asked once on
 * the server and sent as a formatted string. There is deliberately no second copy of this in
 * Vue, for the same reason there is no second copy of the period key: a screen that spelled the
 * rule itself would start disagreeing with the engine the first time a clamp changed.
 *
 * It lives beside the rule rather than inside it so that the engine's class stays the four date
 * questions it advertises. A sentence is a screen's problem.
 */
final class RecurrenceSummary
{
    /**
     * The ordinals a day-of-month can be. The rule clamps to 28, so the list is finite and a
     * `switch` on the last digit — which gets 11th, 12th and 13th wrong — is not needed.
     *
     * @var list<string>
     */
    private const ORDINALS = [
        '', '1st', '2nd', '3rd', '4th', '5th', '6th', '7th', '8th', '9th', '10th',
        '11th', '12th', '13th', '14th', '15th', '16th', '17th', '18th', '19th', '20th',
        '21st', '22nd', '23rd', '24th', '25th', '26th', '27th', '28th',
    ];

    /**
     * ISO weekdays, 1 = Monday, matching `RecurrenceRule::$weekday`.
     *
     * @var array<int, string>
     */
    private const WEEKDAYS = [
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
        7 => 'Sunday',
    ];

    /**
     * The whole rule in one line — what the templates list prints under "Recurrence".
     */
    public static function for(RecurrenceRule $rule): string
    {
        return self::cadence($rule).', '.self::due($rule).'.';
    }

    /**
     * How often, and on which day of the period.
     */
    public static function cadence(RecurrenceRule $rule): string
    {
        return match ($rule->frequency) {
            RecurrenceFrequency::Monthly => sprintf('On the %s of every month', self::ordinal($rule->dayOfMonth)),
            RecurrenceFrequency::Weekly => sprintf('Every %s', self::weekday($rule->weekday)),
            RecurrenceFrequency::Custom => sprintf(
                'Every %s from %s',
                $rule->intervalDays === 1 ? 'day' : $rule->intervalDays.' days',
                ($rule->anchor ?? Carbon::today())->format('j M Y'),
            ),
        };
    }

    /**
     * When the instance it makes is due.
     *
     * `due_offset_days` counts from the period's START on all three frequencies — one knob, not
     * three — so the sentence says "days after it starts" rather than naming a date, which would
     * be a date this class had worked out for itself.
     */
    public static function due(RecurrenceRule $rule): string
    {
        if ($rule->dueOffsetDays === null) {
            return 'due at the end of the period';
        }

        if ($rule->dueOffsetDays === 0) {
            return 'due the day it is created';
        }

        return sprintf(
            'due %d day%s after the period starts',
            $rule->dueOffsetDays,
            $rule->dueOffsetDays === 1 ? '' : 's',
        );
    }

    public static function ordinal(int $day): string
    {
        return self::ORDINALS[$day] ?? $day.'th';
    }

    public static function weekday(int $weekday): string
    {
        return self::WEEKDAYS[$weekday] ?? 'Monday';
    }
}
