<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            SettingsSeeder::class,
            TeamSeeder::class,
            DemoSeeder::class,
            // Phase 2: tasks hang off DemoSeeder's projects, so it has to have run first.
            TaskSeeder::class,
            // Phase 3: the retainer templates. Also DemoSeeder's projects, and deliberately
            // AFTER TaskSeeder so the seeded task count stays the twenty-five Phase 2 asserts —
            // this seeder creates templates and generates nothing.
            RecurringTaskSeeder::class,
            // Phase 5: the Bangladesh public-holiday list for the current year, as a starting
            // point the Admin edits. It depends on nothing — a holiday is a fact about the
            // company and has no employee, project or task on it — so its position here is
            // only "after the things that do have dependencies".
            HolidaySeeder::class,
            // Phase 5: the six leave types, and an opening balance per employee on the capped
            // four. After TeamSeeder, because the balances are per employee; it creates no
            // requests, so the acceptance walk starts from an empty queue.
            LeaveSeeder::class,
            // Phase 7: four demo meetings, all created through MeetingService so the organiser
            // is seated, the participants are filtered by `meetings.use` and the activity trail
            // is written. It needs TeamSeeder's people and DemoSeeder's projects, so it goes
            // last; it creates no tasks, so the seeded task count stays what Phase 2 asserts.
            MeetingSeeder::class,
            // Phase 8: the two finance category lists (Part D §13), and a September 2026 whose
            // income rolls up to the acceptance example's $2,910 — Maintenance $860, SEO $800,
            // Website $1,250. It needs TeamSeeder's Accountant, who records every row through
            // FinanceService, and DemoSeeder's projects for the optional project links, so it
            // goes after both. It creates no tasks and no meetings, so every count an earlier
            // phase asserts is unchanged.
            FinanceSeeder::class,
            // Phase 9: a salary per employee with one raise in its history, and September
            // 2026's payroll **draft** — one line per active employee at the salary in force
            // on the 1st. It needs TeamSeeder's people and it goes after FinanceSeeder
            // deliberately: the two share September 2026, and a payroll period seeded as
            // `locked` or `paid` would close that month to the whole finance ledger (Part D
            // §13). It is seeded open, for the reasons in PayrollSeeder's own docblock. Like
            // FinanceSeeder it writes its rows directly rather than through its service, so
            // the seeded audit log is still empty.
            PayrollSeeder::class,
            // Phase 10: the office attendance and the tracked remote time that Phase 4's
            // screens — Attendance, Time, the Timesheet, Workload, the dashboards' two time
            // cards — and Phase 10's Attendance and Time reports all read, and which no seeder
            // had ever written. It goes LAST because it needs three earlier seeders at once:
            // TeamSeeder's people and schedules, TaskSeeder's tasks (time is always tracked
            // against one) and HolidaySeeder's calendar, which is half of the rule that a
            // working day is never invented. It is deliberately after PayrollSeeder although
            // nothing in payroll reads attendance today: a draft that DID would then be a draft
            // seeded before the days it is drawn from, and that is a trap to close now rather
            // than to discover on the morning somebody wires the two together.
            WorkSeeder::class,
        ]);
    }
}
