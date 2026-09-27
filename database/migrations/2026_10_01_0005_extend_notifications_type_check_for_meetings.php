<?php

use App\Support\NotificationType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Let `notifications.type` hold the four meeting types (Phase 7).
     *
     * **Decision 3-6, fourth time.** `2026_09_23_000004` did this for the due-tomorrow
     * reminder, `2026_09_26_000204` for leave and `2026_09_27_000103` for messages. This is the
     * same file with a different phase in its header, and the next phase that adds a type
     * writes it again.
     *
     * The trap it exists for never shows up in this slice's tests: a database built by
     * `migrate:fresh` today writes `notifications_type_is_known` from
     * `NotificationType::values()` as it stands *now*, so it already knows all twenty-two types
     * and every meeting test passes without this file. The client's database does not — it was
     * created at Phase 2 and knows ten — and the first meeting anybody schedules on it fails
     * with a CHECK violation that reads like a broken feature.
     *
     * This is also the fifth migration in a slice whose brief named four. It is a fifth file
     * rather than two DDL concerns crammed into `0004`, because it is about a different table
     * for a different reason and a `down()` that had to undo both would be the one that got it
     * wrong.
     *
     * The constraint is rewritten FROM the enum, which is still the truth. Re-running it on an
     * already-current database is a no-op with the same text, and it picks up any type another
     * phase added in the meantime.
     */
    public function up(): void
    {
        $this->applyCheck(NotificationType::values());
    }

    /**
     * Back to the eighteen types Phases 2–6 knew. Written out rather than derived, because the
     * point of a `down()` is to restore what was there.
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
            'leave.requested',
            'leave.approved',
            'leave.rejected',
            'leave.correction_requested',
            'message.received',
            'message.mentioned',
            'announcement.posted',
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
