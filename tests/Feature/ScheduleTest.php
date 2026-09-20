<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Str;

it('schedules the backup, cleanup, monitor and weekly verification commands', function () {
    $events = collect(app(Schedule::class)->events())
        ->mapWithKeys(fn (Event $event) => [
            trim(Str::after($event->command, 'artisan'), " '\"") => $event,
        ]);

    expect($events->map(fn (Event $event) => $event->expression)->all())->toBe([
        'backup:clean' => '30 1 * * *',
        'backup:run --only-db' => '0 2 * * *',
        'backup:monitor' => '0 3 * * *',
        'hq:verify-backup' => '0 4 * * 0',
    ]);

    $events->each(fn (Event $event) => expect($event->withoutOverlapping)->toBeTrue()
        ->and($event->timezone ?? config('app.timezone'))->toBe(config('app.timezone')));
})->group('phase0');
