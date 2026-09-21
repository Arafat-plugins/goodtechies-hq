<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who a task is assigned to (master prompt Part D, Phase 2).
     *
     * A task may carry two assignees: both see it and both may edit it, but completion needs
     * the PRIMARY one's work summary. "Exactly one primary" is therefore a rule the database
     * holds, not a convention the service hopes for — see the partial unique index below.
     */
    public function up(): void
    {
        Schema::create('task_assignees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            // One row per person per task.
            $table->unique(['task_id', 'employee_id']);

            // This is the index that answers "the tasks this employee is assigned to", which is
            // the whole of Employee visibility and the group-by-assignee variant of the List
            // view. Employee-first, because that is the direction both queries read it in.
            $table->index(['employee_id', 'task_id']);
        });

        // At most one primary per task, enforced by the database rather than by the service.
        // A partial unique index is the only way to say this in Postgres: a plain unique on
        // (task_id, is_primary) would also forbid a second NON-primary assignee, which is the
        // ordinary two-person case.
        DB::statement(
            'CREATE UNIQUE INDEX task_assignees_one_primary_per_task
             ON task_assignees (task_id) WHERE is_primary',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('task_assignees');
    }
};
