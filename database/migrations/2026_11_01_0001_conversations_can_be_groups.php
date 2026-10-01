<?php

use App\Support\ConversationType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Conversations may be named groups of chosen people (decision 12-81).
 *
 * - `conversations.avatar_path`: the group's picture on the default disk (null for every other type).
 * - `conversations.created_by`: who created the group (null for every other type, and after that
 *   user is deleted).
 * - `conversation_group_members`: the group's audience. Unlike `conversation_members` (read state,
 *   grants nothing), this table GRANTS: `ConversationPolicy::view` reads it for `group` rows only.
 * - Both CHECKs on `conversations` learn `group`: no linked object, no DM pair.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return 'pgsql_migrator';
    }

    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->string('avatar_path', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index('created_by');
        });

        Schema::create('conversation_group_members', function (Blueprint $table) {
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->primary(['conversation_id', 'user_id']);
            $table->index('user_id');
            $table->index('added_by');
        });

        $types = implode(', ', array_map(
            fn (string $value): string => "'".$value."'",
            ConversationType::values(),
        ));

        DB::statement('ALTER TABLE conversations DROP CONSTRAINT IF EXISTS conversations_type_is_known');
        DB::statement("ALTER TABLE conversations ADD CONSTRAINT conversations_type_is_known CHECK (type IN ({$types}))");

        DB::statement('ALTER TABLE conversations DROP CONSTRAINT IF EXISTS conversations_link_matches_type');
        DB::statement(<<<'SQL'
            ALTER TABLE conversations ADD CONSTRAINT conversations_link_matches_type CHECK (
                CASE type
                    WHEN 'task' THEN
                        linked_task_id IS NOT NULL AND linked_project_id IS NULL
                        AND dm_one_id IS NULL AND dm_two_id IS NULL
                    WHEN 'project' THEN
                        linked_project_id IS NOT NULL AND linked_task_id IS NULL
                        AND dm_one_id IS NULL AND dm_two_id IS NULL
                    WHEN 'dm' THEN
                        linked_task_id IS NULL AND linked_project_id IS NULL
                        AND dm_one_id IS NOT NULL AND dm_two_id IS NOT NULL
                        AND dm_one_id < dm_two_id
                    WHEN 'group' THEN
                        linked_task_id IS NULL AND linked_project_id IS NULL
                        AND dm_one_id IS NULL AND dm_two_id IS NULL
                    ELSE
                        linked_task_id IS NULL AND linked_project_id IS NULL
                        AND dm_one_id IS NULL AND dm_two_id IS NULL
                END
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement("DELETE FROM conversations WHERE type = 'group'");

        DB::statement('ALTER TABLE conversations DROP CONSTRAINT IF EXISTS conversations_link_matches_type');
        DB::statement(<<<'SQL'
            ALTER TABLE conversations ADD CONSTRAINT conversations_link_matches_type CHECK (
                CASE type
                    WHEN 'task' THEN
                        linked_task_id IS NOT NULL AND linked_project_id IS NULL
                        AND dm_one_id IS NULL AND dm_two_id IS NULL
                    WHEN 'project' THEN
                        linked_project_id IS NOT NULL AND linked_task_id IS NULL
                        AND dm_one_id IS NULL AND dm_two_id IS NULL
                    WHEN 'dm' THEN
                        linked_task_id IS NULL AND linked_project_id IS NULL
                        AND dm_one_id IS NOT NULL AND dm_two_id IS NOT NULL
                        AND dm_one_id < dm_two_id
                    ELSE
                        linked_task_id IS NULL AND linked_project_id IS NULL
                        AND dm_one_id IS NULL AND dm_two_id IS NULL
                END
            )
        SQL);

        // The five values the type CHECK held before this migration, written out rather than
        // derived, because the enum now carries `group`.
        DB::statement('ALTER TABLE conversations DROP CONSTRAINT IF EXISTS conversations_type_is_known');
        DB::statement("ALTER TABLE conversations ADD CONSTRAINT conversations_type_is_known CHECK (type IN ('team', 'project', 'task', 'dm', 'announcement'))");

        Schema::dropIfExists('conversation_group_members');

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['created_by']);
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn('avatar_path');
        });
    }
};
