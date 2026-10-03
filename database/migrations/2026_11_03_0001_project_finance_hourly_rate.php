<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finance can bill a project at an hourly rate (the client's request: SEO / Ads projects are
 * billed hourly). `billing_frequency` gains the value `hourly` (it has no CHECK, the enum
 * BillingFrequency is the list); the amount lives in `hourly_rate`.
 *
 * - `project_finance_hourly_rate_not_negative`: a rate is never below zero.
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

        Schema::connection($this->getConnection())->table('project_finance', function (Blueprint $table) {
            $table->decimal('hourly_rate', 12, 2)->nullable();
        });

        $db->statement('ALTER TABLE project_finance DROP CONSTRAINT IF EXISTS project_finance_hourly_rate_not_negative');
        $db->statement(
            'ALTER TABLE project_finance ADD CONSTRAINT project_finance_hourly_rate_not_negative CHECK (hourly_rate IS NULL OR hourly_rate >= 0)'
        );
    }

    public function down(): void
    {
        $db = DB::connection($this->getConnection());

        $db->statement('ALTER TABLE project_finance DROP CONSTRAINT IF EXISTS project_finance_hourly_rate_not_negative');

        $db->statement("UPDATE project_finance SET billing_frequency = 'custom' WHERE billing_frequency = 'hourly'");

        Schema::connection($this->getConnection())->table('project_finance', function (Blueprint $table) {
            $table->dropColumn('hourly_rate');
        });
    }
};
