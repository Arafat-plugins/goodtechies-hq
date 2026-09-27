<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What each employee is paid, and what they **were** paid (master prompt Part D §20:
     * `employee_salaries (employee_id, base_salary, allowance, effective_from, set_by)`).
     * Phase 9.
     *
     * ## History is an EFFECTIVE-FROM ROW PER CHANGE, not a current row plus an audit trail
     *
     * Part D §14 asks for *"current base + allowances, **with history**; changes audit-logged as
     * 'salary changed'"*, and Part D §20's column list above already names `effective_from`. So
     * the shape is settled by the plan — but it is worth writing down why it is the right shape,
     * because the alternative is genuinely tempting and genuinely wrong here.
     *
     * **The alternative:** one mutable row per employee, with the old values recoverable from
     * `audit_logs`. It is cheaper, and this repo has twice argued that the audit log is the
     * more durable place to keep a superseded fact (`FinanceService`'s hard deletes). It fails
     * on one requirement, and the requirement is the whole point of the table:
     *
     * > A payroll run must be able to say **which salary it used**, and re-running an old month
     * > must produce that month's number — after a raise, after a correction, for ever.
     *
     * `audit_logs` cannot answer that. It is a compliance trail: ADMIN-only by Part C §1,
     * append-only by grant, shaped as JSON `old_value`/`new_value` with no index on the subject
     * and no notion of *"in force on this date"*. Asking it what Tapu earned in September means
     * replaying every `salary.changed` row for him in order and stopping at the right one — a
     * fold over an audit log, run inside a payroll calculation, on a table the Accountant who
     * runs payroll is not allowed to read. A payslip that cannot be recomputed without ADMIN
     * access to the compliance log is not a payslip anybody can support.
     *
     * With effective-dated rows the same question is one indexed query:
     *
     *     SELECT … WHERE employee_id = ? AND effective_from <= ? ORDER BY effective_from DESC LIMIT 1
     *
     * — `PayrollService::salaryFor()`, and the only place in the application that answers it.
     * **A raise dated in November cannot change what that query returns for September**, because
     * `effective_from <= '2026-09-01'` never sees the November row. That is not a convention a
     * later phase can forget; it is arithmetic on an index. `PayrollCalculationTest` proves it
     * from both ends: the September figure after a November raise, and the November figure.
     *
     * The audit trail is still written — `salary.changed`, Part C §4 — and it is still the
     * record of *who* changed a salary and *when they did it*, which is a different question
     * from *what was in force in September* and is the question an audit log is for.
     *
     * ## Nothing is ever UPDATEd here, and the unique index is why
     *
     * `PayrollService::setSalary()` INSERTs. A history table whose rows get edited is a history
     * that can be rewritten, and the payslip it justified would change underneath a month that
     * has already been paid.
     *
     * **`UNIQUE (employee_id, effective_from)`** is what makes that a rule rather than a habit:
     * correcting today's salary is the same act as setting it, and it collides. The service
     * turns the collision into an update of that one dated row — the same day, the same person,
     * a typed-wrong number fixed before anything read it — and every *other* date is a new row.
     * So the history has one statement of the truth per date and no way to grow a second.
     *
     * ## What is a CONSTRAINT here rather than an `if` in PHP
     *
     *   - **`base_salary >= 0` and `allowance >= 0`.** The sign is not a number this table
     *     carries: a negative salary is not a pay cut, it is a typo or a swapped subtraction,
     *     and it would come out of `payroll_items` as a net the finance ledger then pays.
     *     **Zero is allowed** and is not the same as absent: an unpaid intern, a partner on
     *     allowance only, or somebody's last month before a role change are all a real 0.00,
     *     and the row's existence is what says a figure was decided.
     *   - **`UNIQUE (employee_id, effective_from)`** — above.
     *   - **`ON DELETE CASCADE` from `employees`, `ON DELETE RESTRICT` to `users` for
     *     `set_by`.** A salary is a fact about an employee and has no meaning without one;
     *     whoever set it stays nameable for ever. Nothing in this application deletes either
     *     (Part D §21: leaving is `status = inactive`), and these are what keep that true.
     *
     * ## `decimal(12, 2)`, and never a float
     *
     * Phase 8's reasoning, unchanged and if anything sharper: binary floating point cannot
     * represent 0.10 exactly, and a daily rate is this column **divided** by a day count. A
     * float base salary would put its error into every unpaid-leave deduction in the company,
     * in a direction nobody could predict, on a figure an employee will one day query. Every
     * arithmetic step on this money is either exact `numeric` inside PostgreSQL or integer
     * cents inside PHP — see `PayrollService::leaveImpactCents()`.
     */
    public function up(): void
    {
        Schema::create('employee_salaries', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            $table->decimal('base_salary', 12, 2);
            // Part D §20 says "allowances", singular column. One figure, because Part D §14
            // pays "current base + allowances" as one line on a payslip and nothing in the
            // spec breaks it down. A per-kind breakdown is a table this phase was not asked
            // for and would be building ahead (Part H).
            $table->decimal('allowance', 12, 2)->default('0.00');

            // The day this figure starts applying. `PayrollService::salaryFor($employee, $date)`
            // reads the latest row at or before $date — see the class note.
            $table->date('effective_from');

            // Who decided it. Part C §4's `salary.changed` audit row names the actor too; this
            // column is what lets the SALARY SCREEN say it without reading the audit log, which
            // the Accountant may not.
            $table->foreignId('set_by')->constrained('users')->restrictOnDelete();

            $table->timestamps();

            // The one query this table exists for, covered end to end: employee, then the
            // latest effective date at or before a given day.
            $table->unique(['employee_id', 'effective_from'], 'employee_salaries_one_per_day');
            $table->index(['employee_id', 'effective_from']);
        });

        DB::statement(
            'ALTER TABLE employee_salaries ADD CONSTRAINT employee_salaries_amounts_are_not_negative '
            .'CHECK (base_salary >= 0 AND allowance >= 0)',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_salaries');
    }
};
