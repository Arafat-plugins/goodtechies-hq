<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A message may be a reply to another message in the same conversation (decision 12-82).
 *
 * - `messages.reply_to_id`: the message this one answers, or null. "Same conversation" is
 *   enforced by MessageService::post(), the only writer. Null on delete, so hard-removing the
 *   original never takes the reply with it (a delete-for-everyone keeps the row anyway).
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return 'pgsql_migrator';
    }

    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('reply_to_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->index('reply_to_id');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropForeign(['reply_to_id']);
            $table->dropIndex(['reply_to_id']);
            $table->dropColumn('reply_to_id');
        });
    }
};
