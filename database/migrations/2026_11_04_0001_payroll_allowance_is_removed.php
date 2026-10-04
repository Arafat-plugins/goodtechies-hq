<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Polish 002: the client removed allowance from payroll, and chose to ZERO the amounts that
 * already existed rather than fold them into base salary.
 *
 * - `employee_salaries.allowance` → 0 on every row, so no future draft copies one.
 * - `payroll_items.allowance` → 0 on every line of a month that is still OPEN (draft,
 *   calculated, reviewed, approved). `net_salary` is a generated column, so it drops by the
 *   allowance on its own. Locked and paid months are closed and keep their figures exactly.
 *
 * The columns stay: dropping them would rewrite `net_salary`'s generation expression and the
 * closed months' payslips still read the old amounts. `down()` cannot restore the zeroed figures
 * (they are not kept anywhere else) — the pre-deploy `pg_dump` is the way back.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return 'pgsql_migrator';
    }

    public function up(): void
    {
        DB::statement('UPDATE employee_salaries SET allowance = 0 WHERE allowance <> 0');

        DB::statement(<<<'SQL'
            UPDATE payroll_items
            SET allowance = 0
            WHERE allowance <> 0
              AND payroll_period_id IN (
                  SELECT id FROM payroll_periods WHERE status NOT IN ('locked', 'paid')
              )
        SQL);
    }

    public function down(): void
    {
        // Irreversible by design — see the class note.
    }
};
