<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Two facts slice 1 left the service to discover it needed (master prompt Part D, Phase 2).
     *
     * 1. WHO wrote the work summary. "Completion requires the PRIMARY assignee's work summary"
     *    is unenforceable while the summary is an anonymous text column — a second assignee
     *    could type one and the rule would pass. The author is therefore stored with it.
     *
     * 2. The FIRST completion. Reopening is Completed → In Progress, and the task genuinely is
     *    not complete afterwards, so `completed_at` / `completed_by` have to be cleared or the
     *    row contradicts its own status. The original is copied once into these columns, which
     *    are written only when they are still null and never again.
     *
     *    They live on the row rather than only in activity_logs because activity_logs is a
     *    free-text narrative (`description` and nothing else): the original completion would
     *    survive there only as a sentence, unqueryable and unrenderable as a field. A log is
     *    what happened; the row is what is true.
     */
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('work_summary_by')->nullable()->after('work_summary')
                ->constrained('users')->nullOnDelete();
            $table->timestampTz('work_summary_at')->nullable()->after('work_summary_by');

            $table->text('first_work_summary')->nullable()->after('completed_at');
            $table->foreignId('first_completed_by')->nullable()->after('first_work_summary')
                ->constrained('users')->nullOnDelete();
            $table->timestampTz('first_completed_at')->nullable()->after('first_completed_by');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignKey('work_summary_by');
            $table->dropConstrainedForeignKey('first_completed_by');
            $table->dropColumn(['work_summary_at', 'first_work_summary', 'first_completed_at']);
        });
    }
};
