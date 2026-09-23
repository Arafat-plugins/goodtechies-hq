<?php

namespace App\Support;

/**
 * The Notification Center's tabs (master prompt §11): All / Tasks / Messages / Meetings /
 * Leave / Payroll / System.
 *
 * All seven are declared now because the Center draws all seven, and a tab that appears in
 * Phase 7 would otherwise move the ones either side of it. **Only `Tasks` and `System` can
 * produce a row in Phase 2** — `types()` returns an empty list for the other four, so their
 * tabs come back empty rather than fabricated, and the tab a later phase fills is filled by
 * adding a NotificationType with that tab and nothing else.
 */
enum NotificationTab: string
{
    /** Not a tab of its own so much as the absence of a filter. */
    case All = 'all';

    case Tasks = 'tasks';
    case Messages = 'messages';
    case Meetings = 'meetings';
    case Leave = 'leave';
    case Payroll = 'payroll';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::All => 'All',
            self::Tasks => 'Tasks',
            self::Messages => 'Messages',
            self::Meetings => 'Meetings',
            self::Leave => 'Leave',
            self::Payroll => 'Payroll',
            self::System => 'System',
        };
    }

    /**
     * The notification types this tab shows.
     *
     * `All` returns every type rather than null, so a caller filters the same way whichever
     * tab was asked for and no query has a special case in it.
     *
     * @return list<NotificationType>
     */
    public function types(): array
    {
        if ($this === self::All) {
            return NotificationType::cases();
        }

        return array_values(array_filter(
            NotificationType::cases(),
            fn (NotificationType $type): bool => $type->tab() === $this,
        ));
    }

    /**
     * Can this tab hold anything at all in the phase that is built?
     *
     * The Center shows every tab; this is what lets it say "arrives in Phase 7" against the
     * four that are still empty instead of showing an empty list that looks like a bug.
     */
    public function isBuilt(): bool
    {
        return $this->types() !== [];
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $tab): string => $tab->value, self::cases());
    }
}
