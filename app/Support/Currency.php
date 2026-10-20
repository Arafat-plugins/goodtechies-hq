<?php

namespace App\Support;

use App\Services\SettingsService;

/**
 * The currency a finance record is kept in. Every income row, expense row and repeating-expense
 * template carries its own.
 *
 * Amounts in different currencies are never added together, and there is no exchange rate:
 * totals are always reported per currency.
 */
enum Currency: string
{
    case BDT = 'BDT';
    case USD = 'USD';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $currency): string => $currency->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::BDT => 'Bangladeshi taka (BDT)',
            self::USD => 'US dollar (USD)',
        };
    }

    /**
     * The currency in the settings (payroll is paid in it), or BDT when that is not a known one.
     */
    public static function default(): self
    {
        return self::tryFrom(strtoupper((string) app(SettingsService::class)->get('currency'))) ?? self::BDT;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $currency): array => ['value' => $currency->value, 'label' => $currency->label()],
            self::cases(),
        );
    }
}
