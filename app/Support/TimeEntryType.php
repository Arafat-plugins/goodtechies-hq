<?php

namespace App\Support;

/**
 * How a `time_entries` row came to exist (master prompt Part D §7).
 *
 * `Auto` is the timer's own writing: start, pauses, stop, all timestamped by the server as they
 * happened. `Manual` is a person typing in a stretch afterwards — always with a reason, and
 * routed through approval when `settings.manual_time_requires_approval` is on.
 *
 * The distinction is not cosmetic: it is what lets an Admin see at a glance which hours were
 * measured and which were remembered.
 */
enum TimeEntryType: string
{
    case Auto = 'auto';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Auto => 'Timer',
            self::Manual => 'Added by hand',
        };
    }
}
