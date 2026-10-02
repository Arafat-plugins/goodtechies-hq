<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * How often a Recurring project recurs (billing type Recurring). Not BillingFrequency, which
 * belongs to finance, and not RecurrenceFrequency, which is a recurring task template's rule.
 */
enum ProjectRecurrenceFrequency: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Biweekly = 'biweekly';
    case Monthly = 'monthly';

    public function label(): string
    {
        return match ($this) {
            self::Daily => 'Daily',
            self::Weekly => 'Weekly',
            self::Biweekly => 'Bi-weekly',
            self::Monthly => 'Monthly',
        };
    }

    /**
     * The deadline one period after $start. Monthly never overflows into the month after next:
     * Jan 31 → Feb 28 (Feb 29 in a leap year). Mirrored by `resources/js/lib/recurrence.ts`.
     */
    public function deadlineFrom(CarbonInterface $start): Carbon
    {
        $date = Carbon::instance($start)->startOfDay();

        return match ($this) {
            self::Daily => $date->addDay(),
            self::Weekly => $date->addWeek(),
            self::Biweekly => $date->addWeeks(2),
            self::Monthly => $date->addMonthNoOverflow(),
        };
    }
}
