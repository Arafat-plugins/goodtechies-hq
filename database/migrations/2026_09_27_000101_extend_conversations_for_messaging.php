<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Open the Phase 2 conversations table to the other four types (master prompt Part D §10).
     *
     * Phase 2 promised this phase would INSERT a row rather than alter a column, and it keeps
     * that promise for `type`: the CHECK already knows all five values. What it could not know
     * was how a DM says who its two people are, because Phase 2 had not decided.
     *
     * ## The two columns, and why the pair is not two `conversation_members` rows
     *
     * Decision 2-24 says a conversation's membership is computed and `conversation_members` is
     * read state that grants nothing. A DM is the one type with no task and no project to
     * compute from — so if its audience were member rows, that table would grant access for one
     * type and grant nothing for the other four, and "membership is computed" would quietly
     * become "membership is sometimes computed". One table, two meanings, is how a privacy rule
     * rots.
     *
     * So the pair lives HERE, on the conversation, in two columns:
     *
     *   - a DM cannot grow a third person: there is nowhere to put one. As member rows that
     *     would have needed a trigger or a count check nobody would remember to write.
     *   - "the DM between A and B" becomes a unique index on the ordered pair, so opening one is
     *     a `firstOrCreate` that two simultaneous clicks cannot turn into two threads. The same
     *     guarantee over member rows is a self-join no index can enforce.
     *   - `conversation_members` keeps exactly one meaning, for all five types, and the test
     *     that proves a stale row buys nothing now covers a DM as well as a task.
     *
     * `dm_one_id < dm_two_id` is in the CHECK, so the pair is ORDERED and (A,B) and (B,A) are
     * the same row by construction rather than by everybody remembering to sort before writing.
     *
     * ## Four uniqueness rules, all partial indexes
     *
     * One team channel, one announcements channel, one channel per project, one DM per pair.
     * Each is a partial unique index rather than a check in a service, for the reason the Phase
     * 2 `conversations_one_per_task` index exists: `firstOrCreate` is only "the" conversation if
     * the database says so.
     */
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            // The two people in a DM. Ordered by the CHECK below, so `one` is simply the lower
            // user id and neither column means "the starter" — a DM has no owner.
            $table->foreignId('dm_one_id')->nullable()->after('linked_task_id')
                ->constrained('users')->cascadeOnDelete();
            $table->foreignId('dm_two_id')->nullable()->after('dm_one_id')
                ->constrained('users')->cascadeOnDelete();
        });

        // The link matrix, rewritten to cover the two new columns. Same shape as Phase 2's: a
        // conversation is linked through exactly the columns its type names and through no
        // others, so no query downstream has to defend against a stray id.
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

        // One channel per project, forever — the same guarantee `conversations_one_per_task`
        // gives a task, and what makes the backfill below idempotent however many times it runs
        // beside a live application creating projects.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX conversations_one_per_project
            ON conversations (linked_project_id)
            WHERE type = 'project'
        SQL);

        // One DM per ordered pair. Two people clicking "Message" on each other at the same
        // moment get one thread, not two half-threads neither of them can find again.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX conversations_one_per_dm_pair
            ON conversations (dm_one_id, dm_two_id)
            WHERE type = 'dm'
        SQL);

        // "Every DM this person is in" reads both columns, and the unique index above only
        // leads with the first. This is the other half.
        DB::statement(<<<'SQL'
            CREATE INDEX conversations_dm_second_person
            ON conversations (dm_two_id)
            WHERE type = 'dm'
        SQL);

        // One team channel and one announcements channel, company-wide. A unique index on a
        // column whose value is fixed by the predicate is how Postgres spells "at most one row
        // like this" — crude-looking and exactly right, because the alternative is a service
        // that checks first and a second caller that checks at the same instant.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX conversations_one_team
            ON conversations (type)
            WHERE type = 'team'
        SQL);
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX conversations_one_announcement
            ON conversations (type)
            WHERE type = 'announcement'
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS conversations_one_announcement');
        DB::statement('DROP INDEX IF EXISTS conversations_one_team');
        DB::statement('DROP INDEX IF EXISTS conversations_dm_second_person');
        DB::statement('DROP INDEX IF EXISTS conversations_one_per_dm_pair');
        DB::statement('DROP INDEX IF EXISTS conversations_one_per_project');

        DB::statement('ALTER TABLE conversations DROP CONSTRAINT IF EXISTS conversations_link_matches_type');

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dm_two_id');
            $table->dropConstrainedForeignId('dm_one_id');
        });

        // Phase 2's matrix, restored as it was written rather than derived — the point of a
        // down() is to put back what was there.
        DB::statement(<<<'SQL'
            ALTER TABLE conversations ADD CONSTRAINT conversations_link_matches_type CHECK (
                CASE type
                    WHEN 'task' THEN linked_task_id IS NOT NULL AND linked_project_id IS NULL
                    WHEN 'project' THEN linked_project_id IS NOT NULL AND linked_task_id IS NULL
                    ELSE linked_task_id IS NULL AND linked_project_id IS NULL
                END
            )
        SQL);
    }
};
