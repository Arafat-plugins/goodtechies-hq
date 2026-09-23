<?php

namespace App\Support;

/**
 * The master prompt's High / Normal / Low, the third step of the §11 pipeline
 * (event → recipients → **priority** → dedup/group → stored row).
 *
 * It is a property of the notification TYPE and not a column on the row — see
 * NotificationType::priority(). The spec's own column list for `notifications` is
 * `user_id, type, payload, group_key, count, is_read, read_at, created_at`, and a stored
 * priority would be a copy of something the type already answers: two "in review" rows can
 * never disagree about how urgent they are, so there is nothing per-row to store.
 */
enum NotificationPriority: string
{
    /** Somebody is blocked until this person acts. */
    case High = 'high';

    /** Their own work changed hands or state. */
    case Normal = 'normal';

    /** Worth knowing, never worth interrupting for. */
    case Low = 'low';

    public function label(): string
    {
        return match ($this) {
            self::High => 'High',
            self::Normal => 'Normal',
            self::Low => 'Low',
        };
    }

    /**
     * Most urgent first, for a list that sorts by it.
     */
    public function rank(): int
    {
        return match ($this) {
            self::High => 0,
            self::Normal => 1,
            self::Low => 2,
        };
    }
}
