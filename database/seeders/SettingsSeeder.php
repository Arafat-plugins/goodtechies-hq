<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    /**
     * The only settings keys and their defaults. Adding a key requires a recorded decision.
     *
     * @var array<string, mixed>
     */
    public const DEFAULTS = [
        'timezone' => 'Asia/Dhaka',
        'currency' => 'USD',
        'late_grace_minutes' => 15,
        'half_day_auto' => false,
        'timer_max_session_hours' => 10,
        'heartbeat_timeout_minutes' => 5,
        'manual_time_requires_approval' => true,
        'notification_group_window_minutes' => 2,
        'idle_pause_minutes' => 5,
        'idle_flag_percent' => 25,
        'backup_last_verified_at' => null,
    ];

    /**
     * Insert missing keys only, so a re-seed never overwrites a value an admin changed.
     */
    public function run(): void
    {
        foreach (self::DEFAULTS as $key => $value) {
            Setting::firstOrCreate(['key' => $key], ['value' => $value]);
        }
    }
}
