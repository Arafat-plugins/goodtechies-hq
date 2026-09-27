<?php

use App\Support\PayrollStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One month of payroll and where it has got to (master prompt Part D §20:
     * `payroll_periods (month, status[draft|calculated|reviewed|approved|locked|paid], locked_at,
     * lock_reversed_by, lock_reversal_reason)`). Phase 9.
     *
     * **This is the table Phase 8 left a hole for.** `FinanceService::assertPeriodIsOpen()` has
     * been called from every finance write path since Phase 8 with an empty body, because Part
     * D §13 defines the block against a table that did not exist: *"a finance record is blocked
     * when its `date` falls in the month of a `payroll_periods` row whose status is `LOCKED` or
     * `PAID`"* (decision 8-14). Creating this table and filling that body turns the block on
     * everywhere at once.
     *
     * ## `month` is a DATE pinned to the first of the month, and it is UNIQUE
     *
     * Part D §14 runs payroll per calendar month, so *"one period per September 2026"* is an
     * invariant and not a hope. Three shapes were available:
     *
     *   1. `year int` + `month int`, unique together. Two columns to compare, two to index, and
     *      every query that asks *"which period covers this date"* has to take the date apart
     *      first — including `assertPeriodIsOpen()`, which is called on **every** income and
     *      expense write in the application.
     *   2. A `char(7)` `'2026-09'`. Sorts correctly, compares as text, and silently accepts
     *      `'2026-9'`, `'09-2026'` and `'2026-13'`.
     *   3. **A `date` holding the first of the month**, `UNIQUE`, with a CHECK that the day
     *      part is 1. PostgreSQL validates that it is a real month; `date_trunc('month', …)` or
     *      Carbon's `startOfMonth()` turns any date into the key in one step; and the unique
     *      index is the whole of *"one period per month"*.
     *
     * Three. The CHECK is what stops `'2026-09-15'` becoming a second, invisible September.
     *
     * ## `status`, and the CHECK generated from the enum
     *
     * `payroll_periods_status_is_known` is generated from `PayrollStatus::values()` at the
     * moment this migration runs, exactly as `leave_requests_status_is_known` and
     * `notifications_type_is_known` are. **This table is created here, so its CHECK is born
     * knowing all six statuses on every database — including the client's, which was built at
     * Phase 2** (decisions 3-6, 7-12: the hazard is *widening* an enum that an older CHECK was
     * generated from, and this slice widens none. `audit_logs.event` is a plain indexed string
     * with **no** CHECK — verified in `2026_09_21_…_create_audit_logs_table.php`, which declares
     * `$table->string('event')->index()` and nothing more — so the three audit events this
     * phase writes need no migration at all).
     *
     * A seventh status added later needs a migration that rewrites this constraint. That is
     * written down here because it is the fifth time this repo has met the trap.
     *
     * ## The lock, and the reversal — three columns and two CHECKs
     *
     * Part D §20 names exactly `locked_at`, `lock_reversed_by` and `lock_reversal_reason`, and
     * this table has those three and no more. In particular there is **no `locked_by`, no
     * `approved_by`, no `approved_at` and no `paid_at`**: every one of those is a fact the audit
     * log already holds with its actor, its timestamp and its IP, in the one table `hq_app`
     * cannot UPDATE or DELETE. A second copy on a row the application can edit would be the
     * weaker record of the two and would be able to disagree with it — the reasoning that kept
     * `completed` off `meetings.status` (decision 7-3) and a `reminder_sent_at` off `meetings`
     * (decision 7-5). `locked_at` survives that argument only because Part D names it and
     * because the second CHECK below turns it into a structural statement rather than a note:
     *
     *   - **`locked_at IS NOT NULL` exactly when the status closes the month.** So a row
     *     claiming to be `locked` with no lock time, or an `approved` row still carrying one,
     *     cannot exist — from the service, a seeder, an import or `psql`. This is the same
     *     all-or-nothing shape as `leave_requests_decision_is_whole`, and it matters more,
     *     because `locked_at` is what a payslip and a future audit will quote as the moment the
     *     month closed.
     *   - **A reversal is all-or-nothing:** `lock_reversed_by IS NULL` exactly when
     *     `lock_reversal_reason IS NULL`. Part D §14 and Part C §4 both require a **reason** for
     *     a lock reversal, and a row recording that a lock was reversed by somebody for no
     *     stated cause is the one thing that clause exists to prevent. `PayrollService` refuses
     *     a blank reason with a sentence; this is the promise behind it.
     *
     * Reversing a lock clears `locked_at` (the month is open again) and fills the two reversal
     * columns. Locking it a second time fills `locked_at` again and **leaves the reversal
     * columns alone** — they are the record of the most recent reversal, and the full history of
     * every lock and every reversal is in `audit_logs` under `payroll.lock_reversed`, where it
     * cannot be overwritten.
     *
     * `lock_reversed_by` is **`restrictOnDelete`**, unlike `leave_requests.approver_id` which is
     * nulled. The difference is the pairing CHECK: nulling the reverser on a user delete would
     * leave a reason with nobody attached to it and the constraint would refuse the delete
     * anyway — as a foreign-key error on an unrelated screen rather than as the rule it is. So
     * the rule is stated once, in the direction it actually holds: *a reversal names the person
     * who made it, for ever*. Nothing in this application deletes a user (Part D §21: leaving
     * the company is `status = inactive`), exactly as with `income.recorded_by` and
     * `meetings.organizer_id`, and this is what keeps it that way.
     */
    public function up(): void
    {
        Schema::create('payroll_periods', function (Blueprint $table) {
            $table->id();

            // The first day of the month this period pays. See the class note.
            $table->date('month')->unique();

            $table->string('status')->default(PayrollStatus::Draft->value);

            // When the current lock was placed. Null whenever the month is open — including
            // after a reversal, which is what makes this column readable as "is it closed".
            $table->timestamp('locked_at')->nullable();

            // The most recent reversal, and why. Part D §20's two columns; the full history is
            // in audit_logs.
            $table->foreignId('lock_reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('lock_reversal_reason')->nullable();

            $table->timestamps();

            // The period list reads newest first; `assertPeriodIsOpen()` reads one month by its
            // key, which the unique index on `month` already covers.
            $table->index(['status', 'month']);
        });

        $statuses = implode(', ', array_map(
            fn (string $value): string => "'".$value."'",
            PayrollStatus::values(),
        ));

        DB::statement("ALTER TABLE payroll_periods ADD CONSTRAINT payroll_periods_status_is_known CHECK (status IN ({$statuses}))");

        DB::statement(
            'ALTER TABLE payroll_periods ADD CONSTRAINT payroll_periods_month_is_a_first '
            ."CHECK (date_part('day', month) = 1)",
        );

        $closing = implode(', ', array_map(
            fn (string $value): string => "'".$value."'",
            PayrollStatus::closingValues(),
        ));

        DB::statement(
            'ALTER TABLE payroll_periods ADD CONSTRAINT payroll_periods_lock_is_whole '
            ."CHECK ((status IN ({$closing})) = (locked_at IS NOT NULL))",
        );

        DB::statement(
            'ALTER TABLE payroll_periods ADD CONSTRAINT payroll_periods_reversal_is_whole '
            .'CHECK ((lock_reversed_by IS NULL) = (lock_reversal_reason IS NULL))',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_periods');
    }
};
