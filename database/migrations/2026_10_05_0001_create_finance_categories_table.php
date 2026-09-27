<?php

use App\Support\FinanceCategoryKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One table for both sides of the ledger (master prompt Part D §20:
     * `finance_categories (kind[income|expense], name)`). Phase 8.
     *
     * ## One table, not two, and not an enum
     *
     * Part D §13 gives two lists — income is Maintenance / SEO / Website / Other, expense is
     * Payroll / Office / Hosting / Software / Marketing / Utilities / Operations / Project Cost
     * / Other — and Phase 8's screen list says *"Categories (seeded, **Admin-editable**)"*.
     * Editable is what makes this a table: an enum the Admin can add a row to is a deploy.
     *
     * They share one table because a category IS the same thing on both sides — a name money is
     * filed under — and because both lists carry an *Other*. Two tables would mean two models,
     * two policies and two halves of every rollup query that are the same query; the `kind`
     * column is the one word that tells them apart, and the next section is what makes that
     * word load-bearing rather than decorative.
     *
     * ## `UNIQUE (id, kind)` is the point of this migration
     *
     * `id` is already unique, so on its own this index says nothing. What it does is make
     * `(id, kind)` a **referencable key**, which is what lets `income` and `expenses` each carry
     * a constant `category_kind` column and point a two-column foreign key at it. The effect is
     * that *"a Payroll expense category must not be selectable as income"* is enforced by
     * PostgreSQL rather than by a service that a seeder, an import or a future controller can
     * go around. See `2026_10_05_0002_create_income_table.php` for the other half.
     *
     * It also, for free, freezes a category's kind once anything is filed under it: with
     * `ON UPDATE RESTRICT` on the referencing side, an `UPDATE finance_categories SET kind = …`
     * on a category in use is refused. Reclassifying a category in use would silently move
     * money from one side of the ledger to the other, which is the single worst thing an edit
     * screen could be allowed to do to this table.
     *
     * ## The other constraints
     *
     *   - **`kind` is one of the two** `FinanceCategoryKind` cases, generated from the enum so
     *     the two cannot drift (decision 3-6's shape). **Adding a case to that enum needs a
     *     migration that widens this CHECK** — a database built at Phase 2 knows only what
     *     existed then. This has already bitten four times; see decisions 3-6 and 7-12.
     *   - **`UNIQUE (kind, name)`**, not `UNIQUE (name)`. *Other* exists on both sides and they
     *     are different categories; *Payroll* as an income category is a name nobody has taken.
     *     Within one side, two categories with the same name would split a rollup line in two
     *     and neither of them would be wrong.
     *
     * ## `position`
     *
     * The order every picker offers them in. It is a column and not the id for the reason
     * `leave_types.position` is: the seeder is idempotent, so a category added later to a
     * database that already has thirteen would otherwise sort to the bottom of every list in
     * the application for ever.
     */
    public function up(): void
    {
        Schema::create('finance_categories', function (Blueprint $table) {
            $table->id();

            $table->string('kind');
            $table->string('name');

            $table->unsignedInteger('position')->default(0);

            $table->timestamps();

            // Two categories with the same name on the same side would split a rollup line.
            $table->unique(['kind', 'name']);
            // Every picker and every rollup reads one side, in order.
            $table->index(['kind', 'position']);
        });

        $kinds = implode(', ', array_map(
            fn (string $value): string => "'".$value."'",
            FinanceCategoryKind::values(),
        ));

        DB::statement("ALTER TABLE finance_categories ADD CONSTRAINT finance_categories_kind_is_known CHECK (kind IN ({$kinds}))");

        // Not redundant with the primary key: this is what `income` and `expenses` point their
        // two-column foreign keys at. Without it PostgreSQL refuses to create them.
        DB::statement('ALTER TABLE finance_categories ADD CONSTRAINT finance_categories_id_and_kind UNIQUE (id, kind)');
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_categories');
    }
};
