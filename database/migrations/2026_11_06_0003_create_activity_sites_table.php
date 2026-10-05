<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Seconds per site within one minute of a time entry (Phase 11, `docs/extension-api.md` §5).
     *
     * Only a host is stored, never a URL or a page title. `host` is non-empty for `site` and empty
     * for every other kind, which the host CHECK enforces.
     */
    public function up(): void
    {
        Schema::create('activity_sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('time_entry_id')->constrained('time_entries')->cascadeOnDelete();
            $table->timestampTz('minute_at');
            $table->string('kind', 20);
            $table->string('host', 253)->default('');
            $table->unsignedSmallInteger('seconds');
            $table->timestamps();

            $table->unique(['time_entry_id', 'minute_at', 'kind', 'host']);
            $table->index('minute_at');
            $table->index(['time_entry_id', 'host']);
        });

        DB::statement(<<<'SQL'
            alter table activity_sites
            add constraint activity_sites_kind_check
            check (kind in ('site','other_app','browser_internal','private'))
        SQL);

        DB::statement(<<<'SQL'
            alter table activity_sites
            add constraint activity_sites_seconds_check
            check (seconds between 0 and 60)
        SQL);

        DB::statement(<<<'SQL'
            alter table activity_sites
            add constraint activity_sites_host_check
            check ((kind = 'site' and host <> '') or (kind <> 'site' and host = ''))
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_sites');
    }
};
