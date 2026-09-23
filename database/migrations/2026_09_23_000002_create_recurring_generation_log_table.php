<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per generation ATTEMPT (master prompt Part D §20, Phase 3).
     *
     * The engine runs unattended at five past midnight and nobody watches it. This table is the
     * whole of what they can see afterwards, so it is written for every outcome and not only for
     * the interesting ones: a month that produced nothing says here why it produced nothing.
     *
     * It is history, not state. Nothing reads a row back to decide what to do next except
     * `RecurringTaskEngine::due()`, which asks the narrowest possible question — "has this
     * template been attempted for this period at all" — so that a scheduler run after a day of
     * downtime catches up, and a run on an ordinary Tuesday writes nothing.
     */
    public function up(): void
    {
        // Singular, as the master prompt's table list spells it.
        Schema::create('recurring_generation_log', function (Blueprint $table) {
            $table->id();

            $table->foreignId('recurring_task_id')->constrained('recurring_tasks')->cascadeOnDelete();

            // The period key — `2026-10`, `2026-W41`, `2026-10-07`. The same string that goes on
            // `tasks.recurring_period`, produced by the same RecurrenceRule::periodKey().
            $table->string('period');

            // App\Support\GenerationOutcome.
            $table->string('outcome');

            // The instance, when there is one. Null on every skip. nullOnDelete rather than
            // cascade: the log has to keep saying that October was generated even after somebody
            // force-deletes October's task.
            $table->foreignId('task_id')->nullable()->constrained('tasks')->nullOnDelete();

            // §6's "previous period's still-open task is flagged, not silently duplicated". It is
            // a WARNING and never a refusal — this month's task is generated anyway, and this
            // column is how somebody finds out that last month's is still sitting there.
            $table->foreignId('previous_open_task_id')->nullable()->constrained('tasks')->nullOnDelete();

            // The sentence a person reads. Written by the engine at the moment it knows why,
            // because a reason reconstructed from an outcome and a timestamp is a guess.
            $table->text('message')->nullable();

            $table->timestamps();

            // The templates screen's log panel: one template's attempts, newest first.
            $table->index(['recurring_task_id', 'id']);

            // due()'s question: has this template been attempted for this period?
            $table->index(['recurring_task_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_generation_log');
    }
};
