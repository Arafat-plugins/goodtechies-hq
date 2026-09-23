<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rejecting a time entry (master prompt Part D §7, Phase 4 — the approval queue).
     *
     * ## Why three columns and not a status
     *
     * Decision 4-7 is that totals ask exactly one question, `approved_at is not null`, and that
     * no status enum may sit beside it and contradict it. These columns do not: they answer a
     * DIFFERENT question. `approved_at` says *these hours count*. `rejected_at` says *somebody
     * looked and said no*. An entry nobody has looked at yet has neither, and that — not a
     * third enum case — is what "waiting for approval" means.
     *
     * The predicate every total asks is untouched, and a rejected row answers it false because
     * its `approved_at` is still null, exactly as it was while it waited. Nothing downstream had
     * to learn a new word to stop counting it.
     *
     * ## The CHECK is what stops them disagreeing
     *
     * `time_entries_not_approved_and_rejected` forbids both timestamps at once. Without it the
     * two columns really would be able to contradict each other — a row that both counts and was
     * refused — and that is precisely what 4-7 exists to prevent. Approving a previously
     * rejected entry therefore CLEARS the rejection rather than layering on top of it
     * (`TimerService::approve()`), and the database is what makes that mandatory rather than
     * a convention two callers may keep differently. Same reasoning as 4-2 and 3-1: the `if` in
     * the service produces the sentence, the constraint keeps the rule.
     *
     * ## Rejecting is not deleting
     *
     * The row stays. The hours stay on it, visible to the person who recorded them, and
     * `rejection_reason` says why they do not count — because an afternoon that vanishes from
     * somebody's Time page is an afternoon they will report as lost, and Phase 9 pays from this
     * table. The reason is required by the Form Request, mirroring every other write here that
     * moves what somebody is owed.
     *
     * `reason` is NOT reused for it. That column is the employee's own sentence — why they typed
     * the entry in, or why they corrected it — and overwriting it with an Admin's refusal would
     * destroy the one thing the refusal is a judgement about.
     */
    public function up(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            // The fact: somebody looked at this entry and refused it.
            $table->timestampTz('rejected_at')->nullable();
            // Who. Never null while `rejected_at` is set — a refusal is always somebody's, unlike
            // an approval, where a null `approved_by` means the system signed off an auto entry.
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            // Why, in their words. Printed to the employee whose hours these are.
            $table->text('rejection_reason')->nullable();
        });

        DB::statement(<<<'SQL'
            alter table time_entries
            add constraint time_entries_not_approved_and_rejected
            check (approved_at is null or rejected_at is null)
        SQL);

        // The approval queue: stopped, undecided, oldest first. Partial, so it holds only the
        // handful of rows actually waiting rather than every entry the agency has ever made —
        // the same shape as `time_entries_open_heartbeat` above it.
        DB::statement(<<<'SQL'
            create index time_entries_awaiting_decision
            on time_entries (work_date, id)
            where ended_at is not null and approved_at is null and rejected_at is null
        SQL);
    }

    public function down(): void
    {
        DB::statement('drop index if exists time_entries_awaiting_decision');
        DB::statement('alter table time_entries drop constraint if exists time_entries_not_approved_and_rejected');

        Schema::table('time_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rejected_by');
            $table->dropColumn(['rejected_at', 'rejection_reason']);
        });
    }
};
