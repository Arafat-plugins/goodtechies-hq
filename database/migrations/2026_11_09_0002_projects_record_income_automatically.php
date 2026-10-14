<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Income recorded automatically from each project's billing settings (client request: "income
 * will come from clients project"), next to the income an Admin or Accountant still adds by hand.
 *
 *   - `project_finance.auto_income` switches it on or off per project (on by default);
 *     `auto_income_from` is the first day it may bill. Every existing row gets today, so nothing
 *     before this migration is ever back-filled, and switching it back on moves the date to that
 *     day so the switched-off stretch is not back-filled either.
 *   - `income.source` says whether a row was typed in (`manual`) or recorded by the daily
 *     `hq:generate-project-income` command or a project's completion (`auto`). Both live in the
 *     same ledger and are added up the same way.
 *   - `project_income_runs` holds **one row per billed period** — (project, kind, period) is
 *     unique — whatever came of it: recorded, skipped because the month was locked, or skipped
 *     because the amount was zero. The generator looks here before it records anything, so a
 *     second run on the same day is a no-op, and **an auto income somebody deleted is never
 *     recreated**: its run row stays (the income link goes NULL), and the period stays billed.
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

        $schema->table('project_finance', function (Blueprint $table) {
            $table->boolean('auto_income')->default(true);
            $table->date('auto_income_from')->nullable();
        });

        $db->statement('UPDATE project_finance SET auto_income_from = CURRENT_DATE');

        $schema->table('income', function (Blueprint $table) {
            $table->string('source', 20)->default('manual');
        });

        $db->statement("ALTER TABLE income ADD CONSTRAINT income_source_is_known CHECK (source IN ('manual','auto'))");

        $schema->create('project_income_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->date('period');
            $table->foreignId('income_id')->nullable()->constrained('income')->nullOnDelete();
            $table->string('outcome', 20);
            $table->timestamps();

            $table->unique(['project_id', 'kind', 'period']);
            $table->index('income_id');
        });

        $db->statement(
            "ALTER TABLE project_income_runs ADD CONSTRAINT project_income_runs_kind_is_known CHECK (kind IN ('recurring','hourly','one_time'))",
        );

        $db->statement(
            "ALTER TABLE project_income_runs ADD CONSTRAINT project_income_runs_outcome_is_known CHECK (outcome IN ('recorded','skipped_locked','skipped_zero'))",
        );
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        $db = DB::connection($this->getConnection());

        $schema->dropIfExists('project_income_runs');

        $db->statement('ALTER TABLE income DROP CONSTRAINT IF EXISTS income_source_is_known');

        $schema->table('income', function (Blueprint $table) {
            $table->dropColumn('source');
        });

        $schema->table('project_finance', function (Blueprint $table) {
            $table->dropColumn(['auto_income', 'auto_income_from']);
        });
    }
};
