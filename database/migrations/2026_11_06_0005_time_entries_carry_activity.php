<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Time entries carry their activity rollup (Phase 11, `docs/extension-api.md` §6 and §8).
 *
 * `active_minutes`, `media_minutes`, `call_minutes` and `idle_minutes` are written from
 * `activity_samples` on every pause and stop, and they stay when the per-minute rows are pruned.
 * `idle_pending_from` and `idle_auto_paused_at` record a server auto-pause the person has not
 * yet answered; `discarded_seconds` is the idle time they chose to discard.
 *
 * Every column defaults to 0 or null, so every existing row reads exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->unsignedInteger('active_minutes')->default(0);
            $table->unsignedInteger('media_minutes')->default(0);
            $table->unsignedInteger('call_minutes')->default(0);
            $table->unsignedInteger('idle_minutes')->default(0);
            $table->string('activity_source', 10)->nullable();
            $table->timestampTz('idle_pending_from')->nullable();
            $table->timestampTz('idle_auto_paused_at')->nullable();
            $table->unsignedInteger('discarded_seconds')->default(0);
        });

        DB::statement(<<<'SQL'
            alter table time_entries
            add constraint time_entries_activity_source_check
            check (activity_source is null or activity_source in ('extension','web'))
        SQL);
    }

    public function down(): void
    {
        DB::statement('alter table time_entries drop constraint if exists time_entries_activity_source_check');

        Schema::table('time_entries', function (Blueprint $table) {
            $table->dropColumn([
                'active_minutes',
                'media_minutes',
                'call_minutes',
                'idle_minutes',
                'activity_source',
                'idle_pending_from',
                'idle_auto_paused_at',
                'discarded_seconds',
            ]);
        });
    }
};
