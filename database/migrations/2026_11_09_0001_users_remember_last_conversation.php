<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The conversation Messages reopens: the person or group this user last had open (2026-10-14).
 *
 * Opening Messages without asking for a thread always landed on the team channel ("Resource"),
 * so somebody halfway through a DM had to find it again every visit. Kept on the account rather
 * than in the browser, so the phone and the computer agree. `null` = nothing remembered yet, and
 * a deleted conversation clears it (`nullOnDelete`); one they can no longer see is ignored by
 * MessageController, which only reopens it from the policy-checked inbox.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('last_conversation_id')
                ->nullable()
                ->constrained('conversations')
                ->nullOnDelete();

            $table->index('last_conversation_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['last_conversation_id']);
            $table->dropConstrainedForeignId('last_conversation_id');
        });
    }
};
