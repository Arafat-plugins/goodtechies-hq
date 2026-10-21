<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A bonus can be paid separately, right now, outside payroll (a wedding gift paid today).
 *
 * `payout` says how a bonus is paid: `with_payroll` (with the salary of its payment month, as
 * before) or `separate`. A separate bonus is never linked to a payroll period, so it never
 * reaches `payroll_items.bonus` and is never counted twice. `paid_at` records when a bonus was
 * paid; existing paid bonuses are backfilled with their last update time.
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

        $schema->table('employee_bonuses', function (Blueprint $table) {
            $table->string('payout', 20)->default('with_payroll');
            $table->timestamp('paid_at')->nullable();
        });

        $db->statement(
            "ALTER TABLE employee_bonuses ADD CONSTRAINT employee_bonuses_payout_is_known CHECK (payout IN ('with_payroll','separate'))",
        );

        $db->update("UPDATE employee_bonuses SET paid_at = updated_at WHERE status = 'paid'");

        $db->statement(
            "ALTER TABLE employee_bonuses ADD CONSTRAINT employee_bonuses_separate_never_on_payroll CHECK (payout = 'with_payroll' OR payroll_period_id IS NULL)",
        );
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        $db = DB::connection($this->getConnection());

        $db->statement('ALTER TABLE employee_bonuses DROP CONSTRAINT IF EXISTS employee_bonuses_separate_never_on_payroll');
        $db->statement('ALTER TABLE employee_bonuses DROP CONSTRAINT IF EXISTS employee_bonuses_payout_is_known');

        $schema->table('employee_bonuses', function (Blueprint $table) {
            $table->dropColumn(['payout', 'paid_at']);
        });
    }
};
