<?php

namespace App\Support;

/**
 * How a project is billed. A Recurring project also carries a RecurrenceFrequency, and its
 * deadline is computed from the start date by ProjectService.
 */
enum BillingType: string
{
    case OneTime = 'one_time';
    case Recurring = 'recurring';

    public function label(): string
    {
        return match ($this) {
            self::OneTime => 'One-Time',
            self::Recurring => 'Recurring',
        };
    }
}
