<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A task's checklist — the spec's "checklist / subtasks" (master prompt Part D, Phase 2).
     *
     * A checklist item is NOT a task: it has no assignee, no status machine, no dates and no
     * timer. It is a line somebody ticks. Making it a task row with a parent_id would have
     * bought a second kind of task that every task query then has to remember to exclude, and
     * the List view's "Subtasks 3/5" column would count things that appear in the board.
     */
    public function up(): void
    {
        Schema::create('task_checklists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->string('title');
            $table->boolean('is_done')->default(false);

            // Who ticked it and when. Nulled rather than deleted with the user, like every
            // other actor column in this schema: the tick survives the leaver.
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('completed_at')->nullable();

            // Sparse multiples of 1000, the same convention tasks.position uses, so an item
            // dragged between two others takes the midpoint instead of renumbering the list.
            $table->integer('position')->default(0);
            $table->timestamps();

            // The only way this table is ever read: one task's items, in order.
            $table->index(['task_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_checklists');
    }
};
