<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\BuildsTimerState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Extension\HeartbeatRequest;
use App\Models\Employee;
use App\Models\TimeEntry;
use App\Services\ActivityService;
use App\Services\IdleRule;
use App\Services\TimerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The extension's heartbeat: a batch of activity minutes for one running entry
 * (docs/extension-api.md §5). Stored idempotently, then the server's idle rule is asked.
 */
class HeartbeatController extends Controller
{
    use BuildsTimerState;

    public function __construct(
        private readonly TimerService $timer,
        private readonly ActivityService $activity,
        private readonly IdleRule $idle,
    ) {}

    public function __invoke(HeartbeatRequest $request): JsonResponse
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

        if (! $entry->isRunning()) {
            return response()->json([
                'error' => 'entry_not_running',
                'message' => 'This timer is not running any more.',
                'state' => $this->timerState($request, $employee),
            ], 409);
        }

        $this->timer->heartbeat($entry);

        $result = $this->activity->storeSamples($entry, $request->samples(), ActivityService::SOURCE_EXTENSION);

        $this->idle->apply($entry->fresh(), Carbon::now());

        return response()->json($this->timerState($request, $employee) + $result);
    }

    private function employee(Request $request): Employee
    {
        $employee = $request->user()?->employee;

        abort_if($employee === null, 403);

        return $employee;
    }
}
