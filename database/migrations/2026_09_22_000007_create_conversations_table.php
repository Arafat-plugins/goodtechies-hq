<?php

use App\Support\ConversationType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Conversations (master prompt Part D, communication tables).
     *
     * ## One store for the discussion, not two
     *
     * The spec lists both a `task_comments` table and a `task`-type conversation. Building both
     * would mean two stores for one thing and a migration later to fold one into the other, so
     * the recorded decision is: **every task gets a `conversations` row of type `task` when it
     * is created, and "comments" are its `messages`. `task_comments` is not created.** Nothing
     * in this phase or any later one should add it.
     *
     * ## All five types now, one type built
     *
     * `type` carries the full `ConversationType` list and the CHECK below is generated from it,
     * including the link rule for each. Phase 6 opens a team channel, a project channel, a DM
     * or an announcement by INSERTING a row — no column, no constraint and no index changes,
     * which is the whole of "shape them for Phase 6 now, but build none of it". Phase 2 creates
     * only `task` rows, and ConversationPolicy denies the other four outright.
     *
     * ## Existing tasks
     *
     * Tasks predate conversations, so the table is backfilled at the end of this migration: one
     * row per task that exists, soft-deleted ones included, because a deleted task's discussion
     * is part of the history its soft delete exists to keep. On `migrate:fresh` there are no
     * tasks yet and the backfill is a no-op; on the machine that has been running since slice 1
     * it is the difference between a discussion panel and a 404. It is idempotent by way of
     * `conversations_one_per_task`, so re-running it can only ever insert what is missing.
     */
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();

            // Checked against ConversationType below rather than declared as a Postgres enum
            // type: a native enum needs ALTER TYPE to grow, which is exactly the migration this
            // table is shaped to avoid.
            $table->string('type', 20);

            // The link columns, one per linkable type. Both nullable, and the CHECK decides
            // which of them a given type must fill — see conversations_link_matches_type.
            //
            // cascadeOnDelete is the truth about the conversation: a discussion about a task
            // that no longer exists is not a discussion. Tasks are SOFT-deleted, so this never
            // fires for the ordinary "delete a task" path and the messages survive with it.
            $table->foreignId('linked_project_id')->nullable()->constrained('projects')->cascadeOnDelete();
            $table->foreignId('linked_task_id')->nullable()->constrained('tasks')->cascadeOnDelete();

            // What a channel is called. Null for a task conversation — its name is the task's
            // title, and copying it here would be a second copy to keep in step with renames.
            $table->string('title')->nullable();

            $table->timestamps();

            // Phase 6's "the project's channel" lookup. Cheap now, and it means that phase adds
            // no index either.
            $table->index(['type', 'linked_project_id']);
        });

        $types = implode(', ', array_map(
            fn (string $value): string => "'".$value."'",
            ConversationType::values(),
        ));

        DB::statement("ALTER TABLE conversations ADD CONSTRAINT conversations_type_is_known CHECK (type IN ({$types}))");

        // A conversation is linked through exactly the column its type names, and through no
        // other. Without this, a `task` row could carry a project id that nothing would ever
        // read and every later query would have to defend against.
        DB::statement(<<<'SQL'
            ALTER TABLE conversations ADD CONSTRAINT conversations_link_matches_type CHECK (
                CASE type
                    WHEN 'task' THEN linked_task_id IS NOT NULL AND linked_project_id IS NULL
                    WHEN 'project' THEN linked_project_id IS NOT NULL AND linked_task_id IS NULL
                    ELSE linked_task_id IS NULL AND linked_project_id IS NULL
                END
            )
        SQL);

        // One discussion per task, forever. This is what makes ConversationService::forTask()
        // safe to call from anywhere — the seeder, a controller, a backfill — without any of
        // them having to know whether somebody else already did.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX conversations_one_per_task
            ON conversations (linked_task_id)
            WHERE type = 'task'
        SQL);

        // The backfill. Tasks that predate this table get theirs now, in one statement, with
        // the unique index above making a re-run a no-op.
        DB::statement(<<<'SQL'
            INSERT INTO conversations (type, linked_task_id, created_at, updated_at)
            SELECT 'task', tasks.id, now(), now()
            FROM tasks
            WHERE NOT EXISTS (
                SELECT 1 FROM conversations
                WHERE conversations.type = 'task' AND conversations.linked_task_id = tasks.id
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
