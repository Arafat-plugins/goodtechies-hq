<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "This task depends on that one" (master prompt Part D, Phase 2).
     *
     * One direction only: the row says `task_id` waits for `depends_on_task_id`. The reverse
     * question — "what is waiting on me" — is the same table read through the second index,
     * not a second row, because two rows for one fact is two rows that can disagree.
     *
     * The database refuses a self-dependency; longer cycles are TaskService's business,
     * because the check is a graph walk and no CHECK constraint can express it.
     */
    public function up(): void
    {
        Schema::create('task_dependencies', function (Blueprint $table) {
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('depends_on_task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->nullable();

            // The pair IS the identity, so it is the primary key: a surrogate id would let the
            // same dependency be recorded twice.
            $table->primary(['task_id', 'depends_on_task_id']);

            // "What is waiting on this task", which the primary key cannot serve (wrong
            // leading column) and the detail page asks for on every render.
            $table->index(['depends_on_task_id', 'task_id']);
        });

        DB::statement(
            'ALTER TABLE task_dependencies
             ADD CONSTRAINT task_dependencies_not_self CHECK (task_id <> depends_on_task_id)',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('task_dependencies');
    }
};
