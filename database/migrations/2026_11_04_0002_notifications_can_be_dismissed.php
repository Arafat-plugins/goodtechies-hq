<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Polish 012: a person can delete (dismiss) notifications from their bell and Center.
 *
 * A dismissal is a timestamp, not a DELETE, because this table is also its own memory:
 * `NotificationService::alreadySentFor()` asks "has this ever been sent?" so that the overdue
 * and due-tomorrow sends happen once per task. Deleting the row would make the next morning's
 * run send it again. A dismissed row is always a read one (the CHECK), so it never counts
 * towards a badge and is never grouped into by a later delivery (`groupable` needs unread).
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return 'pgsql_migrator';
    }

    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->timestampTz('dismissed_at')->nullable();
        });

        DB::statement(
            'ALTER TABLE notifications ADD CONSTRAINT notifications_dismissed_is_read '
            .'CHECK (dismissed_at IS NULL OR is_read)'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE notifications DROP CONSTRAINT IF EXISTS notifications_dismissed_is_read');

        Schema::table('notifications', function (Blueprint $table) {
            $table->dropColumn('dismissed_at');
        });
    }
};
