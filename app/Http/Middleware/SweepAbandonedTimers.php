<?php

namespace App\Http\Middleware;

use App\Services\TimerSweep;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs the timer sweep (`TimerSweep`, at most once a minute app-wide) on a signed-in request,
 * BEFORE the controller — so the board, the drawer, "Working now", a clock-out or a ▶ that
 * triggers it already reads the stopped entry and the corrected `tracked_seconds`, rather than
 * showing a dead timer one last time (brief 028).
 *
 * Guests never trigger it: the login page is not a reason to write to `time_entries`.
 */
class SweepAbandonedTimers
{
    public function __construct(private readonly TimerSweep $sweep) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() !== null) {
            $this->sweep->runIfDue();
        }

        return $next($request);
    }
}
