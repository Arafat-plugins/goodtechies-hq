<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The kinds of leave (master prompt Part D §20: `leave_types (name, has_balance)`). Phase 5.
     *
     * Seeded Annual, Sick, Emergency and Personal **with** balances; Unpaid and Other
     * **without** (Part D §9). The seeder is `LeaveTypeSeeder`; nothing in the application
     * creates one, because the list is the agency's policy rather than its data.
     *
     * ## Two booleans, because there are two questions
     *
     * `has_balance` answers *is this type capped* — may a request be refused for want of days,
     * and does an Admin set a number per employee for it. `is_unpaid` answers *does a day of
     * this cost pay* — which is what `leave_requests.unpaid_days` records and what Phase 9's
     * payroll reads.
     *
     * They are not the same question and collapsing them would have been wrong on the seed as
     * it stands: **Other has no balance and is still paid.** A single `is_capped` flag read as
     * "uncapped means unpaid" would quietly dock somebody's pay for a day of Other leave, and
     * nothing would have said so out loud until a payslip did. So the fact is stored where it
     * is decided — on the type — rather than inferred from the absence of a cap.
     *
     * `name` is unique because it is what a person picks in a dropdown and what an audit row
     * prints. There is no slug: with six rows seeded once and no editor in the MVP, a second
     * identifier would be a second thing to keep in step.
     */
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();

            $table->string('name')->unique();

            // Capped: an employee has a per-type balance and a request is refused when it asks
            // for more than is left. Annual, Sick, Emergency, Personal.
            $table->boolean('has_balance')->default(true);

            // Unpaid: every day of this type lands in `leave_requests.unpaid_days`, which is
            // the column Phase 9 reads. Only Unpaid on the seed.
            $table->boolean('is_unpaid')->default(false);

            // The order the six are offered in. Not the id: the seeder is idempotent and a
            // re-seed must be able to insert a missing type without it sorting to the bottom
            // of every picker in the application.
            $table->unsignedSmallInteger('position')->default(0);

            $table->timestamps();

            $table->index('position');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_types');
    }
};
