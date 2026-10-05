<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One minute of activity on a time entry (Phase 11, `docs/extension-api.md` §5).
     *
     * Unique on `(time_entry_id, minute_at)`, so a replayed sample is never stored twice.
     */
    public function up(): void
    {
        Schema::create('activity_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('time_entry_id')->constrained('time_entries')->cascadeOnDelete();
            $table->timestampTz('minute_at');
            $table->string('state', 10);
            $table->string('source', 10);
            $table->string('call_source', 10)->nullable();
            $table->timestamps();

            $table->unique(['time_entry_id', 'minute_at']);
            $table->index('minute_at');
        });

        DB::statement(<<<'SQL'
            alter table activity_samples
            add constraint activity_samples_state_check
            check (state in ('active','media','call','idle'))
        SQL);

        DB::statement(<<<'SQL'
            alter table activity_samples
            add constraint activity_samples_source_check
            check (source in ('extension','web'))
        SQL);

        DB::statement(<<<'SQL'
            alter table activity_samples
            add constraint activity_samples_call_source_check
            check (call_source is null or call_source in ('detected','manual'))
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_samples');
    }
};
