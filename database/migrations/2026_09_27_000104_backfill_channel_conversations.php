<?php

use App\Support\ConversationType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The channels that are not created by anybody: one per existing project, one team, one
     * announcements (Phase 6 — *"a `project` conversation is created when a project is created —
     * backfill for existing projects"*).
     *
     * ## The project channels
     *
     * From today `ProjectService::create()` writes the conversation inside the transaction that
     * writes the project, so a project born from now on has one. This is the other half: every
     * project that already exists, in one statement, the way the Phase 2 migration backfilled
     * the task discussions.
     *
     * **A project created while this runs.** Three things cover the window, and they overlap on
     * purpose because this is a migration that runs once against a live database:
     *
     *   1. the SELECT sees one snapshot, so a project committed after it began is simply not in
     *      it — and that project was created by the new code, which made its own conversation.
     *   2. a project created by the OLD code in the same window is in neither set. It is caught
     *      by `ConversationService::forProject()`, which is a `firstOrCreate`: the first person
     *      to open that project's Discussion tab creates it. The same belt Phase 2 put behind
     *      its task backfill, for the same reason.
     *   3. if both halves happen to reach the same project at once, `ON CONFLICT DO NOTHING`
     *      and the `conversations_one_per_project` unique index mean the loser writes nothing
     *      rather than aborting a migration mid-deploy.
     *
     * Archived projects get one too. A channel is where the conversation about a piece of work
     * lives, and an archived project's history is exactly the thing worth keeping readable —
     * `ProjectPolicy::view` still says yes to an archived project, so its channel is readable
     * and (like an archived task's discussion) still postable, which is how somebody says "this
     * was archived by mistake".
     *
     * ## The two singletons
     *
     * `INSERT … WHERE NOT EXISTS` plus the partial unique indexes from the previous migration:
     * re-running this can only ever insert what is missing. Their titles are written here
     * because these two channels are named by nothing else — a project channel takes its name
     * from its project and a DM from the other person, so neither stores one.
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            INSERT INTO conversations (type, linked_project_id, created_at, updated_at)
            SELECT 'project', projects.id, now(), now()
            FROM projects
            WHERE NOT EXISTS (
                SELECT 1 FROM conversations
                WHERE conversations.type = 'project'
                  AND conversations.linked_project_id = projects.id
            )
            ON CONFLICT DO NOTHING
        SQL);

        $this->singleton(ConversationType::Team, 'Team');
        $this->singleton(ConversationType::Announcement, 'Announcements');
    }

    /**
     * The backfilled rows go; the ones a running application made stay, because they are not
     * this migration's to remove. Nothing is dropped that could hold a message: a channel with
     * messages in it is a channel somebody used.
     */
    public function down(): void
    {
        DB::statement(<<<'SQL'
            DELETE FROM conversations
            WHERE type IN ('project', 'team', 'announcement')
              AND NOT EXISTS (
                  SELECT 1 FROM messages WHERE messages.conversation_id = conversations.id
              )
        SQL);
    }

    private function singleton(ConversationType $type, string $title): void
    {
        DB::statement(
            <<<'SQL'
                INSERT INTO conversations (type, title, created_at, updated_at)
                SELECT ?, ?, now(), now()
                WHERE NOT EXISTS (SELECT 1 FROM conversations WHERE type = ?)
                ON CONFLICT DO NOTHING
            SQL,
            [$type->value, $title, $type->value],
        );
    }
};
