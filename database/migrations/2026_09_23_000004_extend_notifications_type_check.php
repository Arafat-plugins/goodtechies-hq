<?php

use App\Support\NotificationType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Let `notifications.type` hold `task.due_tomorrow` (Phase 3).
     *
     * The Phase 2 migration writes `notifications_type_is_known` from `NotificationType::values()`
     * at the moment it runs, so a database created today already knows about every case. A
     * database created in Phase 2 does not, and its CHECK would refuse the first due-tomorrow
     * reminder at 08:00 on the morning after deploy — a failure that would look like a broken
     * scheduler rather than a stale constraint.
     *
     * So the constraint is rewritten from the enum, which is still the truth. This is the shape
     * every future phase that adds a notification type should copy: the enum grows, the check
     * follows, and nothing hard-codes the list twice.
     *
     * Re-running the migration on an already-current database is a no-op with the same text.
     */
    public function up(): void
    {
        $this->applyCheck(NotificationType::values());
    }

    /**
     * Back to the eight types Phase 2 knew. Written out rather than derived, because the point
     * of a down() is to restore what was there, and deriving it from the enum would just produce
     * today's list again.
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
