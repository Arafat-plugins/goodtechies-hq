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
        ]);
    }
}
