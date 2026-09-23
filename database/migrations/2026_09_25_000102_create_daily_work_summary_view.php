<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * `daily_work_summary` — the reporting join over the two tables that record a worked day
     * (master prompt Part C rule 6, Phase 4 "Backend" and "DB").
     *
     * ## A VIEW, and the rule is the reason
     *
     * Part C rule 6: *"`attendance_records` (office) and `time_entries` (remote) are separate
     * tables — never merged into one 'worked hours' table. A `daily_work_summary` **view** joins
     * them for reporting only."*
     *
     * The rule exists because two stored answers to "how long did they work" can disagree, and
     * the day they do, Phase 9 pays one of them. A view cannot disagree with its sources: it has
     * no rows of its own, so there is nothing to keep in step, nothing to backfill, and no
     * writer to forget. Every number below is computed from the two tables at the moment it is
     * read. Nothing writes here — PostgreSQL will refuse an INSERT into it, because a view over
     * a FULL OUTER JOIN of two grouped relations is not auto-updatable, and that refusal is a
     * feature rather than a limitation to be worked around with a trigger.
     *
     * ## One row per employee-day, both halves side by side
     *
     * A FULL OUTER JOIN on `(employee_id, date)` rather than a UNION ALL: a report asking "what
     * did the agency do on Tuesday" wants one row per person per day with both columns on it,
     * not two rows it has to fold together. `source` names which half the row came from, in
     * words — `office`, `remote`, or `both` for the case that should not arise. It should not,
     * because `employees.tracking_mode` gives each person exactly one clock; but a report that
     * silently dropped one half of such a day would be exactly the bug rule 6 is about, so the
     * view says so instead.
     *
     * ## The predicates are the ones already written down
     *
     *   - `worked_minutes` is the office clock's pair of timestamps, and NULL while somebody is
     *     still in. It is a difference and one end has not happened yet — the same rule
     *     `AttendanceRecord::workedMinutes()` keeps.
     *   - `tracked_minutes` is `approved_at is not null`, decision 4-7's one question, over
     *     FINISHED entries. An open timer contributes nothing: its length is a question about
     *     `now()` and a view is read long after.
     *   - `pending_minutes` is the rest of the finished entries that nobody has signed off, and
     *     `rejected_minutes` the ones somebody refused. Reported separately and never added into
     *     `tracked_minutes`, so no reader can accidentally count hours that do not count — and
     *     never hidden either, because an afternoon that is simply absent from a report is one
     *     the employee will report as lost.
     *
     * Nothing here is a rate, a target percentage or a comparison between two people. It is four
     * durations, a status and two clock times (Part H §1).
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            create view daily_work_summary as
            with office as (
                select
                    employee_id,
                    date as work_date,
                    status,
                    clock_in,
                    clock_out,
                    case
                        when clock_in is not null and clock_out is not null
                        then floor(extract(epoch from (clock_out - clock_in)) / 60)::int
                    end as worked_minutes
                from attendance_records
            ),
            remote as (
                select
                    employee_id,
                    work_date,
                    floor(sum(case when approved_at is not null then duration_seconds else 0 end) / 60)::int
                        as tracked_minutes,
                    floor(sum(case when approved_at is null and rejected_at is null then duration_seconds else 0 end) / 60)::int
                        as pending_minutes,
                    floor(sum(case when rejected_at is not null then duration_seconds else 0 end) / 60)::int
                        as rejected_minutes,
                    count(*)::int as entry_count
                from time_entries
                where ended_at is not null
                group by employee_id, work_date
            )
            select
                coalesce(office.employee_id, remote.employee_id) as employee_id,
                coalesce(office.work_date, remote.work_date) as work_date,
                case
                    when office.employee_id is not null and remote.employee_id is not null then 'both'
                    when office.employee_id is not null then 'office'
                    else 'remote'
                end as source,
                office.status as attendance_status,
                office.clock_in,
                office.clock_out,
                office.worked_minutes,
                remote.tracked_minutes,
                remote.pending_minutes,
                remote.rejected_minutes,
                remote.entry_count
            from office
            full outer join remote
                on remote.employee_id = office.employee_id
                and remote.work_date = office.work_date
        SQL);

        // `ALTER DEFAULT PRIVILEGES … ON TABLES` in deploy/sql/roles.sql already covers a view
        // created afterwards by hq_migrator, so this is belt and braces for a database whose
        // defaults were set up differently — and it is guarded, because local development runs
        // as `postgres` with no hq_* roles at all. Same shape as the audit_logs REVOKE.
        foreach (['hq_app', 'hq_ro'] as $role) {
            $this->grantSelect($role);
        }
    }

    public function down(): void
    {
        DB::statement('drop view if exists daily_work_summary');
    }

    private function grantSelect(string $role): void
    {
        $connection = DB::connection($this->getConnection());

        if ($connection->selectOne('select 1 as found from pg_roles where rolname = ?', [$role]) === null) {
            return;
        }

        $quoted = '"'.str_replace('"', '""', $role).'"';

        $connection->statement("grant select on daily_work_summary to {$quoted}");
    }
};
