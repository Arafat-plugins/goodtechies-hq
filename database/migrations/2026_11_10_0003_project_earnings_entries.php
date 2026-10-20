<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Project financials, recorded by hand.
 *
 *   1. `project_finance.currency`: a project's price and rates are kept in one currency
 *      (`App\Support\Currency`). Existing rows are backfilled with the currency in the settings,
 *      falling back to BDT when that value is missing or not a 3-letter code.
 *   2. `project_earning_entries`: any number of entries per project, each one a contract value,
 *      an earned amount or an amount actually received, in its own currency. These rows are a
 *      record of the project only: nothing here creates an income row, and totals are always
 *      reported per kind and per currency, never added across either.
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

        $setting = strtoupper(trim((string) $db->table('settings')->where('key', 'currency')->value(DB::raw("value #>> '{}'"))));
        $currency = preg_match('/^[A-Z]{3}$/', $setting) === 1 ? $setting : 'BDT';

        $schema->table('project_finance', function (Blueprint $table) {
            $table->string('currency', 3)->nullable();
        });

        $db->update('UPDATE project_finance SET currency = ? WHERE currency IS NULL', [$currency]);

        $db->statement('ALTER TABLE project_finance ALTER COLUMN currency SET NOT NULL');
        $db->statement("ALTER TABLE project_finance ALTER COLUMN currency SET DEFAULT '{$currency}'");
        $db->statement("ALTER TABLE project_finance ADD CONSTRAINT project_finance_currency_is_a_code CHECK (currency ~ '^[A-Z]{3}$')");

        $schema->create('project_earning_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->string('kind', 20);
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3);
            $table->date('date');
            $table->text('description')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['project_id', 'date']);
        });

        $db->statement(
            "ALTER TABLE project_earning_entries ADD CONSTRAINT project_earning_entries_kind_is_known CHECK (kind IN ('contract_value','earned','received'))",
        );
        $db->statement('ALTER TABLE project_earning_entries ADD CONSTRAINT project_earning_entries_amount_is_positive CHECK (amount > 0)');
        $db->statement("ALTER TABLE project_earning_entries ADD CONSTRAINT project_earning_entries_currency_is_a_code CHECK (currency ~ '^[A-Z]{3}$')");
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        $db = DB::connection($this->getConnection());

        $schema->dropIfExists('project_earning_entries');

        $db->statement('ALTER TABLE project_finance DROP CONSTRAINT IF EXISTS project_finance_currency_is_a_code');

        $schema->table('project_finance', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
};
