<?php

use App\Support\NotificationType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Let `notifications.type` hold the four leave types (Phase 5).
     *
     * **Decision 3-6, and it says in as many words that this is a trap that will be stepped in
     * again.** The Phase 2 migration wrote `notifications_type_is_known` from
     * `NotificationType::values()` at the moment it ran, so a database created today already
     * knows every case and a database created in Phase 2 does not. Without this migration the
     * client's own database would refuse the very first leave application — a CHECK violation
     * on the first `Apply`, on a feature every test in this phase had passed — and it would
     * look like a broken feature rather than a stale constraint.
     *
     * The constraint is rewritten from the enum, which is still the truth, exactly as
     * `2026_09_23_000004` did for Phase 3's due-tomorrow reminder. This is the shape every
     * future phase that adds a notification type copies: the enum grows, the check follows, and
     * nothing hard-codes the list twice.
     *
     * Re-running it on an already-current database is a no-op with the same text. It also picks
     * up any type another phase added to the enum in the meantime, which is the point of
     * deriving it rather than listing it.
     */
    public function up(): void
    {
        $this->applyCheck(NotificationType::values());
    }

    /**
     * Back to the ten types Phases 2 and 3 knew. Written out rather than derived, because the
     * point of a `down()` is to restore what was there, and deriving it from the enum would
     * just produce today's list again.
     */
    public function down(): void
    {
        $this->applyCheck([
            'task.assigned',
            'task.reassigned',
            'task.status_changed',
            'task.commented',
            'task.submitted_for_review',
            'task.completed',
            'task.deleted',
            'task.overdue',
            'task.due_tomorrow',
            'project.cancelled',
        ]);
    }

    /**
     * @param  list<string>  $types
     */
    private function applyCheck(array $types): void
    {
        $list = implode(', ', array_map(fn (string $value): string => "'".$value."'", $types));

        DB::statement('ALTER TABLE notifications DROP CONSTRAINT IF EXISTS notifications_type_is_known');
        DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_type_is_known CHECK (type IN ({$list}))");
    }
};
