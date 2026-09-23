<?php

use App\Support\TagColour;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The two rules `tags` was created with as comments rather than as constraints
     * (master prompt Part D, Phase 2 slice 4 — tag management).
     *
     * Slice 1 created the table for a feature that could only READ it: tasks carried tags and
     * filtered by them, and the only writer was the seeder. Two of its rules were therefore
     * written down and left unenforced, both of them with a note in the original migration
     * saying so. This slice adds the endpoints that let a human write to the table, which is
     * the moment a documented rule has to become a constraint.
     *
     * ## 1. A colour is a status token name
     *
     * `colour` is a `varchar` that has always held one of the eight `StatusKey` names —
     * `App\Support\TagColour` is that list, and DESIGN.md §5.1 is why it is a name and not a
     * hex. Until now nothing stopped `'#ff0000'`, `'red'` or `''` going in; a tag carrying one
     * renders a chip with no token behind it, which in light mode is invisible text on an
     * invisible pill.
     *
     * The CHECK is written from TagColour::values() rather than typed out, so the constraint
     * and the enum cannot describe different sets — adding a ninth tone is a new case and a new
     * migration, in that order, and the test in tests/Unit/TagColourTest.php fails loudly if
     * somebody does only the first.
     *
     * ## 2. A global tag's name is unique too
     *
     * The original `unique(['project_id', 'name'])` does nothing for global tags, because
     * PostgreSQL treats two NULLs as distinct: `(NULL, 'SEO')` and `(NULL, 'SEO')` are
     * different rows to that index. The original migration says exactly this and points at "the
     * seeder and the (not-yet-built) create path" to hold the line instead. That create path is
     * what this slice builds, and a rule held up by the caller is a rule the second caller
     * breaks — so the partial unique index below holds it in the one place every caller passes
     * through.
     *
     * Both statements touch no data: every row that exists was written by TaskSeeder, whose
     * four labels are global, distinct and already carry token colours.
     */
    public function up(): void
    {
        $colours = implode(', ', array_map(
            fn (string $value): string => "'".$value."'",
            TagColour::values(),
        ));

        DB::statement("ALTER TABLE tags ADD CONSTRAINT tags_colour_is_a_status_token CHECK (colour IN ({$colours}))");

        // The half `unique(['project_id', 'name'])` cannot express. Partial, so it costs
        // nothing on the scoped rows the composite index already covers.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX tags_global_name_unique
            ON tags (name)
            WHERE project_id IS NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS tags_global_name_unique');
        DB::statement('ALTER TABLE tags DROP CONSTRAINT IF EXISTS tags_colour_is_a_status_token');
    }
};
