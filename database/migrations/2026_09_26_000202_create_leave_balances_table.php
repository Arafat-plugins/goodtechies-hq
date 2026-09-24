<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How many days of each capped type an employee has left (master prompt Part D §20:
     * `leave_balances (employee_id, leave_type_id, balance_days)`). Phase 5.
     *
     * ## There is no accrual, and that is the feature
     *
     * Part D §9 says it twice: *"seeded per employee by Admin; no accrual logic in MVP — Admin
     * adjusts balances, audit-logged"*. So this table has no `accrued`, no `carried_over`, no
     * `year` and no opening balance. One row per employee per capped type holding one number,
     * moved by exactly two things: an approval decrementing it, and an Admin setting it with a
     * reason. Both go through `LeaveService`, and the Admin's edit writes
     * `leave.balance_adjusted` with the old and the new value.
     *
     * A yearly reset, a monthly accrual or a carry-over rule would each be a policy decision the
     * client has not made. When they make it, it is a column and a job, not a rewrite: the one
     * number is still the one number.
     *
     * ## Whole days
     *
     * `balance_days` is an integer and so are `leave_requests.days` and `.unpaid_days`. The
     * plan's leave is applied for in whole days — a range of dates — and Part D §8's *Half Day*
     * is an attendance status an Admin marks, not a half-day leave request. A decimal column
     * would have been a half-day feature nobody asked for, sitting there half-built, and every
     * total in the feature would have had to decide how to round it.
     *
     * ## unique(employee_id, leave_type_id), and why it is the identity
     *
     * Two rows for one person and one type is two answers to "how many days has Yaseen got
     * left", and an approval decrements whichever one it read. The upsert in `LeaveService`
     * keys on this pair for the same reason the attendance edit keys on (employee, date): it is
     * the row's own identity, so seeding a balance and correcting one are the same act and
     * there is only one place for the audit row to be written.
     */
    public function up(): void
    {
        Schema::create('leave_balances', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained()->cascadeOnDelete();

            $table->integer('balance_days')->default(0);

            $table->timestamps();

            $table->unique(['employee_id', 'leave_type_id']);
        });

        // A balance is a count of days remaining. Below zero it is not a balance, it is a debt
        // this application has no rule for — and the decrement path refuses to go there with a
        // sentence long before this fires. This is what stops it arriving by any other door.
        DB::statement('ALTER TABLE leave_balances ADD CONSTRAINT leave_balances_not_negative CHECK (balance_days >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_balances');
    }
};
