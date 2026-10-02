<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Billing type is One-Time or Recurring (the client's request). A Recurring project carries a
 * `recurrence_frequency` (daily / weekly / biweekly / monthly); its deadline is computed from the
 * start date by ProjectService.
 *
 * - Former `monthly_recurring` and `custom_recurring` projects become `recurring` + `monthly`.
 * - `projects_billing_type_is_known` pins the two values.
 * - `projects_recurrence_matches_billing`: a frequency exactly when the project is Recurring,
 *   and only a known one.
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

        Schema::connection($this->getConnection())->table('projects', function (Blueprint $table) {
            $table->string('recurrence_frequency', 20)->nullable();
        });

        $db->statement(
            "UPDATE projects SET billing_type = 'recurring', recurrence_frequency = 'monthly' "
            ."WHERE billing_type IN ('monthly_recurring', 'custom_recurring')"
        );
        $db->statement("UPDATE projects SET recurrence_frequency = NULL WHERE billing_type <> 'recurring'");

        $db->statement('ALTER TABLE projects DROP CONSTRAINT IF EXISTS projects_billing_type_is_known');
        $db->statement(
            "ALTER TABLE projects ADD CONSTRAINT projects_billing_type_is_known CHECK (billing_type IN ('one_time', 'recurring'))"
        );

        $db->statement('ALTER TABLE projects DROP CONSTRAINT IF EXISTS projects_recurrence_matches_billing');
        $db->statement(<<<'SQL'
            ALTER TABLE projects ADD CONSTRAINT projects_recurrence_matches_billing CHECK (
                ((billing_type = 'recurring') = (recurrence_frequency IS NOT NULL))
                AND (recurrence_frequency IS NULL OR recurrence_frequency IN ('daily', 'weekly', 'biweekly', 'monthly'))
            )
            SQL);
    }

    public function down(): void
    {
        $db = DB::connection($this->getConnection());

        $db->statement('ALTER TABLE projects DROP CONSTRAINT IF EXISTS projects_recurrence_matches_billing');
        $db->statement('ALTER TABLE projects DROP CONSTRAINT IF EXISTS projects_billing_type_is_known');

        $db->statement("UPDATE projects SET billing_type = 'monthly_recurring' WHERE billing_type = 'recurring'");

        Schema::connection($this->getConnection())->table('projects', function (Blueprint $table) {
            $table->dropColumn('recurrence_frequency');
        });
    }
};
