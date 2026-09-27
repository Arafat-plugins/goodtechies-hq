<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two remaining read-path indexes the hardening slice's index audit found (Phase 12).
 *
 * ## How they were found
 *
 * Not by reading code. Every foreign key in the schema was compared against every index, with
 * one query against the live schema:
 *
 *     SELECT c.conrelid::regclass, c.conkey
 *     FROM pg_constraint c
 *     WHERE c.contype = 'f'
 *       AND NOT EXISTS (SELECT 1 FROM pg_index i WHERE i.indrelid = c.conrelid AND …)
 *
 * It returned **33** foreign-key columns with no index on them, because **PostgreSQL does not
 * index a foreign key automatically** — the constraint is enforced against the *referenced*
 * table's key and the referencing column is left bare. That is the gap that left `audit_logs`
 * unindexed until the viewer was built (decision 12-19), and it is the reason to check the list
 * rather than assume Laravel did it.
 *
 * **Thirty-one of the thirty-three are correctly bare**, and the audit's conclusion is as much
 * that as it is these two. They fall into two groups:
 *
 *   - *Provenance columns nothing filters on* — `tasks.created_by`, `.completed_by`,
 *     `.first_completed_by`, `.work_summary_by`, `task_checklists.completed_by`,
 *     `task_links.created_by`, `time_entries.approved_by` / `.edited_by` / `.rejected_by`,
 *     `attendance_records.edited_by`, `employee_salaries.set_by`,
 *     `payroll_periods.lock_reversed_by`, `income.recorded_by`, `expenses.recorded_by`,
 *     `files.uploaded_by`, `messages.author_id`, `recurring_tasks.created_by`. Each is read
 *     *forwards* — "who did this", one row at a time through a `belongsTo` — and never as a
 *     predicate. The second reason an unindexed FK hurts is the referential check on a parent
 *     DELETE, and the parents here are `users` and `employees`, which this application never
 *     deletes (Part B §3 rule 11: deactivate, never delete).
 *   - *Columns already covered by a composite whose leading column is the one the query gives*
 *     — `project_members.employee_id` looked like the worst of them, since `Project::
 *     scopeForEmployee()` and `ProjectPolicy::isAssigned()` both read it on every employee's
 *     task list. Both reach it through the `members` relation, so the emitted SQL is correlated
 *     on `project_id` *and* `employees.id`, which `project_members_project_id_employee_id_unique`
 *     answers. Likewise `leave_balances.leave_type_id` under `(employee_id, leave_type_id)` and
 *     `user_project_permissions.permission_id` under `(user_id, project_id, permission_id)`.
 *
 * An index is a write cost paid on every INSERT for a read that never happens. Adding thirty-one
 * of them to make a list come out empty would be the wrong answer to a real question.
 *
 * ## The two that are not
 *
 * Both are the `audit_logs` shape exactly: a table every session writes to, that nothing ever
 * deletes from, read by a screen that filters on one person and orders by time.
 *
 *   - **`login_history (user_id, created_at desc)`** — this table had **no index at all** beyond
 *     its primary key, and `ProfileController::show()` runs
 *     `where user_id = ? order by created_at desc, id desc limit N` on every Profile page load.
 *     It grows on every successful login *and* every failed one (`RecordFailedLogin`), which
 *     makes it the one table an unauthenticated visitor can inflate on purpose: `throttle:login`
 *     bounds the rate, not the total. Composite and in this order because the user is always
 *     given and the time is always the ordering; `id desc` is the tiebreak inside one
 *     millisecond and needs no column of its own.
 *   - **`activity_logs (actor_id, created_at desc)`** — `activity_logs` had `(object_type,
 *     object_id)`, which is the task-timeline read (`ActivityLogger::for()`), and nothing for
 *     the other direction. `Employee\DashboardController::recentActivity()` — decision 10-45,
 *     the employee shell's "Recent activity" card, on a screen every employee opens every
 *     morning — runs `where actor_id = ? and object_type = ? order by created_at desc, id desc
 *     limit N`. `actor_id` first narrows to one person's rows; `object_type` is then a filter
 *     over that, not a scan over the table.
 *
 * ## Not a constraint
 *
 * Indexes only. A CHECK constraint generated from a PHP enum is frozen at the moment its
 * migration runs, and this database was built at Phase 2 — which is why nothing here is a
 * constraint even where one would read well.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('login_history', function (Blueprint $table): void {
            $table->index(['user_id', 'created_at'], 'login_history_user_created_at_index');
        });

        Schema::table('activity_logs', function (Blueprint $table): void {
            $table->index(['actor_id', 'created_at'], 'activity_logs_actor_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('login_history', function (Blueprint $table): void {
            $table->dropIndex('login_history_user_created_at_index');
        });

        Schema::table('activity_logs', function (Blueprint $table): void {
            $table->dropIndex('activity_logs_actor_created_at_index');
        });
    }
};
