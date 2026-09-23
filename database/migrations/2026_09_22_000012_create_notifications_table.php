<?php

use App\Support\NotificationType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Notifications (master prompt Part D §20: `user_id, type, payload JSON, group_key, count,
     * is_read, read_at, created_at`).
     *
     * ## This is not an audit log
     *
     * `audit_logs` is who-did-what-to-what, append-only, and its migration REVOKES UPDATE and
     * DELETE from the runtime role so that nothing can tidy it up afterwards. `activity_logs` is
     * the prose timeline on one object. This table is neither: it is one person's mail, and the
     * two things that happen to a row after it is written — the dedup count going up, and the
     * row being marked read — are both UPDATEs. So the grants stay ordinary here, and that is
     * deliberate rather than an omission.
     *
     * ## `updated_at` exists, and audit_logs' does not, for the same reason
     *
     * `audit_logs` has no `updated_at` because it can never be updated. This row is updated on
     * its two hottest paths: §11's dedup rule increments `count` on an existing row, and
     * marking read writes `is_read` and `read_at`. A table whose rows are written twice and
     * whose columns say only when they were written the first time cannot answer "when did this
     * group last grow" — which is the difference between "12 new comments" that started two
     * minutes ago and one that started two minutes ago and stopped. Eloquent maintains it for
     * free, so the column costs a timestamp and buys the honest answer.
     *
     * ## Indexes: the two questions this table is asked
     *
     *   1. **The dedup lookup, on every single write.** "Is there an UNREAD row for this person,
     *      this group key, inside the window?" It is served by a PARTIAL index on the unread
     *      rows only — which is also, and not by accident, exactly the set the bell's unread
     *      count scans. One small index answers both, and it shrinks as people read their mail
     *      instead of growing with the table.
     *   2. **The lists.** The bell's recent ten and the Center's page, both `where user_id = ?
     *      order by created_at desc`. The Center's tab filter is a `type in (…)` on top of that,
     *      left to the filter step rather than given an index of its own: one person's
     *      notifications are hundreds of rows in an agency of fifteen, and a third index would
     *      cost every insert more than it saves those reads.
     *
     * ## CHECK constraints
     *
     * `type` is checked against NotificationType because the enum is the truth — the same shape
     * `conversations.type` uses, and for the same reason: a native Postgres enum needs ALTER
     * TYPE to grow, and this list grows every phase. `count` and the read pair are checked
     * because a row that says `is_read` with no `read_at`, or a group of zero, is not a row
     * anybody should have to defend against when reading.
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();

            // A notification belongs to exactly ONE person. That is what makes "somebody else's
            // notification" a 404 rather than a 403 in the controller: there is no such thing as
            // a shared row to be refused. Cascade, because mail addressed to a deleted user is
            // not addressed to anybody.
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('type', 40);

            // Everything the bell needs to draw the line without joining: the object's title,
            // who acted, what the object is, and the per-type context (the status moved to, the
            // number of open tasks). Never the object's private fields — a payload is written
            // once and read by one person, and re-reading the record at display time is what
            // keeps a notification from becoming a second copy of data with its own privacy
            // rules.
            $table->jsonb('payload');

            // type + object, computed in one place (NotificationService::groupKey). The
            // RECIPIENT is not in it: every lookup is already scoped by user_id, and putting
            // them in the key would be storing the same fact twice.
            $table->string('group_key', 191);

            // How many events this one row stands for. 1 for an ordinary notification; 12 for
            // §11's "12 new comments in [task]".
            $table->unsignedInteger('count')->default(1);

            $table->boolean('is_read')->default(false);
            $table->timestampTz('read_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'created_at'], 'notifications_user_created');
        });

        $types = implode(', ', array_map(
            fn (string $value): string => "'".$value."'",
            NotificationType::values(),
        ));

        DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_type_is_known CHECK (type IN ({$types}))");

        // Quoted, because `count` is a function name to the parser as well as a column here.
        DB::statement('ALTER TABLE notifications ADD CONSTRAINT notifications_count_is_positive CHECK ("count" >= 1)');

        // The read pair cannot contradict itself. `is_read` is what the bell counts and
        // `read_at` is when it happened; a row carrying one without the other is a row that
        // would make one of the two a lie.
        DB::statement(<<<'SQL'
            ALTER TABLE notifications ADD CONSTRAINT notifications_read_state_agrees CHECK (
                (is_read = false AND read_at IS NULL) OR (is_read = true AND read_at IS NOT NULL)
            )
        SQL);

        // The dedup lookup AND the unread badge, in one partial index over the unread rows.
        // Leading with user_id is what lets `where user_id = ? and is_read = false` count
        // straight off it; group_key and created_at then finish the dedup predicate.
        DB::statement(<<<'SQL'
            CREATE INDEX notifications_unread
            ON notifications (user_id, group_key, created_at DESC)
            WHERE is_read = false
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
