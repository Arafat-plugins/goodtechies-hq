<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One employee's line in one month of payroll (master prompt Part D §20:
     * `payroll_items (payroll_period_id, employee_id, base_salary, allowance, bonus, deduction,
     * advance, leave_impact, net_salary, admin_notes)`). Phase 9.
     *
     * ## Every money column is a POSITIVE MAGNITUDE; the column name carries the sign
     *
     * `bonus` adds, `deduction`, `advance` and `leave_impact` subtract, and each is stored `>= 0`
     * by CHECK. This is Phase 8's *"the sign is the table, not the number"* applied one level
     * down: a negative `deduction` is a bonus wearing the wrong hat, it would be rendered by
     * every screen as a deduction, and the only thing that would notice is the bank. Zero is
     * allowed on all of them and is the normal value for most people in most months.
     *
     * ## `net_salary` is a GENERATED column, and nothing in PHP writes it
     *
     *     net_salary = base_salary + allowance + bonus - deduction - advance - leave_impact
     *
     * `GENERATED ALWAYS AS (…) STORED` — PostgreSQL computes it, in exact `numeric`, on every
     * insert and every update, and refuses any attempt to write it.
     *
     * This is the strongest form of the argument this repo has now made four times about stored
     * derived facts (`meetings.status`, the overdue flag, the monthly rollup, `income` totals).
     * Those three refused to store the fact at all. Here Part D §20 names `net_salary` as a
     * column and it has to exist — a payslip is issued, released and archived, and a net that
     * was a `SELECT` expression would be recomputed by a report six months later from columns
     * an Admin had since corrected. So the question is not *store or compute* but *who computes
     * it*, and the answer that makes the two impossible to disagree is: the database, on write.
     *
     * What it buys, concretely:
     *
     *   - **A row whose net does not match its parts cannot exist.** Not from `PayrollService`,
     *     not from `PayrollSeeder`, not from a Phase 12 import, not from somebody at `psql`, and
     *     not from a future screen that updates `bonus` with `->update()` and forgets to
     *     recalculate. The last one is not hypothetical: `PayrollItemPolicy` lets an Admin edit
     *     an item right up to the lock, and every one of those edits changes the net.
     *   - **No float, ever.** `numeric + numeric` in PostgreSQL is exact base-10 arithmetic.
     *
     * The one division in this feature — `unpaid_days × daily rate`, where the daily rate is a
     * monthly figure divided by a day count — is **not** here. It is computed in PHP, in
     * **integer cents**, and lands in `leave_impact` as an exact two-decimal figure before this
     * expression ever sees it. See `PayrollService::leaveImpactCents()`, which is where the
     * rounding rule is stated and argued. So the whole feature is exact `numeric` inside the
     * database and exact integers inside PHP, and a float appears nowhere in either.
     *
     * **`net_salary` may be negative, and that is not an error.** Somebody who drew an advance
     * larger than the month's pay, or took the whole month unpaid, has a net below zero — it is
     * the honest answer, it is what the next month recovers, and clamping it at zero would
     * quietly forgive money the company is owed. It is the same reasoning that lets
     * `FinanceService::monthlyRollup()` return a negative net. So there is no CHECK on its sign,
     * and there is one on every part that feeds it.
     *
     * ## What else is a CONSTRAINT rather than an `if` in PHP
     *
     *   - **`UNIQUE (payroll_period_id, employee_id)`.** One line per person per month. This is
     *     also the whole of `hq:create-payroll-draft`'s idempotency: the command has no
     *     `drafted_at` flag and no cache lock (decision 7-5's reasoning), it simply cannot write
     *     a second line for somebody, so running it twice on the 1st is a no-op rather than a
     *     doubled payroll.
     *   - **`ON DELETE CASCADE` from `payroll_periods`.** An item has no meaning without its
     *     month. Nothing deletes a period that has been locked — the state machine has no verb
     *     for deleting one at all — but a draft created for the wrong month should take its
     *     lines with it and leave nothing orphaned.
     *   - **`ON DELETE RESTRICT` to `employees`.** A payslip must never lose who it was for.
     *   - **`admin_notes` is plain nullable text with no default.** Its privacy is not a schema
     *     property; see below.
     *
     * ## `admin_notes` — the column the Accountant never receives
     *
     * Part D §14: *"`payroll_items.admin_notes` is the 'personal notes' column the Accountant
     * never receives."* Part C §1's matrix says the same from the other side: *View others'
     * payroll — ADMIN ✅, ACCOUNTANT 🟡 amounts, **no personal notes***.
     *
     * The database stores it like any other text. The rule is enforced where Part B §3 rule 1
     * says it must be — in the serializer, by the key being **absent** from the payload rather
     * than null or masked — and `PayrollItemResource` is the only way this row leaves the
     * server. `PayrollPrivacyTest` pins it with `array_key_exists` plus a recursive
     * forbidden-key walk, in the shape `AccountantProjectEndpointTest` established.
     */
    public function up(): void
    {
        Schema::create('payroll_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('payroll_period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();

            // Copied from `employee_salaries` when the draft is created, and adjustable by the
            // Accountant afterwards (Part D §14). They are the item's own figures from that
            // moment on: re-running Calculate does not re-read the salary table, because that
            // would silently undo the adjustment the Accountant was asked to make.
            $table->decimal('base_salary', 12, 2)->default('0.00');
            $table->decimal('allowance', 12, 2)->default('0.00');

            $table->decimal('bonus', 12, 2)->default('0.00');
            $table->decimal('deduction', 12, 2)->default('0.00');
            $table->decimal('advance', 12, 2)->default('0.00');

            // `leave_requests.unpaid_days` falling in this month × the daily rate. Written only
            // by `PayrollService::calculate()`; see that method for the daily-rate rule.
            $table->decimal('leave_impact', 12, 2)->default('0.00');

            // Part D §14's "personal notes". Absent from the Accountant's payload — see above.
            $table->text('admin_notes')->nullable();

            $table->timestamps();

            $table->unique(['payroll_period_id', 'employee_id'], 'payroll_items_one_per_employee_per_period');
            // "My payslip" is every item for one employee, newest month first, which is a join
            // to `payroll_periods`; this is the index it starts from.
            $table->index('employee_id');
        });

        // The generated net. Added by hand rather than through the Blueprint so the expression
        // is written exactly as PostgreSQL stores it, beside the CHECKs that guard its inputs.
        DB::statement(
            'ALTER TABLE payroll_items ADD COLUMN net_salary numeric(12, 2) '
            .'GENERATED ALWAYS AS (base_salary + allowance + bonus - deduction - advance - leave_impact) STORED',
        );

        DB::statement(
            'ALTER TABLE payroll_items ADD CONSTRAINT payroll_items_amounts_are_not_negative CHECK ('
            .'base_salary >= 0 AND allowance >= 0 AND bonus >= 0 '
            .'AND deduction >= 0 AND advance >= 0 AND leave_impact >= 0)',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_items');
    }
};
