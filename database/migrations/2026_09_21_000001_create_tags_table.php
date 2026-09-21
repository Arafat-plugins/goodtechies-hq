<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Colour-coded labels for tasks (master prompt Part D, Phase 2).
     *
     * A tag is either global (project_id null) or scoped to exactly one project. Admins and
     * Managers create them; employees may only assign ones that already exist, which is a
     * policy rule, not a schema one.
     */
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            // Null project_id = a global tag, usable on any project.
            $table->foreignId('project_id')->nullable()->constrained('projects')->cascadeOnDelete();
            $table->string('name');
            // A StatusKey-style token name, never a hex value: colour comes from app.css.
            $table->string('colour');
            $table->timestamps();

            // The tag picker lists a project's own tags plus the global ones, so this is the
            // one index it reads, and it enforces "one tag of a given name per scope" too.
            // Postgres treats NULLs as distinct, so global tags are guarded in the seeder and
            // the (not-yet-built) create path rather than here.
            $table->unique(['project_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tags');
    }
};
