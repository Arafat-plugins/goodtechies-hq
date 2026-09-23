<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `notifications.resolved_at` — when the thing this row asked for was DONE (decision 2-48).
     *
     * ## Why this is not `is_read`
     *
     * A notification is a request for attention. Reading it and answering it are two different
     * events and the table has to be able to tell them apart, because the §11 dedup rule is
     * written on top of the read one:
     *
     *   - **read** (`is_read`, `read_at`) means *you looked*. Decision 2-33 hangs off it: a row
     *     you have already looked at does not absorb the next event, so reading closes a group
     *     and the next event starts a fresh row. Without that, reading "3 new comments" silently
     *     swallows the fourth.
     *   - **resolved** (`resolved_at`) means *you dealt with the subject*. It is set by
     *     NotificationService when the recipient is the ACTOR of an act the type names as
     *     resolving — a reviewer ruling on the task they were asked to review. It leaves the
     *     bell, the Center and the unread count, and it does NOT close the group: a
     *     resubmission grows the same row, clears this column, and the row comes back saying
     *     *"…is waiting for your review again"*.
     *
     * Storing the second as the first would have been one column cheaper and would have broken
     * 2-33's rule in the one place it is visible: the resubmission would have started a new row
     * of one and the reviewer would have been told, for the second time, what they were told an
     * hour ago.
     *
     * ## No index, and no new CHECK
     *
     * The partial index `notifications_unread` is `WHERE is_read = false`, and it stays that
     * way on purpose: it serves BOTH questions this column splits. The unread badge adds
     * `resolved_at IS NULL` on top of it, over a set that is already one person's unread mail;
     * the dedup lookup deliberately does not, because a resolved row must still be groupable.
     * Narrowing the index to unresolved rows would make the second query miss the row it exists
     * to find.
     *
     * There is no CHECK pairing this with anything. `is_read`/`read_at` have one because they
     * are two spellings of one fact and either without the other is a lie; `resolved_at` is a
     * single nullable timestamp and cannot contradict itself.
     */
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->timestampTz('resolved_at')->nullable()->after('read_at');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropColumn('resolved_at');
        });
    }
};
