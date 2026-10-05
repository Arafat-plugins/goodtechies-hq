<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What was decided about one idle stretch of a time entry (Phase 11, `docs/extension-api.md` §6).
     *
     * Unique on `(time_entry_id, idle_from)`: a repeated decision returns the stored row and
     * changes nothing.
     */
    public function up(): void
    {
        Schema::create('idle_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('time_entry_id')->constrained('time_entries')->cascadeOnDelete();
            $table->timestampTz('idle_from');
            $table->timestampTz('idle_to');
            $table->string('decision', 10);
            $table->unsignedInteger('discarded_seconds')->default(0);
            $table->string('source', 10)->default('extension');
            $table->timestampTz('decided_at');
            $table->timestamps();

            $table->unique(['time_entry_id', 'idle_from']);
        });

        DB::statement(<<<'SQL'
            alter table idle_decisions
            add constraint idle_decisions_decision_check
            check (decision in ('keep','discard','meeting','stop','auto_pause'))
        SQL);

        DB::statement(<<<'SQL'
            alter table idle_decisions
            add constraint idle_decisions_source_check
            check (source in ('extension','web','server'))
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('idle_decisions');
    }
};
