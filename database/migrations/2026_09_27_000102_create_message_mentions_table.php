<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who was named in a message (master prompt Part D §20: `message_mentions`).
     *
     * ## What this table is for, and what it is not
     *
     * It is the record that a person was ADDRESSED, which is a different fact from the message
     * body containing their name. Three things read it:
     *
     *   - the notification. A mention is its own `NotificationType` (`message.mentioned`), not
     *     a louder comment — see NotificationType.
     *   - the thread, which highlights the names it holds so a reader can see at a glance
     *     whether a line is aimed at them.
     *   - nothing else. It is **not** a permission: being mentioned in a conversation you may
     *     not read gets you nothing, because `ConversationPolicy` never looks here. That is the
     *     same rule `conversation_members` lives under (decision 2-24) and it is the reason
     *     `MessageService` refuses to write a row for somebody who cannot see the conversation:
     *     an unenforceable row is a row that will one day be treated as a grant.
     *
     * ## Shape
     *
     * The pair is the identity, so it is the primary key: naming somebody twice in one message
     * ("@tapu … @tapu") is one mention, decided by the schema rather than by the parser
     * remembering to de-duplicate.
     *
     * `user_id` cascades on delete for the same reason the rest of the communication tables do,
     * and `message_id` cascades because a mention of nobody in nothing is not a record.
     */
    public function up(): void
    {
        Schema::create('message_mentions', function (Blueprint $table) {
            $table->foreignId('message_id')->constrained('messages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->timestamps();

            $table->primary(['message_id', 'user_id']);

            // "Everything I have been named in", which the primary key above cannot serve —
            // wrong leading column. It is what a future Mentions filter on the Messages page
            // reads, and what makes the notification dispatch cheap today.
            $table->index(['user_id', 'message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_mentions');
    }
};
