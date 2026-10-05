<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\TimerStateException;
use App\Http\Controllers\Concerns\BuildsTimerState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Extension\TimerEntryRequest;
use App\Http\Requests\Time\StartTimerRequest;
use App\Models\Employee;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Services\TimerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The timer extension's state and its four verbs (docs/extension-api.md §2).
 *
 * New entry points into the same `TimerService` the web timer uses (flow F3), answering the
 * shared `timerState()` block on every success and the §3 envelope on every refusal.
 */
class TimerController extends Controller
{
    use BuildsTimerState;

    public function __construct(private readonly TimerService $timer) {}

    public function state(Request $request): JsonResponse
    {
        return response()->json($this->timerState($request, $this->employee($request)));
    }

    public function tasks(Request $request): JsonResponse
    {
        return response()->json(['tasks' => $this->timeableTasks($request)]);
    }

    public function start(StartTimerRequest $request): JsonResponse
    {
        $employee = $this->employee($request);

        $task = Task::query()
            ->visibleTo($request->user())
            ->notArchived()
            ->whereKey($request->taskId())
            ->firstOrFail();

        try {
            $entry = $this->timer->start(
                $employee,
                $task,
                $request->clientUuid(),
                $request->startedAt(),
                countsTowardHours: true,
            );
        } catch (TimerStateException $e) {
            return $this->conflict($request, $employee, $e);
        }

        $entry->forceFill(['activity_source' => 'extension'])->save();

        return response()->json($this->timerState($request, $employee));
    }

    public function pause(TimerEntryRequest $request): JsonResponse
    {
        return $this->onEntry($request, fn (TimeEntry $entry) => $this->timer->pause($entry));
    }

    public function resume(TimerEntryRequest $request): JsonResponse
    {
        return $this->onEntry($request, fn (TimeEntry $entry) => $this->timer->resume($entry));
    }

    public function stop(TimerEntryRequest $request): JsonResponse
    {
        return $this->onEntry($request, fn (TimeEntry $entry) => $this->timer->stop($entry));
    }

    private function onEntry(TimerEntryRequest $request, callable $action): JsonResponse
    {
        $employee = $this->employee($request);

        $entry = TimeEntry::query()
            ->where('employee_id', $employee->getKey())
            ->whereKey($request->timeEntryId())
            ->first();

        if ($entry === null) {
            return response()->json([
                'error' => 'entry_not_found',
                'message' => 'That timer session was not found.',
            ], 404);
        }

        try {
            $action($entry);
        } catch (TimerStateException $e) {
            return $this->conflict($request, $employee, $e);
        }

        return response()->json($this->timerState($request, $employee));
    }

    private function conflict(Request $request, Employee $employee, TimerStateException $e): JsonResponse
    {
        $code = $e->getMessage() === TimerStateException::notPaused()->getMessage()
            ? 'entry_not_paused'
            : 'entry_not_running';

        return response()->json([
            'error' => $code,
            'message' => $e->getMessage(),
            'state' => $this->timerState($request, $employee),
        ], 409);
    }

    private function employee(Request $request): Employee
    {
        $employee = $request->user()?->employee;

        abort_if($employee === null, 403);

        return $employee;
    }
}
