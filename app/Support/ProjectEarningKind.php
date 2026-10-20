<?php

namespace App\Support;

/**
 * What a project earnings entry records. The three are never interchangeable: a contract value
 * is a price, an earned amount is billable work, and only a received amount is money in.
 */
enum ProjectEarningKind: string
{
    case ContractValue = 'contract_value';
    case Earned = 'earned';
    case Received = 'received';

    public function label(): string
    {
        return match ($this) {
            self::ContractValue => 'Contract value',
            self::Earned => 'Earned',
            self::Received => 'Received',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ContractValue => 'The agreed price of the work, not money in.',
            self::Earned => 'Work done and billable, whether or not it has been paid.',
            self::Received => 'Money that has actually arrived from the client.',
        };
    }

    /**
     * @return list<array{value: string, label: string, description: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $kind): array => [
                'value' => $kind->value,
                'label' => $kind->label(),
                'description' => $kind->description(),
            ],
            self::cases(),
        );
    }
}
