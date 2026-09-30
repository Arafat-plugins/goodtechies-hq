<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Task timers for everyone — the breakdown entry (decision 12-73, flow F3).
 *
 * Office employees and Admins may now run a timer on a task they are assigned to, inside an
 * open clock-in. Their DAY is still the office clock: `attendance_records` is what their hours,
 * the absent sweep and payroll are built on, and nothing about that changes. The timer's rows
 * are a breakdown of that day by task — how the clocked-in hours split across the work — and
 * must never be read as hours a second time.
 *
 * ## One boolean, not a third `entry_type`
 *
 * `entry_type` says how a row came to exist (`auto`: the timer measured it; `manual`: somebody
 * typed it). A breakdown row is measured by the timer exactly as a remote one is, so it is
 * `auto` — what differs is not its provenance but whether it is anybody's hours. That is a
 * separate fact and gets a separate column, so a future hand-typed breakdown row would be
 * `manual` + `counts_toward_hours = false` rather than a fourth word.
 *
 * `default true` means every existing row, and every row the remote timer writes, reads exactly
 * as before. `TimeEntry::scopeCounted()` and `counts()` now ask `approved_at is not null AND
 * counts_toward_hours`, so every total that already went through them — `trackedMinutes()`,
 * the 5 h target, Admin → Time, Reports, Workload — drops a breakdown row with no edit of its
 * own. `tasks.tracked_seconds` asks `scopeTracked()` (approved, either kind), because the
 * task's own total is precisely what the breakdown is for.
 *
 * ## The view
 *
 * `daily_work_summary` sums `time_entries` with its own SQL rather than through the model, and
 * **payroll reads this view** (see `2026_09_27_000301`). Left alone, Yaseen's timed afternoon
 * would have turned his office day into `source = both` with tracked minutes on top of his
 * clocked ones. The remote CTE now reads `counts_toward_hours` rows only; everything else in
 * the view is byte-for-byte the 000301 definition.
 *
 * ## Grants
 *
 * The column needs none: `hq_app` holds table-level privileges on `time_entries`, which cover a
 * new column. The view is dropped and recreated, which drops its grants, so SELECT is granted
 * again to `hq_app` and `hq_ro` exactly as 000301 does.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return 'pgsql_migrator';
    }

    public function up(): void
    {
        DB::statement('alter table time_entries add column counts_toward_hours boolean not null default true');

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
                    and counts_toward_hours
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
     * Back to 000301's view, then the column: the view reads the column, so it goes first.
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

        DB::statement('alter table time_entries drop column if exists counts_toward_hours');
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
