<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One message in a conversation — which, for a task, is one "comment".
     *
     * ## Why `body` is nullable
     *
     * A Phase 2 message always has text or a file, and ConversationService refuses one with
     * neither. But Phase 6's voice note is a message whose whole content is its attachment, and
     * `body NOT NULL` would meet that with a migration and a round of `''` placeholder rows. A
     * nullable column costs nothing and the rule that matters — "a message says something" —
     * cannot be expressed in a CHECK anyway, because it depends on a row in another table.
     *
     * ## No edit, no delete, no soft delete
     *
     * There is no `edited_at`, no `deleted_at` and no endpoint for either. A message is a thing
     * somebody said at a time; rewriting it afterwards would rewrite the record other people
     * are answering. That is also why a message ATTACHMENT cannot be replaced (FilePolicy) —
     * the two rules are the same rule.
     *
     * `author_id` is nullOnDelete rather than cascade for the reason `files.uploaded_by` is:
     * losing the user must not silently delete what they wrote. Users are not deleted here in
     * any case, so this is about the shape of the record, not about an expected event.
     */
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();

            // Plain text. Rendering is the panel's business and it escapes; nothing here stores
            // or trusts markup.
            $table->text('body')->nullable();

            $table->timestamps();

            // The only read this table has: one conversation's messages in order. `id` rather
            // than `created_at` as the tie-break, so two messages posted in the same second
            // have a stable order.
            $table->index(['conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
