<?php

namespace App\Http\Controllers\Shared;

use App\Exceptions\AttendanceStateException;
use App\Exceptions\TimerStateException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Time\StartTaskTimerRequest;
use App\Models\Employee;
use App\Models\TimeEntry;
use App\Services\SettingsService;
use App\Services\TaskTimerService;
use App\Services\TimerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * ▶ / ⏸ / ⏹ on a task card and in the drawer, for everyone who works tasks (flow F3).
 *
 * Shared rather than one set per surface for the reason the clock routes are: an Admin and
 * Yaseen press the same button for the same reason, and two copies of five routes would be two
 * places for "whose timer is this" to be answered differently.
 *
 * Pause, resume and stop take no id, like the remote widget's: a person has at most one open
 * entry (the partial unique index), so "pause my timer" has one referent and an id in the URL
 * would only be a way to say it about somebody else.
 *
 * The writes answer an Inertia redirect with a flash, like every other write here; the
 * heartbeat answers JSON, because it runs in the background once a minute and an Inertia visit
 * would re-render the page the person is on. A `TimerStateException` / `AttendanceStateException`
 * is the state refusing the move, not the person being refused, so it is a flash, never a 403.
 */
class TaskTimerController extends Controller
{
    public function __construct(
        private readonly TaskTimerService $taskTimer,
        private readonly TimerService $timer,
        private readonly SettingsService $settings,
    ) {}

    public function start(StartTaskTimerRequest $request): RedirectResponse
    {
        $employee = $this->employee($request);

        return $this->settle(
            fn () => $this->taskTimer->start($employee, $request->task(), $request->clientUuid(), $request->clockIn()),
            'Timer started.',
        );
    }

    public function pause(Request $request): RedirectResponse
    {
        Gate::authorize('trackTasks', TimeEntry::class);

        return $this->onOpenEntry($request, fn (TimeEntry $entry) => $this->timer->pause($entry), 'Timer paused.');
    }

    public function resume(Request $request): RedirectResponse
    {
        Gate::authorize('trackTasks', TimeEntry::class);

        return $this->onOpenEntry($request, fn (TimeEntry $entry) => $this->timer->resume($entry), 'Timer running again.');
    }

    public function stop(Request $request): RedirectResponse
    {
        Gate::authorize('trackTasks', TimeEntry::class);

        return $this->onOpenEntry($request, fn (TimeEntry $entry) => $this->timer->stop($entry), 'Timer stopped and the time saved.');
    }

    /**
     * The once-a-minute "still here" for a task timer that is not the remote widget's — the
     * safeguard every timer keeps (heartbeat timeout, Part D §7). The reply says what the server
     * believes, so a timer the watchdog or a clock-out has already stopped is learned about
     * through the request the page was making anyway.
     */
    public function heartbeat(Request $request): JsonResponse
    {
        Gate::authorize('trackTasks', TimeEntry::class);

        $entry = $this->timer->current($this->employee($request));

        if ($entry !== null) {
            $this->timer->heartbeat($entry);
        }

        // Client doc 2026-10-05: a page of theirs is alive, so a closed-tab mark is cancelled.
        app(\App\Services\AttendanceService::class)->stillHere($request->user()?->employee);

        return response()->json([
            'server_time' => Carbon::now()->toIso8601String(),
            'running' => $entry === null ? null : [
                'task_id' => (int) $entry->task_id,
                ...TaskTimerService::present($entry),
            ],
            'heartbeat_seconds' => 60,
            'heartbeat_timeout_minutes' => (int) $this->settings->get('heartbeat_timeout_minutes'),
        ]);
    }

    /**
     * The `pagehide` beacon from the last goodERP tab while a timer runs. It only marks the
     * moment; `TimerService::sweep()` stops the entry AT it once the 30-second grace has passed
     * with no heartbeat (a reload beats at once and cancels it). The beacon carries the CSRF
     * token as the `_token` form field — `sendBeacon` cannot set headers.
     */
    public function leaving(Request $request): Response
    {
        Gate::authorize('trackTasks', TimeEntry::class);

        $this->timer->markLeaving($this->employee($request));

        return response()->noContent();
    }

    private function onOpenEntry(Request $request, callable $action, string $success): RedirectResponse
    {
        $entry = $this->timer->current($this->employee($request));

        if ($entry === null) {
            return back()->with('error', 'There is no timer going at the moment.');
        }

        return $this->settle(fn () => $action($entry), $success);
    }

    private function settle(callable $write, string $success): RedirectResponse
    {
        try {
            $write();
        } catch (TimerStateException|AttendanceStateException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $success);
    }

    private function employee(Request $request): Employee
    {
        $employee = $request->user()?->employee;

        abort_if($employee === null, 403);

        return $employee;
    }
}
