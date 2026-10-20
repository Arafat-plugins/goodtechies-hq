<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Finance is entered by hand (client spec: "Do not automatically generate income from projects,
 * project completion, invoices, expenses, or other events").
 *
 *   - Every project's `auto_income` switch is turned off and the column now defaults to false.
 *     Nothing reads it any more; the column, `auto_income_from` and `project_income_runs` stay
 *     so existing financial data is preserved. No columns or rows are dropped.
 *   - `down()` only restores the old default: it does not switch rows back on.
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

        $db->statement('UPDATE project_finance SET auto_income = false');
        $db->statement('ALTER TABLE project_finance ALTER COLUMN auto_income SET DEFAULT false');
    }

    public function down(): void
    {
        $db = DB::connection($this->getConnection());

        $db->statement('ALTER TABLE project_finance ALTER COLUMN auto_income SET DEFAULT true');
    }
};
