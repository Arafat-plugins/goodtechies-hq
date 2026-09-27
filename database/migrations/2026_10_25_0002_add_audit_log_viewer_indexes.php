<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The three indexes the Audit Log viewer's filters need (Phase 12, decision 12-19).
 *
 * ## Why this was not noticed until now
 *
 * `audit_logs` has been written to since Phase 0 and **read by nobody** until this slice built
 * the viewer. A write-only table needs no index beyond its primary key, so it had exactly one:
 * `event`, added because the append-only test looks rows up by it. The moment a screen offers
 * filters, three of the four are sequential scans.
 *
 * Which is the one table where that matters most. Every phase writes to it and nothing ever
 * deletes from it — it cannot be — so it is the fastest-growing table in the application and
 * the only one that is permanently append-only. A scan that is instant against the seeded
 * handful is a scan against two years of an agency's history.
 *
 * ## The three, and why each is shaped the way it is
 *
 *   - **`(created_at desc)`** — the viewer's default ordering is newest-first and its date
 *     range cuts on this column. Descending because that is the direction it is read in; a
 *     btree can be walked backwards, but saying it here costs nothing and states the intent.
 *   - **`(actor_id)`** — the "who did this" filter. **PostgreSQL does not index a foreign key
 *     automatically**, which is the part that surprises people coming from MySQL's InnoDB: the
 *     FK constraint is enforced against the *referenced* table's key, and the referencing
 *     column is left bare.
 *   - **`(target_type, target_id)`** — the "what was this done to" filter, composite and in
 *     that order because `target_type` is always given when `target_id` is, and never the other
 *     way round. One index answers both the type-alone and the type-plus-id question; the
 *     reverse order would answer neither well.
 *
 * ## This does not weaken the append-only guarantee
 *
 * Indexes are the schema owner's (`hq_migrator`), like every other migration here. `hq_app`'s
 * REVOKE of UPDATE, DELETE and TRUNCATE is untouched, and adding an index grants nothing —
 * `tests/Feature/Database/AuditLogAppendOnlyTest.php` still proves that with raw statements.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->index(['created_at'], 'audit_logs_created_at_index');
            $table->index(['actor_id'], 'audit_logs_actor_id_index');
            $table->index(['target_type', 'target_id'], 'audit_logs_target_index');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropIndex('audit_logs_created_at_index');
            $table->dropIndex('audit_logs_actor_id_index');
            $table->dropIndex('audit_logs_target_index');
        });
    }
};
