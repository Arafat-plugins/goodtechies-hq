<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Subtasks — decision 12-71, which reverses the `parent_id` argument in the header of
     * `2026_09_22_000001_create_task_checklists_table.php` because the client asked for it:
     * *"now there no way to followup sub task"*. A checklist line has no assignee, no date and no
     * status, so nobody can be chased for it; a subtask is a task and can.
     *
     * The checklist stays exactly as it was. This adds a second, different thing.
     *
     * ## The foreign key
     *
     * Tasks are soft-deleted, and the application never hard-deletes one: deleting a parent is
     * `TaskService::delete()`, which soft-deletes its subtasks in the same transaction (the FK
     * cannot see a soft delete, so the cascade that matters is the service's). The FK's own
     * `ON DELETE CASCADE` is for the one path the service does not own — a real `DELETE` from a
     * future purge or a console — and it says the same thing the service does: a subtask does not
     * outlive its parent. `nullOnDelete` would have promoted orphans to top-level tasks, silently
     * adding them to every board.
     *
     * ## One level deep
     *
     * Depth ≤ 1 is not expressible as a CHECK (it is a fact about another row), so it is held by
     * `TaskPolicy::createSubtask()` and `TaskService::createSubtask()`. The CHECK below holds the
     * one part a row can hold alone: a task is never its own parent.
     *
     * ## Grants
     *
     * None needed: this adds a column to `tasks`, which `hq_app` already reads and writes through
     * the default privileges `deploy/sql/roles.sql` sets for tables `hq_migrator` owns.
     */
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->after('project_id')
                ->constrained('tasks')->cascadeOnDelete();

            // The two reads: one parent's subtasks (the drawer and the card's counter), and
            // "top-level only", which every general list asks as `parent_id IS NULL`.
            $table->index('parent_id');
        });

        DB::statement('ALTER TABLE tasks ADD CONSTRAINT tasks_parent_is_not_self CHECK (parent_id IS NULL OR parent_id <> id)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tasks DROP CONSTRAINT IF EXISTS tasks_parent_is_not_self');

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['parent_id']);
            $table->dropConstrainedForeignId('parent_id');
        });
    }
};
