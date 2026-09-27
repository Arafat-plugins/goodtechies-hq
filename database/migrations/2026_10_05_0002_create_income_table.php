<?php

use App\Support\FinanceCategoryKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Money in (master prompt Part D §20: `income (project_id nullable, category_id, amount,
     * date, notes, recorded_by)`). Phase 8.
     *
     * The table is called `income` and not `incomes` because Part D names it, and because the
     * plural of income is income. `App\Models\Income` sets `$table` rather than letting Eloquent
     * guess.
     *
     * ## `decimal(12, 2)`, and never a float
     *
     * Binary floating point cannot represent 0.10, 0.20 or 0.70 exactly. A float column would
     * store *nearly* $860.00, and a `SUM()` over twelve months of nearly-right numbers drifts —
     * so the September rollup Part D §13 gives as its acceptance example would come back as
     * 2909.9999999999995, be rendered as $2,910.00 by a formatter, and disagree with the bank
     * by a cent somewhere in the year with nothing to point at. `decimal(12, 2)` is exact
     * base-10 arithmetic in PostgreSQL, and it is what `project_finance` already uses for the
     * same money on the same screens.
     *
     * Twelve digits is up to 9,999,999,999.99, which is four orders of magnitude more than this
     * agency will invoice and exactly what `project_finance.price` allows. Matching it is the
     * point: a figure copied from a project's price into an income row must not be able to
     * round on the way.
     *
     * ## What is a CONSTRAINT here rather than an `if` in PHP
     *
     *   - **`amount > 0`.** The SIGN is the table, not the number. A negative income is an
     *     expense wearing the wrong hat: it would subtract from a rollup line that every screen
     *     renders as a total of money received, and no refund is ever entered that way because
     *     the application has no refund. Zero is excluded with it — a zero income is not a
     *     record of anything, and it is what a half-filled form submits.
     *   - **The category is an INCOME category**, enforced in SQL and not only in
     *     `FinanceService`. `category_kind` is a constant column: a CHECK pins it to `'income'`
     *     and a two-column foreign key `(category_id, category_kind)` points at
     *     `finance_categories (id, kind)`. So filing income under the Payroll *expense*
     *     category is a foreign-key violation, from any writer — a service, a seeder, a console
     *     command, a later CSV import, or somebody at `psql`.
     *
     *     The alternative considered was a service rule alone. It was rejected because this is
     *     the constraint that decides which line of the rollup money lands on: `FinanceService`
     *     is the only writer *today*, and Part D §13 already promises a financial report and
     *     Phase 12 promises a migration import — two future writers, each with its own chance
     *     to forget. A redundant-looking column is a cheap price for a rule that cannot be
     *     gone around, and it is the standard relational way to express "the FK must point at a
     *     row of a particular kind".
     *
     *     `ON UPDATE RESTRICT` is the second half of that: it means a category in use cannot
     *     have its `kind` changed either, so an Admin editing the category list cannot
     *     reclassify eight months of income with one dropdown.
     *
     *   - **`ON DELETE RESTRICT` on the category.** A finance record must never lose what it was
     *     for. Deleting a category that money is filed under would leave rows whose rollup line
     *     no longer exists; `FinanceService::deleteCategory()` checks first so the Admin reads a
     *     sentence instead of a Postgres error, but this is the promise.
     *
     * ## The project link is OPTIONAL, and restricted rather than nulled
     *
     * Part D §13: *"optional project link"*. Most income is invoiced against a project and some
     * is not, so the column is nullable and the finance-by-project report is a report about the
     * rows that have one.
     *
     * Where it IS set, it is `restrictOnDelete`. Nulling it on a project delete would quietly
     * rewrite the by-project report for a month that has already been reported on, which is the
     * same failure as losing the category. Nothing in this application deletes a project —
     * Part D §21 makes a cancelled project a status and an ended one an archive — and this is
     * what keeps it that way rather than trusting that it stays true.
     *
     * **This column is deliberately reachable for a project the recorder cannot see through any
     * ordinary route.** That is not an oversight: it is the whole reason Part D §13 asks for a
     * dedicated finance-only endpoint. See `App\Http\Controllers\Accountant\ProjectController`.
     *
     * ## `recorded_by` is not nullable
     *
     * Every finance record has somebody who entered it, and `restrictOnDelete` keeps it that
     * way — the same reasoning as `meetings.organizer_id`. Nothing here deletes a user (leaving
     * the company is `status = inactive`, Part D §21).
     */
    public function up(): void
    {
        Schema::create('income', function (Blueprint $table) {
            $table->id();

            // Not `foreignId()->constrained()`: the foreign key is the two-column one added
            // below, and a second single-column FK beside it would allow the row to satisfy
            // `category_id` alone while the pair is what the rule is about.
            $table->unsignedBigInteger('category_id');

            // A constant. Never written by the application — the DEFAULT sets it on INSERT, the
            // CHECK pins it, and the composite foreign key is what it exists for. It is not
            // fillable on the model.
            $table->string('category_kind')->default(FinanceCategoryKind::Income->value);

            // Part D §13's "optional project link".
            $table->foreignId('project_id')->nullable()->constrained()->restrictOnDelete();

            $table->decimal('amount', 12, 2);
            $table->date('date');
            $table->text('notes')->nullable();

            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();

            $table->timestamps();

            // The monthly rollup is a range scan over `date` grouped by category; the finance
            // dashboard and the by-project report are the other two.
            $table->index(['date', 'category_id']);
            $table->index('category_id');
            $table->index('project_id');
        });

        DB::statement(
            "ALTER TABLE income ADD CONSTRAINT income_category_is_an_income_category CHECK (category_kind = '"
            .FinanceCategoryKind::Income->value
            ."')",
        );

        DB::statement(
            'ALTER TABLE income ADD CONSTRAINT income_category_id_foreign '
            .'FOREIGN KEY (category_id, category_kind) REFERENCES finance_categories (id, kind) '
            .'ON DELETE RESTRICT ON UPDATE RESTRICT',
        );

        DB::statement('ALTER TABLE income ADD CONSTRAINT income_amount_is_positive CHECK (amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('income');
    }
};
