<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `notification_preferences` — the global notification defaults an Admin sets
     * (master prompt Part D §20: *"`notification_preferences` (type, channel, enabled —
     * **Phase 12**)"*, and *"global defaults set by Admin … the engine reads them"*).
     *
     * ## There is NO CHECK on `type` and NO CHECK on `channel`, and that is the decision
     *
     * `notifications.type` carries one, generated from `NotificationType` at the moment its
     * migration ran, and the cost of that is on the record: `2026_09_23_000004` widened it for
     * the due-tomorrow reminder, `2026_09_26_000204` for leave, `2026_09_27_000103` for
     * messages and `2026_10_01_0005` for meetings — four migrations that exist only because a
     * CHECK freezes an enum. Decisions 3-6 and 7-12 are the same lesson recorded twice. A
     * `migrate:fresh` database never shows the bug, because it writes the constraint from the
     * enum as it stands today; **the client's database was created at Phase 2 and knows ten
     * types**, so on the live database the first row a new case tried to write failed with a
     * constraint violation that reads like a broken feature.
     *
     * Three things make this table the one where that trade goes the other way:
     *
     *   1. **A row here is not a record of something that happened — it is a switch somebody
     *      set.** `notifications.type` describes an event; a wrong value there would corrupt a
     *      person's mail. A wrong value here is a preference about a type nothing will ever ask
     *      about, because the read path below looks preferences up **by** the enum case it is
     *      already holding. It cannot be read into existence, it cannot be joined against, and
     *      it cannot change what any recipient is sent.
     *   2. **Absent means default, so an unreadable row is the same as no row.** The engine
     *      asks "is there a row saying `off` for this case on this channel"; a row naming a
     *      value that is not a case is never that row. A stale preference is inert, which is
     *      exactly what a CHECK would be protecting against here — at the price of a
     *      widening migration per future notification type, on a live database, for ever.
     *   3. **The write path is closed by validation, not by the schema.**
     *      `UpdateNotificationDefaultRequest` refuses any `type` outside
     *      `NotificationType::values()` and any `channel` the type's own `channels()` list does
     *      not name — which is both the "no sender behind it" rule and a check the schema could
     *      not express anyway, because which channels are real is a fact about the *type*.
     *
     * So the constraints this table does get are the ones that do not depend on an enum:
     * `UNIQUE (type, channel)`, because a pair with two rows would be a switch that is both on
     * and off, and NOT NULL on all three columns. The unique index is also the read index — the
     * whole table is a handful of rows and the engine loads it by pair.
     *
     * ## Why there is no row per type × channel here, and no seeder
     *
     * Pre-seeding every pair is what makes a table like this go stale the moment a
     * `NotificationType` case is added: the new case has no row, and a table that is *supposed*
     * to be exhaustive would have to read a missing row as `off` to stay consistent — so a type
     * nobody has ever configured would silently deliver nothing. A row is written only when an
     * Admin turns something off (or back on), and nothing at all is seeded. `NotificationType`
     * stays the single source of what types exist; this table only records the exceptions.
     */
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();

            // `NotificationType::value` — a dotted string, 40 to match `notifications.type`.
            // Deliberately not a native Postgres enum and deliberately not CHECKed; see above.
            $table->string('type', 40);

            // `NotificationChannel::value` — `in_app`, `web_push` or `mail`.
            $table->string('channel', 20);

            // No default. A row exists because somebody decided something, so the decision is
            // always stated; "not decided" is the absence of the row, not a `NULL` in it.
            $table->boolean('enabled');

            $table->timestamps();

            // One switch per pair. Also the index the engine reads the table by — there is no
            // second access pattern, and at one row per exception there never will be.
            $table->unique(['type', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
