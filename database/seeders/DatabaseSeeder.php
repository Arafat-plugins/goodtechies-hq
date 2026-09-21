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
        ]);
    }
}
