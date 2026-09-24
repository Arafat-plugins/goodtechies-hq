<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Teach `daily_work_summary` about leave and holidays.
 *
 * Part D §20 says the view unions `attendance_records`, `time_entries`, `leave_requests` **and**
 * `holidays`. Phase 4 built the first two because the other two did not exist yet, and decision
 * **5-18** recorded the gap. It is not a cosmetic one: **Phase 9 payroll reads this view**, and
 * two of the four things it must know were missing.
 *
 * ## Why leave is a FULL OUTER join and not a LEFT one
 *
 * Decision **5-9**: approving leave for a remote-timer employee writes **no attendance row at
 * all**, deliberately, so that "Tapu is never Absent" stays structural. He also files no time
 * entry on a day he is away. So his leave day has neither half — and under a LEFT join it would
 * simply not be in the view. A payroll run reading the view would see the month with his leave
 * silently missing. A third full outer is the only shape that keeps that day.
 *
 * `source` therefore grows a fourth answer, `leave`, meaning "this row exists because somebody
 * was away, and for no other reason".
 *
 * ## Why holidays are a LEFT join and joined on the DATE alone
 *
 * A holiday has no employee: `holidays` is (date, name). It annotates the employee-days that are
 * already here rather than creating any. A holiday on which nobody has an attendance row, a time
 * entry or an approved leave produces no rows, and that is correct — this view is one row per
 * employee per day, and there is no employee in a bare holiday to make a row for. Payroll walks
 * employees × days itself; what it needs from here is "was the day this row describes a public
 * holiday", which is what it now gets.
 *
 * Two holidays can share one date — decision **5-3** notes the seeded year has exactly that — so
 * they are aggregated to one row per date before the join. Otherwise a single May Day would
 * double every employee-day it touched.
 *
 * ## Both new CTEs aggregate, and that is deliberate
 *
 * `leave_days` groups even though `leave_requests_no_overlap` already makes two approved
 * requests on one day impossible (decision 5-7). A join that can fan out is a join that will,
 * the day somebody adds a status the exclusion's WHERE clause does not cover — and a fanned-out
 * row here is double-counted pay.
 *
 * Nothing here is a rate, a percentage or a comparison between two people (Part H §1).
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return 'pgsql_migrator';
    }

    public function up(): void
    {
        DB::statement('drop view if exists daily_work_summary');

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
            ),
            leave_days as (
                select
                    lr.employee_id,
                    day::date as work_date,
                    min(lt.name) as leave_type,
                    bool_or(lt.is_unpaid) as leave_is_unpaid
                from leave_requests lr
                join leave_types lt on lt.id = lr.leave_type_id
                cross join lateral generate_series(lr.start_date, lr.end_date, interval '1 day') as day
                where lr.status = 'approved'
                group by lr.employee_id, day::date
            ),
            holiday_days as (
                select
                    date as work_date,
                    string_agg(name, ', ' order by name) as holiday_name
                from holidays
                group by date
            ),
            worked as (
                select
                    coalesce(office.employee_id, remote.employee_id, leave_days.employee_id) as employee_id,
                    coalesce(office.work_date, remote.work_date, leave_days.work_date) as work_date,
                    case
                        when office.employee_id is not null and remote.employee_id is not null then 'both'
                        when office.employee_id is not null then 'office'
                        when remote.employee_id is not null then 'remote'
                        else 'leave'
                    end as source,
                    office.status as attendance_status,
                    office.clock_in,
                    office.clock_out,
                    office.worked_minutes,
                    remote.tracked_minutes,
                    remote.pending_minutes,
                    remote.rejected_minutes,
                    remote.entry_count,
                    leave_days.employee_id is not null as on_leave,
                    leave_days.leave_type,
                    leave_days.leave_is_unpaid
                from office
                full outer join remote
                    on remote.employee_id = office.employee_id
                    and remote.work_date = office.work_date
                full outer join leave_days
                    on leave_days.employee_id = coalesce(office.employee_id, remote.employee_id)
                    and leave_days.work_date = coalesce(office.work_date, remote.work_date)
            )
            select
                worked.*,
                holiday_days.work_date is not null as is_holiday,
                holiday_days.holiday_name
            from worked
            left join holiday_days on holiday_days.work_date = worked.work_date
        SQL);

        foreach (['hq_app', 'hq_ro'] as $role) {
            $this->grantSelect($role);
        }
    }

    /**
     * Back to the two-source view. Spelled out rather than "re-run the other migration",
     * because a rollback that depended on another file still being on disk is one that breaks
     * the day somebody squashes.
     */
    public function down(): void
    {
        DB::statement('drop view if exists daily_work_summary');

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

        foreach (['hq_app', 'hq_ro'] as $role) {
            $this->grantSelect($role);
        }
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
