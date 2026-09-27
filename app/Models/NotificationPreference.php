<?php

namespace App\Models;

use App\Support\NotificationChannel;
use App\Support\NotificationType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One global notification default: *this type, on this channel, is off* (or is back on).
 *
 * Part D §20 gives the table three columns — `type, channel, enabled` — and one sentence about
 * who owns it: *"global defaults set by Admin, Phase 12; the engine reads them"*. There is no
 * `user_id`. Per-person preferences are not in the MVP and are not implied by this table; adding
 * them later is a nullable column and a second lookup, not a rewrite.
 *
 * ## Absent means default, not off
 *
 * The table holds **exceptions only**, and nothing seeds it. `NotificationType` is still the
 * list of what exists and `NotificationType::channels()` is still the per-type default; a row
 * here overrides that default for one pair. The alternative — a row per type × channel, written
 * by a seeder — goes stale the moment a `NotificationType` case is added: the new case has no
 * row, and a table that claims to be exhaustive has to read a missing row as `off`, so a type
 * nobody has ever configured would silently stop being delivered. `enabledIn()` therefore
 * defaults to the type's own answer whenever it finds nothing.
 *
 * ## `type` and `channel` are strings, not enum casts
 *
 * Deliberately, and for the same reason the migration puts no CHECK on either column: the enum
 * moves. An enum cast throws `ValueError` on hydration, so a row left behind by a case that was
 * renamed or removed would turn every read of this table — including the engine's, on every
 * notification — into a 500. As strings they are inert: `overrides()` keys them and a key
 * nothing asks for is never read. The values that may be *written* are closed by
 * `UpdateNotificationDefaultRequest`, which asks the enum.
 */
#[Fillable(['type', 'channel', 'enabled'])]
class NotificationPreference extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    /**
     * Every stored exception, keyed by the pair it is about.
     *
     * One query for the whole table, because the whole table is the answer: it holds a row per
     * switch an Admin has touched, which in an agency of fifteen is a handful. The caller keeps
     * it for as long as it is entitled to — see `NotificationService::deliver()`.
     *
     * @return array<string, bool>
     */
    public static function overrides(): array
    {
        return self::query()
            ->get(['type', 'channel', 'enabled'])
            ->mapWithKeys(fn (self $preference): array => [
                self::key((string) $preference->type, (string) $preference->channel) => (bool) $preference->enabled,
            ])
            ->all();
    }

    /**
     * Is this type deliverable on this channel, given the stored overrides?
     *
     * Two questions in order, and the order is the rule:
     *
     *   1. **Does the type list the channel at all?** `NotificationType::channels()` is the
     *      default and the only statement of which channels are real. A channel the type does
     *      not name has no sender behind it, and no stored row can conjure one — which is why
     *      `web_push` and `mail` cannot be switched on from the screen, and why a request that
     *      tries is refused rather than saved and ignored.
     *   2. **Then, is there a row turning it off?** Absent means default.
     *
     * @param  array<string, bool>  $overrides  as returned by `overrides()`
     */
    public static function enabledIn(array $overrides, NotificationType $type, NotificationChannel $channel): bool
    {
        if (! in_array($channel, $type->channels(), true)) {
            return false;
        }

        return $overrides[self::key($type->value, $channel->value)] ?? true;
    }

    /**
     * The one spelling of "this type on this channel", so the map and every lookup agree.
     */
    public static function key(string $type, string $channel): string
    {
        return $type.':'.$channel;
    }
}
