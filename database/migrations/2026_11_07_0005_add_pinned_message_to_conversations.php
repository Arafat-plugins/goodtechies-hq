<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Polish 044: a conversation can have ONE pinned message (the client chose "one at a time").
     * Today only the announcements channel uses it: the pinned announcement is the app-wide
     * banner, and with nothing pinned there is no banner. Deleting the message clears the pin.
     */
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->foreignId('pinned_message_id')->nullable()->index()->constrained('messages')->nullOnDelete();
            $table->timestamp('pinned_at')->nullable();
            $table->foreignId('pinned_by')->nullable()->index()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pinned_by');
            $table->dropColumn('pinned_at');
            $table->dropConstrainedForeignId('pinned_message_id');
        });
    }
};
