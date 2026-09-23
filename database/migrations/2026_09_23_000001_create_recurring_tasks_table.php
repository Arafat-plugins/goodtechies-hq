<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Recurring task templates (master prompt Part D §6 and §20, Phase 3).
     *
     * One row is "this project generates this task every period". It is a TEMPLATE and holds no
     * state about what it has produced — that is `recurring_generation_log`, one row per attempt,
     * and `tasks.recurring_task_id` on the instances themselves. Two places to look for "did
     * October happen" is one place too many, so this table does not carry a `last_generated_at`.
     *
     * `next_run_at` is the one apparent exception and it is not one: it is a PREVIEW, recomputed
     * from the rule whenever the engine touches the template, and the engine never reads it to
     * decide anything. What gets generated is decided from the period the run date falls in
     * (RecurringTaskEngine::due()), so a `next_run_at` that has gone stale costs a screen an
     * accurate hint and never costs anybody a month's work.
     */
    public function up(): void
    {
        Schema::create('recurring_tasks', function (Blueprint $table) {
            $table->id();

            // A template belongs to exactly one project, and dies with it. Generation stops long
            // before that, though — a cancelled or archived project is refused by the engine and
            // the refusal is written to the log (spec §21).
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();

            // The generated task's title, with `{period}`, `{project}` and `{date}` placeholders
            // — see RecurringTaskEngine::renderTitle(). A template with no placeholder produces
            // the same title every month, which is legal and usually a mistake.
            $table->string('title_template');

            // An ordered list of checklist item titles, replayed onto every instance through
            // TaskService::addChecklistItem(). JSON rather than a child table because it is the
            // template's own text, edited as one field, and never joined to.
            $table->jsonb('checklist_template')->nullable();

            // The rule, split in two on purpose: the case in a column a list can filter and a
            // Form Request can validate against, the parameters in JSON because they differ per
            // case. RecurrenceRule is the only thing that reads either.
            $table->string('frequency');
            $table->jsonb('recurrence_rule');

            $table->timestampTz('next_run_at')->nullable();

            // Who the generated task lands on. Nullable: a template whose assignee has left the
            // company keeps generating, into nobody's plate, and the task shows up unassigned on
            // the Admin board rather than not at all.
            $table->foreignId('default_assignee_id')->nullable()->constrained('employees')->nullOnDelete();

            // Who set the template up. This is the first candidate for the ACTOR the engine
            // creates instances as — the generated task's `created_by`, and therefore the
            // "original assigner" who hears about its completion. See RecurringTaskEngine::actorFor().
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->boolean('active')->default(true);

            $table->timestamps();

            // The project detail page's Recurring tab.
            $table->index(['project_id', 'active']);

            // The next-run preview, sorted.
            $table->index('next_run_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_tasks');
    }
};
