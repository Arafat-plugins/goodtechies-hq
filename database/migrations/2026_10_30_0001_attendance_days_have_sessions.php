<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An office day is a list of sessions (decision 12-76, superseding the "gap counts" clause of
 * 12-74).
 *
 * An office employee may clock in and out several times a day, the way Jibble, Hubstaff and
 * Deel let them: each in -> out is one `attendance_sessions` row under the day's
 * `attendance_records` row, and the gap between two sessions is a break, not worked time.
 *
 * `attendance_records` keeps what it always held — the first clock-in, the last clock-out and
 * the status the first clock-in earned — so the absent sweep, the roster and payroll keep their
 * one row per day. What changes is how long the day was: the sum of its closed sessions.
 *
 * ## Shape
 *
 * - One open session per day at most: a partial unique index on `attendance_record_id where
 *   clock_out is null`, so a double tap cannot open two.
 * - `clock_out >= clock_in`, by CHECK.
 * - Every existing row with a clock-in is backfilled as one session spanning its record, so a
 *   past day reads exactly as before.
 *
 * ## The view
 *
 * `daily_work_summary` (which payroll reads) now sums the closed sessions for `worked_minutes`,
 * falling back to the record's span for a record with no session rows. Everything else is
 * byte-for-byte the 2026_10_29_0001 definition. The view is dropped and recreated, which drops
 * its grants, so SELECT is granted again to `hq_app` and `hq_ro`.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return 'pgsql_migrator';
    }

    public function up(): void
    {
        Schema::create('attendance_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_record_id')->constrained('attendance_records')->cascadeOnDelete();
            $table->timestamp('clock_in');
            $table->timestamp('clock_out')->nullable();
            $table->timestamps();

            $table->index(['attendance_record_id', 'clock_in']);
        });

        DB::statement('alter table attendance_sessions add constraint attendance_sessions_clock_out_after_in check (clock_out is null or clock_out >= clock_in)');

        DB::statement('create unique index attendance_sessions_one_open_per_day on attendance_sessions (attendance_record_id) where clock_out is null');

        DB::statement(<<<'SQL'
            insert into attendance_sessions (attendance_record_id, clock_in, clock_out, created_at, updated_at)
            select id, clock_in, clock_out, now(), now()
            from attendance_records
            where clock_in is not null
        SQL);

        DB::statement('drop view if exists daily_work_summary');

        DB::statement(<<<'SQL'
            create view daily_work_summary as
            with office as (
                select
                    ar.employee_id,
                    ar.date as work_date,
                    ar.status,
                    ar.clock_in,
                    ar.clock_out,
                    case
                        when ar.clock_in is not null and ar.clock_out is not null
                        then coalesce(
                            (select sum(floor(extract(epoch from (s.clock_out - s.clock_in)) / 60))::int
                               from attendance_sessions s
                              where s.attendance_record_id = ar.id and s.clock_out is not null),
                            floor(extract(epoch from (ar.clock_out - ar.clock_in)) / 60)::int)
                    end as worked_minutes
                from attendance_records ar
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
     * Back to 2026_10_29_0001's view, then the table: the view reads the table, so it goes first.
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

        Schema::dropIfExists('attendance_sessions');
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
