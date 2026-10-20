<?php

namespace App\Support;

/**
 * What kind of bonus an `employee_bonuses` row is. `Custom` carries its own title.
 */
enum BonusType: string
{
    case Eid = 'eid';
    case Annual = 'annual';
    case Performance = 'performance';
    case Reward = 'reward';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Eid => 'Eid bonus',
            self::Annual => 'Annual bonus',
            self::Performance => 'Performance bonus',
            self::Reward => 'Special reward',
            self::Custom => 'Custom',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $type): array => ['value' => $type->value, 'label' => $type->label()],
            self::cases(),
        );
    }
}
