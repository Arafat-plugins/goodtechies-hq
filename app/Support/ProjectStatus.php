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
     * The `StatusBadge` key — the server's answer, the way `TaskStatus::tone()` is.
     *
     * Added for decision M-14: the Messages context panel printed a project's status as a bare
     * lower-cased word because the endpoint sent a key with no tone and no label, and this repo's
     * rule is that a screen never maps a status to a colour itself. The same disagreement
     * decision 10-30 settled on the reports, settled the same way — on the server, once.
     *
     * The five values match `toneForProjectStatus()` in `Components/StatusPill.vue` exactly, and
     * that copy is the one that should go: eight project screens still call it because
     * `ProjectResource` sends `status_label` and no tone. Converging them is a payload change
     * across those screens rather than part of this fix, and it is in the report as a follow-up.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Active => 'progress',
            self::OnHold => 'waiting',
            self::Completed => 'done',
            self::Cancelled => 'cancelled',
            self::Archived => 'todo',
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
