<?php

namespace App\Http\Controllers\Concerns;

use App\Http\Resources\TimeEntryResource;
use App\Models\Employee;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Services\SettingsService;
use App\Services\TimerService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The one shape "what is my timer doing" is answered in.
 *
 * It is built here and sent from three places — the Time page's props, the persistent bar's
 * `GET /employee/time/current`, and the reply to every start / pause / resume / stop. One
 * builder, so the bar and the page cannot hold different ideas of whether a timer is running;
 * the same discipline `BuildsDiscussionPayload` keeps for a thread.
 *
 * **It is also the reconciliation channel.** The client's `localStorage` is a cache of intent;
 * this is the truth. Whenever a response carries this block the widget throws away its own idea
 * of the session and takes this one — which is how a browser learns that the watchdog stopped
 * its timer at 13:04 while it was asleep.
 *
 * `server_time` rides along for the same reason: the counter ticks against the SERVER's clock
 * plus the seconds since the payload arrived, so a laptop whose clock is twenty minutes out does
 * not display twenty minutes of work nobody did.
 */
trait BuildsTimerState
{
    /**
     * @return array<string, mixed>
     */
    private function timerState(Request $request, Employee $employee): array
    {
        $timer = app(TimerService::class);
        $settings = app(SettingsService::class);

        $running = $timer->current($employee);
        $today = Carbon::today();

        return [
            'server_time' => Carbon::now()->toIso8601String(),

            'running' => $running === null
                ? null
                : (new TimeEntryResource($running))->resolve($request),

            'today' => [
                'date' => $today->toDateString(),
                // Banked, finished and approved. The running entry is NOT in here — the widget
                // adds its own live elapsed on top, so the number never claims a session has a
                // length it does not have yet.
                'counted_seconds' => $timer->countedSecondsOn($employee, $today),
                // Finished but unsigned. Shown beside the total in words, never folded into it.
                'pending_seconds' => $timer->pendingSecondsOn($employee, $today),
                'target_seconds' => $timer->targetSecondsFor($employee),
            ],

            // The client pings once a minute (Part D §7). Sent rather than hard-coded in Vue so
            // the cadence and the rule that punishes missing it come from the same place.
            'heartbeat_seconds' => 60,
            // What happens if it stops pinging, in the widget's own words. An employee who is
            // told "your timer stops after 5 minutes offline" is not surprised by a flag.
            'heartbeat_timeout_minutes' => (int) $settings->get('heartbeat_timeout_minutes'),
            'manual_time_requires_approval' => (bool) $settings->get('manual_time_requires_approval'),
        ];
    }

    /**
     * The tasks this person may time against: the ones assigned to them that are still open.
     *
     * `Task::visibleTo()` is the scope, so the picker can only ever offer what the endpoint
     * would accept — and a task they are not assigned to is absent here for exactly the reason
     * it answers 404 there.
     *
     * @return list<array{id: int, title: string, project: string|null}>
     */
    private function timeableTasks(Request $request): array
    {
        return Task::query()
            ->visibleTo($request->user())
            ->notArchived()
            ->open()
            ->with('project:id,name')
            ->orderBy('title')
            ->limit(200)
            ->get(['id', 'title', 'project_id'])
            ->map(fn (Task $task): array => [
                'id' => (int) $task->id,
                'title' => (string) $task->title,
                'project' => $task->project?->name,
            ])
            ->values()
            ->all();
    }

    /**
     * Resolve an entry the requester is allowed to know exists.
     *
     * Through `visibleTo()` and `firstOrFail()`, so another employee's entry is **404** and not
     * 403 — a 403 would confirm the record is there, which Part C §1 forbids. Whether they may
     * CHANGE the one they can see is the policy's question, asked separately by the caller.
     */
    private function visibleEntry(Request $request, TimeEntry $entry): TimeEntry
    {
        return TimeEntry::query()
            ->visibleTo($request->user())
            ->with(['task:id,title,project_id', 'project:id,name'])
            ->whereKey($entry->getKey())
            ->firstOrFail();
    }
}
