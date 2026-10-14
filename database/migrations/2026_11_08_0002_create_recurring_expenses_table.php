<?php

use App\Support\FinanceCategoryKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Repeating expenses: a cost set up once (a VPS every month, a domain every year) that the
 * daily `hq:generate-recurring-expenses` command records as an ordinary `expenses` row on each
 * due date — the way Xero's repeating bills and QuickBooks' recurring transactions work.
 *
 * The template carries the same category rule as `expenses` (the composite foreign key pinned
 * to `'expense'`, so a template cannot be filed under an income category) and the same
 * positive-amount CHECK. `next_due_date` is always `start_date + occurrences × step`, computed
 * from the start rather than from the previous due date, so a 31st never drifts to the 28th.
 *
 * `expenses.recurring_expense_id` names the template a row came from, and the unique index on
 * `(recurring_expense_id, date)` is what makes a second run on the same day a no-op rather than
 * a double charge. Hand-entered rows have a NULL there, and Postgres treats NULLs as distinct,
 * so they are unaffected. Deleting a template keeps the rows it recorded (`ON DELETE SET NULL`):
 * money that was spent stays spent.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return 'pgsql_migrator';
    }

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        $db = DB::connection($this->getConnection());

        $schema->create('recurring_expenses', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('category_id');

            // A constant, exactly as on `expenses`. See 2026_10_05_0002_create_income_table.php.
            $table->string('category_kind')->default(FinanceCategoryKind::Expense->value);

            $table->foreignId('project_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('billable')->default(false);

            $table->decimal('amount', 12, 2);
            $table->string('frequency', 20);
            $table->date('start_date');
            $table->date('ends_on')->nullable();
            $table->unsignedInteger('occurrences')->default(0);
            $table->date('next_due_date');
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();

            $table->timestamps();

            $table->index('project_id');
            $table->index(['active', 'next_due_date']);
        });

        $db->statement(
            "ALTER TABLE recurring_expenses ADD CONSTRAINT recurring_expenses_category_is_an_expense_category CHECK (category_kind = '"
            .FinanceCategoryKind::Expense->value
            ."')",
        );

        $db->statement(
            'ALTER TABLE recurring_expenses ADD CONSTRAINT recurring_expenses_category_id_foreign '
            .'FOREIGN KEY (category_id, category_kind) REFERENCES finance_categories (id, kind) '
            .'ON DELETE RESTRICT ON UPDATE RESTRICT',
        );

        $db->statement('ALTER TABLE recurring_expenses ADD CONSTRAINT recurring_expenses_amount_is_positive CHECK (amount > 0)');

        $db->statement(
            "ALTER TABLE recurring_expenses ADD CONSTRAINT recurring_expenses_frequency_is_known CHECK (frequency IN ('monthly','quarterly','yearly'))",
        );

        $db->statement(
            'ALTER TABLE recurring_expenses ADD CONSTRAINT recurring_expenses_ends_after_start CHECK (ends_on IS NULL OR ends_on >= start_date)',
        );

        $schema->table('expenses', function (Blueprint $table) {
            $table->foreignId('recurring_expense_id')->nullable()->constrained('recurring_expenses')->nullOnDelete();
            $table->unique(['recurring_expense_id', 'date']);
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());

        $schema->table('expenses', function (Blueprint $table) {
            $table->dropUnique(['recurring_expense_id', 'date']);
            $table->dropConstrainedForeignId('recurring_expense_id');
        });

        $schema->dropIfExists('recurring_expenses');
    }
};
