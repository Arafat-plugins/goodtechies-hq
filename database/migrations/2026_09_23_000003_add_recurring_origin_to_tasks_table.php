<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A task's recurring origin, and the constraint the whole engine rests on (Phase 3).
     *
     * Phase 2 created the two columns nullable and unconstrained so that this migration would
     * never have to ALTER a populated `tasks` table. It does three things to them:
     *
     *   1. **Renames `recurring_template_id` to `recurring_task_id`.** The master prompt's §20
     *      calls the table `recurring_tasks` and the column `recurring_task_id`; Phase 2 guessed
     *      at a name before the table existed. Renaming it now, while every value is still NULL,
     *      costs nothing; leaving it would leave a column whose name does not match the table it
     *      points at for the life of the product.
     *   2. **Adds the foreign key.**
     *   3. **Adds the unique index that makes a duplicate impossible.**
     *
     * ## The index IS the duplicate prevention
     *
     * §6 is explicit: "a unique index on (`recurring_task_id`, `recurring_period`) — the
     * duplicate check is a DB constraint, not only code". Two schedulers on two boxes, a queue
     * job being retried and an Admin pressing "Generate now" can all pass an `if (already
     * exists)` in the same millisecond. Only the database can refuse the second INSERT, and
     * because `TaskService::create()` writes both columns in the same INSERT as the rest of the
     * task, that refusal happens on the row itself — there is no window between creating the
     * task and stamping it with its period.
     *
     * The engine's own existing-instance check is still there and still runs first. It is the
     * NICE error: it turns the ordinary case, a second run later the same day, into a readable
     * log row instead of an exception. The index is what makes the claim true.
     *
     * ## Why the index is partial, and why it counts soft-deleted rows
     *
     * `WHERE recurring_task_id IS NOT NULL` keeps every hand-made task — the overwhelming
     * majority — out of the index. (Postgres would allow them anyway, since NULLs are distinct
     * by default; the predicate says so out loud and keeps the index small.)
     *
     * Soft-deleted tasks are deliberately NOT excluded. A deleted instance still occupies its
     * period, so tomorrow's 00:05 run does not quietly resurrect a task somebody deleted on
     * purpose. "October's task is simply there on the 1st, once" has to survive somebody
     * deciding that October's task was wrong.
     */
    public function up(): void
    {
        // Alone in its own Schema::table call: Laravel refuses a rename combined with other
        // changes to the same table.
        Schema::table('tasks', function (Blueprint $table) {
            $table->renameColumn('recurring_template_id', 'recurring_task_id');
        });

        Schema::table('tasks', function (Blueprint $table) {
            // nullOnDelete, not cascade: deleting a template must not delete two years of
            // completed maintenance tasks. The instances survive, carrying their period string,
            // and RecurrenceRule::labelForPeriod() can still read it without the template.
            $table->foreign('recurring_task_id')
                ->references('id')
                ->on('recurring_tasks')
                ->nullOnDelete();
        });

        // Raw, because the builder has no partial-unique-index API.
        DB::statement(
            'create unique index tasks_recurring_task_period_unique
             on tasks (recurring_task_id, recurring_period)
             where recurring_task_id is not null'
        );
    }

    public function down(): void
    {
        DB::statement('drop index if exists tasks_recurring_task_period_unique');

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropForeign(['recurring_task_id']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->renameColumn('recurring_task_id', 'recurring_template_id');
        });
    }
};
