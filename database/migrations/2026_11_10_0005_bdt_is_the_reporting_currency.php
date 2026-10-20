<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * BDT is the reporting currency. The finance reports show every figure in taka, converting USD
 * at the stored USD→BDT rate (`usd_bdt_rate`, refreshed from the live market rate in `auto`
 * mode or set by the Admin in `manual` mode).
 *
 * The settings currency becomes BDT: payroll, salaries and payslips are paid in taka, so this
 * relabels them — it converts nothing. Bonuses ride on payroll and are relabelled with it.
 * Income, expenses, repeating expenses, project finance and project earning entries keep their
 * own currency, because that is a fact about each record.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return 'pgsql_migrator';
    }

    public function up(): void
    {
        $db = DB::connection($this->getConnection());

        $old = strtoupper(trim((string) $db->table('settings')->where('key', 'currency')->value(DB::raw("value #>> '{}'"))));

        // Stored the way `Setting`'s json cast stores a string: a jsonb string.
        $db->table('settings')->upsert(
            [['key' => 'currency', 'value' => json_encode('BDT'), 'created_at' => now(), 'updated_at' => now()]],
            ['key'],
            ['value', 'updated_at'],
        );

        if ($old !== '' && $old !== 'BDT') {
            $db->update('UPDATE employee_bonuses SET currency = ? WHERE currency = ?', ['BDT', $old]);
        }

        foreach (['usd_bdt_rate' => '122.00', 'usd_bdt_rate_mode' => 'auto'] as $key => $value) {
            $db->table('settings')->insertOrIgnore([
                'key' => $key,
                'value' => json_encode($value),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Deliberately a no-op: the previous settings currency is not stored anywhere, so the
        // relabel cannot be undone safely, and nothing was converted that would need reversing.
    }
};
