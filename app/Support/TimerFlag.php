<?php

namespace App\Support;

/**
 * The sentences the two safeguards write into `time_entries.flag_reason` (master prompt Part D
 * §7, "Safeguards — two rules, both flag the entry for review").
 *
 * A flag is a fact with a reason, and the reason is a sentence rather than a code, because the
 * Time page has to print it to the person whose afternoon it is. A row flagged `heartbeat_lost`
 * is a row nobody investigates; a row that says *"the browser stopped answering at 3:12 pm, so
 * the entry ends there"* is one somebody can act on.
 *
 * The thresholds are always passed in — they are `settings.heartbeat_timeout_minutes` and
 * `settings.timer_max_session_hours`, read through `SettingsService`, never a constant here.
 * That is also why the numbers appear in the sentence: an Admin who lowers the timeout wants
 * yesterday's flags to still say what the rule was when they fired.
 */
final class TimerFlag
{
    /**
     * Rule 1 — the running client stopped pinging, so the entry was closed at its last ping.
     */
    public static function heartbeatTimeout(int $timeoutMinutes, string $stoppedAt): string
    {
        return sprintf(
            'Stopped automatically: the timer stopped checking in for more than %d minute%s, '
            .'so this entry ends at its last check-in, %s. Nothing was counted after that.',
            $timeoutMinutes,
            $timeoutMinutes === 1 ? '' : 's',
            $stoppedAt,
        );
    }

    /**
     * Rule 2 — a single session ran past the maximum, so it was paused for review.
     */
    public static function maxSession(float $maxHours): string
    {
        return sprintf(
            'Paused automatically: this session had been running for more than %s hours. '
            .'Check the hours are right before this day is signed off.',
            rtrim(rtrim(number_format($maxHours, 2, '.', ''), '0'), '.'),
        );
    }

    /**
     * A stopped entry was extended when the browser came back with later check-ins than the
     * server had seen.
     *
     * It replaces the sentence that was on the row, rather than sitting beside it: the old one
     * named the moment the entry used to end, and that moment has moved. A flag whose reason has
     * stopped being true is worse than no flag at all.
     */
    public static function extendedOnReplay(string $endsAt): string
    {
        return sprintf(
            'Stopped automatically when the timer stopped checking in, then extended to %s '
            .'when the browser reconnected with later check-ins. Worth a look before sign-off.',
            $endsAt,
        );
    }

    /**
     * A replayed offline batch claimed more time than its heartbeats could account for. The
     * entry keeps the server's answer; this says so, because a silently shortened afternoon is
     * exactly the thing the employee will otherwise report as lost time.
     */
    public static function replayTrimmed(string $keptUntil): string
    {
        return sprintf(
            'Shortened on reconnect: the browser had been offline and reported more time than '
            .'its last check-in could account for, so this entry ends at %s.',
            $keptUntil,
        );
    }
}
