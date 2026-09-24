<?php

use App\Support\LeaveStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Leave requests (master prompt Part D §20: `leave_requests (employee_id, leave_type_id,
     * start_date, end_date, days, unpaid_days, reason, status, approver_id, decided_at,
     * decision_note)`). Phase 5.
     *
     * ## The overlap rule is a CONSTRAINT, not an `if`
     *
     * Part D §9: *"overlapping pending/approved requests are refused"*. `LeaveService` checks
     * first, and the check is only there to produce a readable sentence — the promise is the
     * EXCLUDE constraint below.
     *
     * The reason is the one decisions 3-1 and 4-2 already made twice in this repo: two writers
     * can reach this table in the same millisecond. Somebody double-tapping *Apply* on a phone,
     * a resubmission racing the approval of the request it replaces, and an Admin approving one
     * request while the employee files another over the same week are all real, and none of
     * them is prevented by a SELECT followed by an INSERT — there is a window between them and
     * the two overlapping rows go in on either side of it. A partial UNIQUE index cannot express
     * this, because the thing that must not repeat is a *range*, not a value.
     *
     *     EXCLUDE USING gist (employee_id WITH =, daterange(start_date, end_date, '[]') WITH &&)
     *         WHERE (status IN ('pending', 'approved'))
     *
     * `'[]'` because both ends are inclusive: a request for the 1st to the 2nd covers the 2nd,
     * and a second request starting on the 2nd overlaps it. The `WHERE` clause is
     * `LeaveStatus::holding()` — rejected requests and ones sent back for correction hold no
     * days, which is what makes "send it back and let them re-file the same week" work at all.
     *
     * It needs `btree_gist`, because the `employee_id WITH =` half is a plain equality and GiST
     * does not index integers for equality without it. The extension is **trusted** in
     * PostgreSQL 13 and later, so the database owner (`hq_migrator`, which is what migrations
     * run as) can create it without a superuser.
     *
     * ## The other three CHECKs
     *
     *   - **`status` is one of the four** Part D §9 names, generated from `LeaveStatus` so the
     *     enum and the constraint cannot drift. The model's transition guard is what stops an
     *     illegal *move*; this is what stops an unknown *word*.
     *   - **`end_date >= start_date`.** A range that runs backwards is not a range, and
     *     `daterange()` in the exclusion constraint above would throw on one anyway — with a
     *     message about ranges rather than about leave.
     *   - **A decision is all-or-nothing.** `status = 'pending'` exactly when `decided_at IS
     *     NULL`: a pending request that claims a decision time, or a rejected one that does not,
     *     is a row no screen can render honestly. `approver_id` is nullable beside it on
     *     purpose — the decider's *account* may be deleted later and the decision still
     *     happened, so it is `nullOnDelete` and the timestamp is what says a decision was made.
     *
     * ## `unpaid_days` is stored, not derived
     *
     * Part D §9 has it computed on approval and Phase 9's payroll reads it. It is stored
     * because it is a fact about the request as it was granted: a type could be re-flagged, a
     * schedule could be edited, and a payslip already issued must not silently change. The
     * column is `<= days` by CHECK, because the days you were not paid for cannot outnumber the
     * days you took.
     */
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            // Restricted, not cascaded: deleting a type that somebody has taken leave under
            // would erase the record of leave that was actually granted. Nothing in the
            // application deletes a type; this is what keeps it that way.
            $table->foreignId('leave_type_id')->constrained()->restrictOnDelete();

            $table->date('start_date');
            $table->date('end_date');

            // Working days inside the range, on this employee's own schedule — never a
            // difference between two dates. See LeaveService::leaveDays().
            $table->integer('days');
            // Of those, the ones that cost pay. Phase 9 reads this and nothing else.
            $table->integer('unpaid_days')->default(0);

            $table->text('reason');

            $table->string('status')->default(LeaveStatus::Pending->value);

            // Who ruled, when, and what they said. The note is required on a rejection and on a
            // correction request (the Form Requests enforce it) and optional on an approval,
            // because "yes" needs no explanation and "no" always does.
            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();

            $table->timestamps();

            // The queue reads by status; My Leave and the overlap check read by employee.
            $table->index(['employee_id', 'start_date']);
            $table->index(['status', 'start_date']);
            // The calendar and the absent sweep both ask "which approved leave covers this
            // window", which is a range scan over dates and not over one employee.
            $table->index(['start_date', 'end_date']);
        });

        $statuses = implode(', ', array_map(
            fn (string $value): string => "'".$value."'",
            LeaveStatus::values(),
        ));

        DB::statement("ALTER TABLE leave_requests ADD CONSTRAINT leave_requests_status_is_known CHECK (status IN ({$statuses}))");
        DB::statement('ALTER TABLE leave_requests ADD CONSTRAINT leave_requests_end_after_start CHECK (end_date >= start_date)');
        DB::statement('ALTER TABLE leave_requests ADD CONSTRAINT leave_requests_days_counted CHECK (days >= 1 AND unpaid_days >= 0 AND unpaid_days <= days)');
        DB::statement(
            "ALTER TABLE leave_requests ADD CONSTRAINT leave_requests_decision_is_whole CHECK ((status = '"
            .LeaveStatus::Pending->value
            ."') = (decided_at IS NULL))",
        );

        $holding = implode(', ', array_map(
            fn (string $value): string => "'".$value."'",
            LeaveStatus::holdingValues(),
        ));

        DB::statement(
            'ALTER TABLE leave_requests ADD CONSTRAINT leave_requests_no_overlap '
            ."EXCLUDE USING gist (employee_id WITH =, daterange(start_date, end_date, '[]') WITH &&) "
            ."WHERE (status IN ({$holding}))",
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_requests');

        // The extension is deliberately left in place: another table may come to need it, and
        // dropping an extension that something else has started depending on is a worse failure
        // than leaving an unused one behind.
    }
};
