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

// Recurring tasks (master prompt §6, §19, Phase 3): the period's instance for every template
// that is due, one queue job each. 00:05 so the period has rolled over first. The five minutes
// are slack and not precision — a run that does not happen until the next day still generates
// the right period, because the engine works from the period the RUN DATE falls in rather than
// from a `next_run_at` it has to hit exactly. See RecurringTaskEngine::due().
Schedule::command('hq:generate-recurring-tasks')->dailyAt('00:05')->withoutOverlapping();

// Tasks (master prompt §19, Phase 2): one notification per NEWLY overdue task, to its
// assignees and their manager or the Admins. 08:00 because it is meant to be the first thing
// somebody sees, and "newly" is answered by the notifications table rather than by this
// schedule — see FlagOverdueTasks. The Overdue buckets themselves stay query-time.
Schedule::command('hq:flag-overdue')->dailyAt('08:00')->withoutOverlapping();

// Tasks (master prompt §19, Phase 3): the last of the fixed automation rules — a reminder to
// the assignee the day before something is due. Same 08:00 slot as the overdue sweep, because
// both answer the question somebody opens the bell with; "once per task" is answered by the
// notifications table here too, so the two running a minute apart cannot double up.
Schedule::command('hq:notify-due-tomorrow')->dailyAt('08:00')->withoutOverlapping();

// Remote timer (master prompt Part D §7, Phase 4): the two safeguards. Every minute, because
// `heartbeat_timeout_minutes` defaults to five and a sweep that ran hourly would let a closed
// laptop log the rest of the hour — the very thing rule 1 exists to prevent. The sweep touches
// only OPEN entries, of which there is at most one per remote employee, so "every minute" is a
// handful of rows and a partial index.
Schedule::command('hq:timer-watchdog')->everyMinute()->withoutOverlapping();

// Office attendance (master prompt Part D §8, Phase 4): every office employee whose schedule
// says today was a working day and who has no record gets one, status Absent. 23:55 app time,
// because the day has to be over — somebody who clocks in at 23:40 is not absent, and the
// unique index on (employee_id, date) is what makes that true rather than the five minutes.
// What it SKIPS lives in AttendanceService::markAbsent(), including the seam Phase 5 fills
// with approved leave and holidays; this line is only when.
Schedule::command('hq:mark-absent')->dailyAt('23:55')->withoutOverlapping();

// Backups (master prompt Part B §4): encrypted daily database dump, weekly restore test.
Schedule::command('backup:clean')->dailyAt('01:30')->withoutOverlapping();
Schedule::command('backup:run --only-db')->dailyAt('02:00')->withoutOverlapping();
Schedule::command('backup:monitor')->dailyAt('03:00')->withoutOverlapping();
Schedule::command('hq:verify-backup')->weeklyOn(0, '04:00')->withoutOverlapping();
