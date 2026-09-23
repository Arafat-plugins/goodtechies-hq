<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Str;

it('schedules the recurring sweep, the two task reminders, the backups, the cleanup, the monitor and the weekly verification', function () {
    $events = collect(app(Schedule::class)->events())
        ->mapWithKeys(fn (Event $event) => [
            trim(Str::after($event->command, 'artisan'), " '\"") => $event,
        ]);

    expect($events->map(fn (Event $event) => $event->expression)->all())->toBe([
        // Phase 3: the period's instance for every template that is due. 00:05 so the period has
        // rolled over first; the five minutes are slack and not precision, because the engine
        // works from the period the RUN DATE falls in rather than from a next_run_at it has to
        // hit exactly — see RecurringTaskEngine::due().
        'hq:generate-recurring-tasks' => '5 0 * * *',
        // Phase 2: one notification per newly overdue task, first thing in the morning. The
        // "newly" is not this schedule's doing — see FlagOverdueTasks — so a missed run costs
        // a day's warning, never a duplicate.
        'hq:flag-overdue' => '0 8 * * *',
        // Phase 3: the last of §19's fixed automation rules. Same 08:00 slot as the overdue
        // sweep, and "once per task" is answered by the notifications table here too, so the two
        // running a minute apart cannot double up.
        'hq:notify-due-tomorrow' => '0 8 * * *',
        'backup:clean' => '30 1 * * *',
        'backup:run --only-db' => '0 2 * * *',
        'backup:monitor' => '0 3 * * *',
        'hq:verify-backup' => '0 4 * * 0',
    ]);

    $events->each(fn (Event $event) => expect($event->withoutOverlapping)->toBeTrue()
        ->and($event->timezone ?? config('app.timezone'))->toBe(config('app.timezone')));
})->group('phase0');
