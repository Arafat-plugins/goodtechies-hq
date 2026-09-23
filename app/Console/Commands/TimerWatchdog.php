<?php

namespace App\Console\Commands;

use App\Services\TimerService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Every minute: the two safeguards from master prompt Part D §7.
 *
 * **Rule 1 — heartbeat timeout.** A running client pings every 60 s. An entry that has not been
 * pinged for `settings.heartbeat_timeout_minutes` is stopped AT its last heartbeat and flagged
 * with a sentence saying so. The requirement is "a closed laptop never logs hours", and the
 * ending has to be the last heartbeat rather than now, or the laptop logs every hour it was shut.
 *
 * **Rule 2 — max session.** An entry that has been running longer than
 * `settings.timer_max_session_hours`, heartbeats and all, is paused and flagged "very long
 * session". Nobody works ten hours straight without a break the timer knows about, so this
 * catches the other failure: a timer left on beside somebody who went home.
 *
 * ## Why the command holds no rules
 *
 * Both sweeps are `TimerService` methods, and the thresholds are read inside them from
 * `SettingsService`. This file is a schedule entry and a console summary. That is deliberate:
 * Phase 11's browser extension shares the same `time_entries` and the same two rules, and a rule
 * living in a command is a rule the extension's path would have to restate.
 *
 * ## Order
 *
 * Rule 1 runs first. An entry that has been silent for an hour AND has been running for eleven
 * is dead, not overlong — stopping it at its last heartbeat is the true account of the day, and
 * pausing it first would leave the ending at "now" for rule 1 to find a minute later.
 */
#[Signature('hq:timer-watchdog')]
#[Description('Stop timers whose client has gone silent, and pause sessions that have run too long')]
class TimerWatchdog extends Command
{
    public function handle(TimerService $timer): int
    {
        $stopped = $timer->stopAbandoned();
        $paused = $timer->pauseOverlongSessions();

        if ($stopped === [] && $paused === []) {
            $this->info('No timers needed attention.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%d timer%s stopped at the last heartbeat, %d paused for running too long.',
            count($stopped),
            count($stopped) === 1 ? '' : 's',
            count($paused),
        ));

        return self::SUCCESS;
    }
}
