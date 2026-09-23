<?php

namespace App\Support;

/**
 * How often a recurring template fires (master prompt Part D §6: "recurrence rule (monthly /
 * weekly / custom)").
 *
 * The three cases differ in exactly two things — how long a period is, and how its key is
 * spelled — and both answers live in RecurrenceRule, not here. This enum is the name of the
 * choice; it is deliberately not a place to hang behaviour, because a rule type that carried
 * its own period arithmetic is how weekly and custom end up with two different ideas of when
 * a period starts.
 */
enum RecurrenceFrequency: string
{
    case Monthly = 'monthly';
    case Weekly = 'weekly';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Monthly',
            self::Weekly => 'Weekly',
            self::Custom => 'Custom',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $frequency): string => $frequency->value, self::cases());
    }
}
