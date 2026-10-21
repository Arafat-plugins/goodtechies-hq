<?php

namespace App\Support;

/**
 * How a bonus is paid: with the salary of its payment month (through payroll), or separately,
 * right now, outside payroll. A separate bonus is never linked to a payroll period.
 */
enum BonusPayout: string
{
    case WithPayroll = 'with_payroll';
    case Separate = 'separate';

    public function label(): string
    {
        return match ($this) {
            self::WithPayroll => 'With salary',
            self::Separate => 'Paid separately',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $payout): array => ['value' => $payout->value, 'label' => $payout->label()],
            self::cases(),
        );
    }
}
