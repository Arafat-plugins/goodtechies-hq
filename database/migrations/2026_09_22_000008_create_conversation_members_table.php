<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who has been in a conversation, and how far they have read.
     *
     * ## This table does NOT grant access to a task discussion
     *
     * That sentence is the whole of the slice's privacy decision, so it is written on the table
     * itself rather than left in a policy somebody might not open.
     *
     * The spec says the task conversation's membership "follows task access". Task access is
     * not a stored list: it is TaskPolicy::view — an Admin or Manager sees everything, an
     * employee sees the tasks ASSIGNED to them — and it changes when a task is reassigned, when
     * somebody's role changes, and when a user is deactivated. None of those write to this
     * table, and there is no reason they ever should.
     *
     * So for a `task` conversation, membership is COMPUTED, every time, from the linked task,
     * and a row here means only "this person has been in the room, and this is where their
     * unread line sits". A row that outlives somebody's access to the task is therefore inert:
     * it carries a timestamp and nothing else, and ConversationPolicy never reads it. The
     * alternative — maintaining this table as the grant, and syncing it from every write that
     * changes who can see a task — is the shape a privacy hole comes in, because it is correct
     * only for as long as nobody adds a sixth way to change task access.
     *
     * Phase 6's other conversation types have no task to compute from: a DM's audience IS these
     * rows, and for those types this table will be both the membership and the read state.
     * `ConversationType::membershipIsComputed()` is where that split is named.
     *
     * ## Shape
     *
     * The pair is the identity, so it is the primary key: no surrogate id, and a person cannot
     * be in one conversation twice. `last_read_at` is nullable — null means "has never read
     * it", which is not the same as having read it at the epoch.
     */
    public function up(): void
    {
        Schema::create('conversation_members', function (Blueprint $table) {
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // Where this person's unread line sits. Null until they open it for the first time.
            $table->timestampTz('last_read_at')->nullable();

            $table->timestamps();

            $table->primary(['conversation_id', 'user_id']);

            // The reverse direction — "every conversation this person is in" — which is Phase
            // 6's inbox and which the primary key above cannot serve (wrong leading column).
            $table->index(['user_id', 'conversation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_members');
    }
};
