<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Schedule
|--------------------------------------------------------------------------
|
| Run by the VPS cron (`php artisan schedule:run` every minute), in the app
| timezone. One server, so withoutOverlapping() is enough.
|
*/

// Backups (master prompt Part B §4): encrypted daily database dump, weekly restore test.
Schedule::command('backup:clean')->dailyAt('01:30')->withoutOverlapping();
Schedule::command('backup:run --only-db')->dailyAt('02:00')->withoutOverlapping();
Schedule::command('backup:monitor')->dailyAt('03:00')->withoutOverlapping();
Schedule::command('hq:verify-backup')->weeklyOn(0, '04:00')->withoutOverlapping();
