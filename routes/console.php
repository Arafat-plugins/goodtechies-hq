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

// Tasks (master prompt §19, Phase 2): one notification per NEWLY overdue task, to its
// assignees and their manager or the Admins. 08:00 because it is meant to be the first thing
// somebody sees, and "newly" is answered by the notifications table rather than by this
// schedule — see FlagOverdueTasks. The Overdue buckets themselves stay query-time.
Schedule::command('hq:flag-overdue')->dailyAt('08:00')->withoutOverlapping();

// Backups (master prompt Part B §4): encrypted daily database dump, weekly restore test.
Schedule::command('backup:clean')->dailyAt('01:30')->withoutOverlapping();
Schedule::command('backup:run --only-db')->dailyAt('02:00')->withoutOverlapping();
Schedule::command('backup:monitor')->dailyAt('03:00')->withoutOverlapping();
Schedule::command('hq:verify-backup')->weeklyOn(0, '04:00')->withoutOverlapping();
