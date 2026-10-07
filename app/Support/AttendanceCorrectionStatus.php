<?php

namespace App\Support;

/**
 * Polish 029: where an employee's request to correct a day stands.
 */
enum AttendanceCorrectionStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting for approval',
            self::Approved => 'Approved',
            self::Rejected => 'Declined',
        };
    }
}
