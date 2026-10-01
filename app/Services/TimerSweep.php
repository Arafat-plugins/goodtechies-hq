<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The watchdog's two rules, run by the application itself — no scheduler required (brief 028).
 *
 * `hq:timer-watchdog` is a schedule entry, and a schedule only runs where something runs
 * `schedule:run` / `schedule:work`. The Windows start script ran no scheduler, so a timer whose
 * PC was switched off was never stopped and kept counting for eighteen hours. This runs the same
 * `TimerService::stopAbandoned()` + `pauseOverlongSessions()`, in the watchdog's order, from the
 * requests people are making anyway (`SweepAbandonedTimers`).
 *
 * **At most once a minute, app-wide**: `Cache::add` is atomic, so of every request in a minute
 * exactly one wins the key and sweeps; the rest pay one cache round trip and no SQL. The
 * winner pays one `exists` statement when nothing is due (`TimerService::sweep()`). The
 * scheduled watchdog keeps running where cron exists — both are idempotent, a second sweep in
 * the same minute finds nothing.
 *
 * A sweep that fails is reported and never breaks the request that happened to carry it.
 */
class TimerSweep
{
    public const CACHE_KEY = 'timer-sweep';

    public const EVERY_SECONDS = 60;

    public function __construct(private readonly TimerService $timer) {}

    /**
     * Sweep if nobody has in the last minute. Returns whether this call swept.
     */
    public function runIfDue(): bool
    {
        try {
            if (! Cache::add(self::CACHE_KEY, true, self::EVERY_SECONDS)) {
                return false;
            }

            $this->timer->sweep();
        } catch (Throwable $e) {
            report($e);

            return false;
        }

        return true;
    }
}
