<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A project file may belong to the project's INTERNAL NOTES rather than its Files tab.
 *
 * - `files.internal`: true for an attachment to the internal notes. Such a file is visible only
 *   to whoever may see the internal notes (`ProjectPolicy::viewCommercial`) — FilePolicy::view
 *   adds that check — and is never listed by FileService::for(), which feeds the Files tab.
 * - `files_internal_only_on_projects`: only a project owns internal notes, so only a project
 *   file may be internal.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return 'pgsql_migrator';
    }

    public function up(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->boolean('internal')->default(false);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE files ADD CONSTRAINT files_internal_only_on_projects CHECK (
                internal = false OR project_id IS NOT NULL
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE files DROP CONSTRAINT IF EXISTS files_internal_only_on_projects');

        Schema::table('files', function (Blueprint $table) {
            $table->dropColumn('internal');
        });
    }
};
