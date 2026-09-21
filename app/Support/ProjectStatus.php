<?php

namespace App\Support;

enum ProjectStatus: string
{
    case Active = 'active';
    case OnHold = 'on_hold';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::OnHold => 'On Hold',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
            self::Archived => 'Archived',
        };
    }

    /**
     * Whether the project is still being worked on (as opposed to finished, cancelled or archived).
     */
    public function isOpen(): bool
    {
        return $this === self::Active || $this === self::OnHold;
    }
}
