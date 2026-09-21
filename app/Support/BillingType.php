<?php

namespace App\Support;

enum BillingType: string
{
    case OneTime = 'one_time';
    case MonthlyRecurring = 'monthly_recurring';
    case CustomRecurring = 'custom_recurring';

    public function label(): string
    {
        return match ($this) {
            self::OneTime => 'One-Time',
            self::MonthlyRecurring => 'Monthly Recurring',
            self::CustomRecurring => 'Custom Recurring',
        };
    }
}
