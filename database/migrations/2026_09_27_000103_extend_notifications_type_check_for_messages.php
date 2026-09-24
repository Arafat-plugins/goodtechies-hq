<?php

use App\Support\NotificationType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Let `notifications.type` hold the three messaging types (Phase 6).
     *
     * **Decision 3-6, third time.** The Phase 2 migration wrote `notifications_type_is_known`
     * from `NotificationType::values()` at the moment it ran, so a database created then knows
     * ten types, a database created after Phase 3 knows eleven, after Phase 5 fifteen — and a
     * database created today from `migrate:fresh` already knows all eighteen, which is exactly
     * why this is the trap it is: every test in the phase passes on a fresh database and the
     * client's own database refuses the first @mention with a CHECK violation that reads like a
     * broken feature.
     *
     * `2026_09_23_000004` did this for the due-tomorrow reminder and `2026_09_26_000204` did it
     * for leave. This is the same file with a different phase in its header, and the next phase
     * that adds a type writes it again.
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
     * Back to the fifteen types Phases 2–5 knew. Written out rather than derived, because the
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
