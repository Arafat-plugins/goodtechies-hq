<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every income row, expense row and repeating-expense template carries its own currency
 * (`App\Support\Currency`: BDT or USD). Amounts in different currencies are never added
 * together and there is no exchange rate, so totals are reported per currency.
 *
 * Existing rows are backfilled with the currency in the settings (the one every amount was
 * shown in until now), falling back to BDT when that value is missing or not a 3-letter code.
 * The same value becomes the column default. Nothing is deleted.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const TABLES = ['income', 'expenses', 'recurring_expenses'];

    /** @var list<string> */
    private const DATED_TABLES = ['income', 'expenses'];

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

        foreach (self::TABLES as $name) {
            $schema->table($name, function (Blueprint $table) {
                $table->string('currency', 3)->nullable();
            });

            $db->update("UPDATE {$name} SET currency = ? WHERE currency IS NULL", [$currency]);

            $db->statement("ALTER TABLE {$name} ALTER COLUMN currency SET NOT NULL");
            $db->statement("ALTER TABLE {$name} ALTER COLUMN currency SET DEFAULT '{$currency}'");
            $db->statement("ALTER TABLE {$name} ADD CONSTRAINT {$name}_currency_is_a_code CHECK (currency ~ '^[A-Z]{3}$')");
        }

        foreach (self::DATED_TABLES as $name) {
            $schema->table($name, function (Blueprint $table) {
                $table->index(['currency', 'date']);
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        $db = DB::connection($this->getConnection());

        foreach (self::DATED_TABLES as $name) {
            $schema->table($name, function (Blueprint $table) {
                $table->dropIndex(['currency', 'date']);
            });
        }

        foreach (self::TABLES as $name) {
            $db->statement("ALTER TABLE {$name} DROP CONSTRAINT IF EXISTS {$name}_currency_is_a_code");

            $schema->table($name, function (Blueprint $table) {
                $table->dropColumn('currency');
            });
        }
    }
};
