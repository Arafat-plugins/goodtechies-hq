<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which tags a task carries.
     *
     * No timestamps: the row is the fact, and who attached a tag when belongs in activity_logs,
     * not in a pivot the List view reads once per row.
     */
    public function up(): void
    {
        Schema::create('task_tags', function (Blueprint $table) {
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('tags')->cascadeOnDelete();

            // The pivot's identity is the pair, so it is the primary key: no surrogate id, and
            // a tag cannot be attached to the same task twice.
            $table->primary(['task_id', 'tag_id']);

            // The reverse direction — "every task carrying this tag" — is the tag filter on the
            // List view, and the primary key above cannot serve it (wrong leading column).
            $table->index(['tag_id', 'task_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_tags');
    }
};
