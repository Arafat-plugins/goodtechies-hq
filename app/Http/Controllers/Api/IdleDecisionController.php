<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\TimerStateException;
use App\Http\Controllers\Concerns\BuildsTimerState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Extension\IdleDecisionRequest;
use App\Models\Employee;
use App\Models\IdleDecision;
use App\Models\TimeEntry;
use App\Services\ActivityService;
use App\Services\AuditLogger;
use App\Services\TimerService;
use App\Support\AuditEvent;
use App\Support\IdleDecisionKind;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * An answer to the idle prompt (docs/extension-api.md §6). Idempotent on
 * `(time_entry_id, idle_from)`: a repeat returns the stored decision and changes nothing.
 */
class IdleDecisionController extends Controller
{
    use BuildsTimerState;

    public function __construct(
        private readonly TimerService $timer,
        private readonly ActivityService $activity,
        private readonly AuditLogger $audit,
    ) {}

    public function __invoke(IdleDecisionRequest $request): JsonResponse
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

        $from = $request->idleFrom();
        $to = $request->idleTo();
        $kind = IdleDecisionKind::from($request->decision());

        /** @var IdleDecision|null $existing */
        $existing = IdleDecision::query()
            ->where('time_entry_id', $entry->getKey())
            ->where('idle_from', $from)
            ->first();

        // The server's own auto-pause is not an answer: the person's decision about the same
        // stretch still has to land, on that same row (the table is unique on the stretch).
        if ($existing !== null && $existing->decision !== IdleDecisionKind::AutoPause) {
            return response()->json($this->timerState($request, $employee) + [
                'decision' => [
                    'idle_from' => $existing->idle_from->toIso8601String(),
                    'decision' => $existing->decision->value,
                    'discarded_seconds' => (int) $existing->discarded_seconds,
                    'repeated' => true,
                ],
            ]);
        }

        $discarded = $kind === IdleDecisionKind::Discard ? (int) $from->diffInSeconds($to) : 0;

        try {
            DB::transaction(function () use ($request, $entry, $existing, $from, $to, $kind, $discarded): void {
                match ($kind) {
                    IdleDecisionKind::Discard => $entry->forceFill([
                        'paused_seconds' => (int) $entry->paused_seconds + $discarded,
                        'discarded_seconds' => (int) $entry->discarded_seconds + $discarded,
                    ])->save(),
                    IdleDecisionKind::Meeting => $this->activity->markMeeting($entry, $from, $to),
                    IdleDecisionKind::Stop => $this->timer->stop($entry),
                    default => null,
                };

                if ($kind !== IdleDecisionKind::Stop && $entry->idle_pending_from !== null) {
                    $entry->forceFill(['idle_pending_from' => null, 'idle_auto_paused_at' => null])->save();
                }

                $values = [
                    'idle_to' => $to,
                    'decision' => $kind,
                    'discarded_seconds' => $discarded,
                    'source' => 'extension',
                    'decided_at' => Carbon::now(),
                ];

                if ($existing !== null) {
                    $existing->forceFill($values)->save();
                } else {
                    IdleDecision::query()->create(['time_entry_id' => $entry->getKey(), 'idle_from' => $from] + $values);
                }

                $this->audit->record(
                    AuditEvent::TimerIdleDecision,
                    $entry,
                    null,
                    [
                        'decision' => $kind->value,
                        'idle_from' => $from->toIso8601String(),
                        'idle_to' => $to->toIso8601String(),
                        'discarded_seconds' => $discarded,
                    ],
                    $request->user(),
                );
            });
        } catch (TimerStateException $e) {
            return response()->json([
                'error' => 'entry_not_running',
                'message' => $e->getMessage(),
                'state' => $this->timerState($request, $employee),
            ], 409);
        }

        return response()->json($this->timerState($request, $employee) + [
            'decision' => [
                'idle_from' => $from->toIso8601String(),
                'decision' => $kind->value,
                'discarded_seconds' => $discarded,
                'repeated' => false,
            ],
        ]);
    }

    private function employee(Request $request): Employee
    {
        $employee = $request->user()?->employee;

        abort_if($employee === null, 403);

        return $employee;
    }
}
