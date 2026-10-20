<?php

namespace App\Support;

/**
 * Where a bonus stands: pending → approved → paid, or cancelled. Only approved and paid
 * bonuses count in a payroll item's bonus.
 */
enum BonusStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Paid => 'Paid',
            self::Cancelled => 'Cancelled',
        };
    }

    public function countsInPayroll(): bool
    {
        return $this === self::Approved || $this === self::Paid;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $status): array => ['value' => $status->value, 'label' => $status->label()],
            self::cases(),
        );
    }
}
