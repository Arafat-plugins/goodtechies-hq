<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tasks (master prompt Part D, Phase 2 slice 1).
     *
     * Three things this table deliberately does NOT hold:
     *   - overdue. It is `due_date < today AND status NOT IN (completed, cancelled)`, computed
     *     at query time by TaskService. A stored flag would be wrong every midnight.
     *   - the assignees. They live in task_assignees, because a task has one or two of them
     *     and exactly one is primary.
     *   - the checklist, links, dependencies, attachments and discussion. Later slices, each
     *     with its own table; none of them is a column here.
     */
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();

            $table->string('status')->default('backlog');
            $table->string('priority')->default('medium');

            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable();

            // Who created the task IS the "original assigner" the spec talks about; there is no
            // second column for it. Kept on delete of the user so history survives them.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            // Planned effort, in minutes. Actual effort is filled by the Phase 4 timer; the
            // column exists now so the List view's "Time tracked" column has somewhere to read
            // from and Phase 4 does not have to migrate a populated table.
            $table->unsignedInteger('estimated_minutes')->nullable();
            $table->unsignedInteger('tracked_seconds')->default(0);

            // Completion. The work summary is mandatory on submit-for-review and on completion —
            // enforced by the service in slice 2, not by a NOT NULL that would break every other
            // transition.
            $table->text('work_summary')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('completed_at')->nullable();

            // PHASE 3 (recurring tasks) — deliberately created nullable and unconstrained NOW so
            // Phase 3 adds its recurring_templates table and a foreign key, and never has to run
            // an ALTER on a populated tasks table. Nothing in Phase 2 reads or writes them.
            $table->unsignedBigInteger('recurring_template_id')->nullable();
            $table->string('recurring_period')->nullable();

            // Manual order inside one Kanban column, i.e. inside one (project_id, status) pair.
            // Sparse by convention: slice 2's drag endpoint assigns multiples of 1000 so a card
            // dropped between two others gets the midpoint without renumbering the column.
            // Ties break on id, so the order is total even before any drag has happened.
            $table->integer('position')->default(0);

            $table->timestampTz('archived_at')->nullable();
            $table->timestamps();
            // Delete is Admin/Manager only and audit-logged; it must not take the task's history,
            // time entries or discussion with it, so it is a soft delete.
            $table->softDeletes();

            // ── Indexes ──────────────────────────────────────────────────────────────
            // The Kanban board and the project detail page: every card of one column of one
            // project, already in drag order. Its leading column also serves the plain
            // "tasks of this project" filter, so project_id needs no index of its own.
            $table->index(['project_id', 'status', 'position']);

            // The List view's default shape: grouped by status, each group sorted by due date.
            // Group counts read the same index without touching the heap.
            $table->index(['status', 'due_date']);

            // The group-by-priority variant, which is the same query with a different leading
            // column. Priority alone would be a four-value btree and worth nothing; paired with
            // the sort key it is a real access path.
            $table->index(['priority', 'due_date']);

            // The overdue bucket crosses every status, so it cannot use either index above:
            // `due_date < today` needs due_date leading. Status is then filtered, not sought.
            $table->index('due_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
