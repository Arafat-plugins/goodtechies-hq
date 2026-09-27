<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The unread line, at sub-second precision — decision M-15.
 *
 * ## The bug this closes
 *
 * "Have I read this?" is one comparison, `messages.created_at > conversation_members.last_read_at`,
 * asked by `ConversationService::readState()` and `unreadCounts()`. Both columns were
 * `timestamp(0)`: Laravel's `timestamp()` and `timestampTz()` default to precision **0**, so
 * Postgres ROUNDED every value to the nearest second on the way in.
 *
 * At that precision a reply posted in the same second as a read is not later than the read, it
 * is EQUAL to it — and `>` says already-read. The reader's badge never counts it, the thread
 * draws no "new messages" line above it, and nothing anywhere says a message was skipped. On
 * this team, where opening a thread marks it read and the other person is often typing as you
 * do, that is a real message quietly lost rather than a theoretical race. Rounding makes it
 * worse than truncation would: a read at 12:00:00.6 is stored as 12:00:01, so a message posted
 * 300ms AFTER it was already read.
 *
 * ## Why a migration is the fix rather than a tighter query
 *
 * There was no precision to compare. The column could not hold the fraction of a second that
 * distinguishes the two events, so no amount of care at the call site could recover it — and
 * the two halves that write these values (`Message`'s `$dateFormat`, and the string
 * `markRead()` binds) are worth nothing until the columns can keep what they send.
 * `App\Support\UnreadLine` holds the formats; this holds the room for them.
 *
 * Six digits, not three: `Y-m-d H:i:s.u` is what PHP hands over, and a column that silently
 * rounded microseconds to milliseconds would be the same class of bug one order down.
 *
 * Both sides move together. Widening one of them would leave the comparison exactly as blind as
 * it was, which is the kind of half-fix that reads as done.
 *
 * `updated_at` rides along on `messages` so the pair keeps one precision; nothing compares it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE messages ALTER COLUMN created_at TYPE timestamp(6) without time zone');
        DB::statement('ALTER TABLE messages ALTER COLUMN updated_at TYPE timestamp(6) without time zone');
        DB::statement('ALTER TABLE conversation_members ALTER COLUMN last_read_at TYPE timestamp(6) with time zone');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE messages ALTER COLUMN created_at TYPE timestamp(0) without time zone');
        DB::statement('ALTER TABLE messages ALTER COLUMN updated_at TYPE timestamp(0) without time zone');
        DB::statement('ALTER TABLE conversation_members ALTER COLUMN last_read_at TYPE timestamp(0) with time zone');
    }
};
