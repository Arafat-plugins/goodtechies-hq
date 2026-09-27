<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * The precision the unread line is measured at — decision M-15.
 *
 * ## One comparison, three places it has to agree
 *
 * "Have I read this?" is `messages.created_at > conversation_members.last_read_at`, and it is
 * asked twice on the server (`ConversationService::readState()` and `unreadCounts()`) and once
 * in the browser (`MessageThread.vue` draws the new-messages line from the same two values). So
 * three things must carry the same precision, and each of them silently dropped it:
 *
 *   1. **The columns.** `timestamp(0)`, so Postgres rounded every value to the second. The
 *      `2026_10_27_0001` migration widened both to `timestamp(6)`.
 *   2. **The write.** Eloquent formats every date binding with the query grammar's
 *      `Y-m-d H:i:s` whatever the column can hold, so `SQL_FORMAT` is what `Message::$dateFormat`
 *      is set to and what `markRead()` binds — as a STRING, because a `DateTimeInterface`
 *      binding goes back through the grammar and is truncated again.
 *   3. **The payload.** `Carbon::toIso8601String()` has no fractional part at all, so a thread
 *      and a read line that were microseconds apart on the server arrived in the browser equal.
 *      `ISO_FORMAT` is what `MessageResource` and `readState()` send.
 *
 * At second precision a reply posted in the same second as a read is not later than the read but
 * EQUAL to it, and `>` calls it already-read: no badge, no new-messages line, no trace. That is
 * a message lost rather than a race, and rounding made it reach 500ms the wrong side of the line.
 *
 * ## Deliberately narrow
 *
 * This is not "how this application formats dates". Everything else in the repo sends
 * `toIso8601String()` and reads to the second because a *display* of a time has no use for
 * microseconds — a relative time, a decision stamp, an audit row. These two values are different
 * in kind: they are not read, they are **compared to each other**, and the comparison is the
 * feature. Do not reach for this for a timestamp somebody is going to look at.
 */
final class UnreadLine
{
    /**
     * How a message timestamp and a read line are WRITTEN to and COMPARED in Postgres.
     *
     * Six digits, because that is what `timestamp(6)` keeps and what PHP has to give.
     */
    public const SQL_FORMAT = 'Y-m-d H:i:s.u';

    /**
     * How either of them LEAVES the server.
     *
     * Milliseconds, not microseconds: `Date.parse` in the browser keeps three digits and drops
     * the rest, so sending six would be precision the reader cannot use — and the two sides of
     * the comparison would round differently, which is the bug again one order down.
     */
    public const ISO_FORMAT = 'Y-m-d\TH:i:s.vP';

    /**
     * A binding for a comparison against one of those columns.
     *
     * A string and not the Carbon instance: `Connection::prepareBindings()` runs every
     * `DateTimeInterface` binding through `Grammar::getDateFormat()`, which is `Y-m-d H:i:s`,
     * so passing the object would throw the microseconds away between here and the database.
     */
    public static function sql(CarbonInterface $at): string
    {
        return $at->format(self::SQL_FORMAT);
    }

    /**
     * The payload form. Null stays null — never read means everything is unread, and that is a
     * different sentence from "read at the epoch".
     */
    public static function iso(?CarbonInterface $at): ?string
    {
        return $at?->format(self::ISO_FORMAT);
    }
}
