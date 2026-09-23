<?php

use App\Support\AttendanceStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Office attendance (master prompt Part D §20: `employee_id, date, clock_in, clock_out,
     * status, note, edited_by`). Phase 4.
     *
     * ## One row per person per day, and the database says so
     *
     * `unique(employee_id, date)` is the whole integrity model of this table. A second row for
     * the same day is not a bug with a sensible reading — it is two answers to "was Yaseen in
     * on Tuesday", and Phase 9 pays somebody from that answer. Three separate writers reach
     * this table (a clock-in, an Admin's edit, the 23:55 absent sweep) and at least one of them
     * runs unattended, so "check then insert" is not a promise anybody can keep across a
     * retried job and a person pressing a button. The index is (decision 3-1: the database
     * enforces what an `if` cannot). `AttendanceService` still checks first, so the common case
     * gets a sentence rather than a constraint violation.
     *
     * ## What is NOT here
     *
     * There is no row for an Off Day and no row for Tapu. Off Day comes from
     * `schedules.working_days` and Remote comes from `employees.tracking_mode`, both derived at
     * read time (see `AttendanceStatus`). A stored Off Day would outlive the schedule it was
     * copied from; a stored Remote would be a second answer to a question the employee record
     * already answers. The CHECK below is what stops either from being written by accident.
     *
     * ## `clock_in` / `clock_out` are timestamps, not times
     *
     * A `time` column cannot say which day it belongs to, and a clock-out is not always on the
     * same day as its clock-in — somebody who stays past midnight has worked, not gone home
     * yesterday. Storing the instant lets `clock_out - clock_in` be the minutes worked without
     * a special case, and `date` stays the day the SHIFT belongs to, which is the day the
     * clock-in happened. Laravel writes these in the app timezone (`Asia/Dhaka`), the same
     * wall clock `schedules.start_time` is written in, so the Late comparison is two values in
     * one frame of reference and never a conversion.
     *
     * ## `note` is one column doing one job
     *
     * Part D §20 gives this table a single free-text column and the plan requires that an Admin
     * edit carry a reason. So `note` IS that reason: `UpdateAttendanceRecordRequest` requires it
     * on every edit, and the same text goes into the `attendance.edited` audit row's new value.
     * A clock-in writes none — the row is its own explanation. Making it nullable rather than
     * required is therefore deliberate: the constraint that matters is on the edit path, where
     * the Form Request and the policy are, not on rows nobody edited.
     */
    public function up(): void
    {
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('date');

            $table->timestamp('clock_in')->nullable();
            $table->timestamp('clock_out')->nullable();

            $table->string('status');
            $table->text('note')->nullable();

            // Who last edited the row by hand. Null on a row written by a clock-in or by the
            // absent sweep, which is how a reader tells an edited day from a recorded one
            // without going to the audit log for every cell. `nullOnDelete` because the row is
            // somebody's attendance and must outlive the account of whoever corrected it.
            $table->foreignId('edited_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // The identity of a row. Also the index the month grid reads by.
            $table->unique(['employee_id', 'date']);

            // The roster: every employee's row for one date. The unique index above is
            // (employee_id, date) and cannot serve a date-only lookup, so the morning's one
            // query gets its own.
            $table->index('date');
        });

        // A derived status must never reach a column. See AttendanceStatus::storable().
        $list = implode(', ', array_map(
            fn (string $value): string => "'".$value."'",
            AttendanceStatus::storableValues(),
        ));

        DB::statement("ALTER TABLE attendance_records ADD CONSTRAINT attendance_records_status_is_storable CHECK (status IN ({$list}))");

        // A shift that ends before it starts is not a shift. The clock-out path refuses it with
        // a sentence; this is what stops it arriving by any other door.
        DB::statement('ALTER TABLE attendance_records ADD CONSTRAINT attendance_records_clock_out_after_in CHECK (clock_out IS NULL OR clock_in IS NULL OR clock_out >= clock_in)');
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
    }
};
