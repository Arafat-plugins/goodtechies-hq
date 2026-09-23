<?php

namespace App\Http\Controllers\Employee;

use App\Exceptions\TimerStateException;
use App\Http\Controllers\Concerns\BuildsTimerState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Time\ReplayTimerRequest;
use App\Http\Requests\Time\StartTimerRequest;
use App\Models\Employee;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Services\TimerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Start, pause, resume, stop, heartbeat, replay — the six things the widget does.
 *
 * ## Why pause, resume and stop take no id
 *
 * Because there is nothing to identify. An employee has at most one open entry, guaranteed by a
 * partial unique index, so "pause my timer" has exactly one referent and the server resolves it.
 * An id in the URL would be a second way to say the same thing and a chance to say it about
 * somebody else's afternoon.
 *
 * ## Why two response shapes
 *
 * The four verbs come back as an ordinary Inertia redirect with a flash, like every other write
 * in this codebase — the widget posts with `preserveState`, so the page it is sitting on stays
 * where it was.
 *
 * `heartbeat` and `replay` answer JSON instead, and that is not a style choice: they run in the
 * background every minute and on reconnect. An Inertia visit for either would re-render whatever
 * page the person is on, once a minute, taking its scroll position and any open drawer with it.
 *
 * ## The refusals
 *
 * `TimeEntryPolicy::track` is checked in the Form Requests' `authorize()` (which runs before
 * validation) and directly here where there is no Form Request, so an office role gets **403**
 * from all six. A `TimerStateException` — pausing what is already paused — is not a refusal
 * about the person, so it comes back as a flash error, the way `TaskStateException` does.
 */
class TimerController extends Controller
{
    use BuildsTimerState;

    public function __construct(private readonly TimerService $timer) {}

    public function start(StartTimerRequest $request): RedirectResponse
    {
        $employee = $this->employee($request);

        // Resolved through Task::visibleTo(), so starting a timer on a task this person is not
        // assigned to is 404 — the same answer the task itself gives them.
        $task = Task::query()
            ->visibleTo($request->user())
            ->notArchived()
            ->whereKey($request->taskId())
            ->firstOrFail();

        return $this->settle(
            fn () => $this->timer->start($employee, $task, $request->clientUuid(), $request->startedAt()),
            'Timer started.',
        );
    }

    public function pause(Request $request): RedirectResponse
    {
        Gate::authorize('track', TimeEntry::class);

        return $this->onOpenEntry($request, fn (TimeEntry $entry) => $this->timer->pause($entry), 'Timer paused.');
    }

    public function resume(Request $request): RedirectResponse
    {
        Gate::authorize('track', TimeEntry::class);

        return $this->onOpenEntry($request, fn (TimeEntry $entry) => $this->timer->resume($entry), 'Timer running again.');
    }

    public function stop(Request $request): RedirectResponse
    {
        Gate::authorize('track', TimeEntry::class);

        return $this->onOpenEntry($request, fn (TimeEntry $entry) => $this->timer->stop($entry), 'Timer stopped and the time saved.');
    }

    /**
     * The once-a-minute ping, and the channel the browser learns the truth on.
     *
     * A heartbeat for a session the server has already closed is **not an error**. It is news,
     * and the reply carries it: the state block comes back with `running: null`, the widget sees
     * the server has stopped its timer and stops pretending otherwise. That is the whole "server
     * wins" rule, arriving through the one request the client was making anyway.
     */
    public function heartbeat(Request $request): JsonResponse
    {
        Gate::authorize('track', TimeEntry::class);

        $employee = $this->employee($request);
        $entry = $this->timer->current($employee);

        if ($entry !== null) {
            $this->timer->heartbeat($entry);
        }

        return response()->json($this->timerState($request, $employee));
    }

    /**
     * What the browser sends when it comes back from being offline.
     *
     * The batch is believed exactly as far as its heartbeats go. Everything else about how the
     * server treats it is in `TimerService::replay()`, including why replaying it five times
     * cannot add five afternoons.
     */
    public function replay(ReplayTimerRequest $request): JsonResponse
    {
        $employee = $this->employee($request);

        $task = Task::query()
            ->visibleTo($request->user())
            ->whereKey($request->taskId())
            ->firstOrFail();

        try {
            $this->timer->replay(
                $employee,
                $task,
                $request->clientUuid(),
                $request->startedAt(),
                $request->heartbeats(),
                $request->pausedSeconds(),
                $request->stoppedAt(),
            );
        } catch (TimerStateException $e) {
            // The batch could not be accepted as it stands — most often because its uuid is not
            // this employee's. The client still gets the server's state and reconciles to it,
            // which is the only outcome that leaves the two agreeing.
            return response()->json([
                ...$this->timerState($request, $employee),
                'message' => $e->getMessage(),
            ], 409);
        }

        return response()->json($this->timerState($request, $employee));
    }

    /**
     * Run `$action` against this employee's open entry.
     */
    private function onOpenEntry(Request $request, callable $action, string $success): RedirectResponse
    {
        $entry = $this->timer->current($this->employee($request));

        if ($entry === null) {
            return back()->with('error', 'There is no timer going at the moment.');
        }

        return $this->settle(fn () => $action($entry), $success);
    }

    /**
     * A timer write, with the state machine's refusals turned into a sentence rather than a 403.
     *
     * The distinction matters: the person IS allowed to use the timer — that was settled before
     * this ran — the timer is simply not in a state that accepts the move. Telling them they may
     * not track time would be false.
     */
    private function settle(callable $write, string $success): RedirectResponse
    {
        try {
            $write();
        } catch (TimerStateException $e) {
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
