<?php

use App\Support\FinanceCategoryKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Money out (master prompt Part D §20: `expenses (category_id, amount, date, notes,
     * recorded_by)`). Phase 8.
     *
     * The mirror of `income`, with the same `decimal(12, 2)`, the same positive-amount CHECK and
     * the same composite foreign key — pinned to `'expense'` instead — so that filing an expense
     * under the SEO *income* category is a foreign-key violation from any writer. The reasoning
     * for every one of those is written out once in `2026_10_05_0002_create_income_table.php`
     * and is not repeated here.
     *
     * ## There is no `project_id`, and that is Part D's decision rather than an omission
     *
     * Part D §20 gives income a nullable `project_id` and gives expenses none:
     *
     * > `income (project_id nullable, category_id, amount, date, notes, recorded_by)`
     * > `expenses (category_id, amount, date, notes, recorded_by)`
     *
     * The expense category list contains **Project Cost**, and it is tempting to read that as
     * implying a project link. It is not built, because inventing the column would be inventing
     * the by-project cost report that Part D never asks for, and because a nullable column that
     * only one of nine categories ever fills is a column that is empty on every row anybody
     * looks at. Part D §13's own finance-by-project report is about *income* linked to
     * invoicing.
     *
     * If the client wants project profitability — cost as well as revenue per project — that is
     * a column, a picker, a report and a decision about whether payroll is apportioned to
     * projects. It is written up as an open question in this slice's report rather than guessed
     * at here.
     */
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('category_id');

            // A constant, exactly as on `income`. See that migration.
            $table->string('category_kind')->default(FinanceCategoryKind::Expense->value);

            $table->decimal('amount', 12, 2);
            $table->date('date');
            $table->text('notes')->nullable();

            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();

            $table->timestamps();

            $table->index(['date', 'category_id']);
            $table->index('category_id');
        });

        DB::statement(
            "ALTER TABLE expenses ADD CONSTRAINT expenses_category_is_an_expense_category CHECK (category_kind = '"
            .FinanceCategoryKind::Expense->value
            ."')",
        );

        DB::statement(
            'ALTER TABLE expenses ADD CONSTRAINT expenses_category_id_foreign '
            .'FOREIGN KEY (category_id, category_kind) REFERENCES finance_categories (id, kind) '
            .'ON DELETE RESTRICT ON UPDATE RESTRICT',
        );

        DB::statement('ALTER TABLE expenses ADD CONSTRAINT expenses_amount_is_positive CHECK (amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
