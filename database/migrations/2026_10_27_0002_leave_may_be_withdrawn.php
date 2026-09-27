<?php

use App\Support\LeaveStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The fifth leave status — decision 5-19.
 *
 * `leave_requests.status` is a string with a CHECK behind it, generated from `LeaveStatus::values()`
 * when the table was created. Adding a case to the enum without this migration gives a build where
 * every screen offers *Withdraw* and the INSERT is refused by the database — which is exactly the
 * trap decision 3-6 records for the `notifications` type CHECK, stepped in again one table over.
 * So the CHECK is dropped and rewritten from the enum, the same way it was written.
 *
 * ## What is deliberately NOT touched
 *
 * `leave_requests_no_overlap`, the GiST EXCLUDE constraint, is `WHERE status IN ('pending',
 * 'approved')` — `LeaveStatus::holdingValues()`, and `withdrawn` is not in it. That is the whole
 * point: a withdrawn request must stop holding its week, or somebody who withdrew a request could
 * not re-book the days they withdrew it to free. Nothing here changes, and the test that books the
 * same window after a withdrawal is what proves it.
 *
 * `leave_requests_decision_is_whole` — `(status = 'pending') = (decided_at IS NULL)` — is also
 * untouched, and a withdrawal satisfies it by stamping `decided_at`. That column has meant "this
 * row is no longer waiting in the queue" since the correction status was built on it (see
 * `LeaveService::requestCorrection()`), and a withdrawal is the cleanest case of that there is.
 * `approver_id` stays whatever it was — null on a pending request, the asker on one sent back —
 * because nobody approved anything and the applicant is not an approver.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rewriteStatusCheck(LeaveStatus::values());
    }

    public function down(): void
    {
        // The four Part D §9 names, spelled out rather than read from the enum: a `down()` that
        // asked today's enum what yesterday's statuses were would be a no-op the day this is
        // reverted, and any withdrawn row would then violate the constraint it restored.
        DB::table('leave_requests')->where('status', 'withdrawn')->delete();

        $this->rewriteStatusCheck(['pending', 'approved', 'rejected', 'correction_requested']);
    }

    /**
     * @param  list<string>  $statuses
     */
    private function rewriteStatusCheck(array $statuses): void
    {
        $values = implode(', ', array_map(fn (string $value): string => "'".$value."'", $statuses));

        DB::statement('ALTER TABLE leave_requests DROP CONSTRAINT leave_requests_status_is_known');
        DB::statement("ALTER TABLE leave_requests ADD CONSTRAINT leave_requests_status_is_known CHECK (status IN ({$values}))");
    }
};
