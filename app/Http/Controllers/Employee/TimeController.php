<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Concerns\BuildsTimerState;
use App\Http\Controllers\Controller;
use App\Http\Resources\TimeEntryResource;
use App\Models\Employee;
use App\Models\TimeEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The remote employee's Time page, and the persistent bar's state endpoint.
 *
 * The page is *entries by day* — what did I work on, when, and which rows need a second look.
 * There is no ranking on it, no rate, no percentage of a target dressed up as a verdict: Part H
 * forbids productivity scoring, and `tests/Feature/Employee/TimeScreenTest.php` greps the built
 * payload and the Vue sources for "score" and "productivity" to keep it that way.
 *
 * Thin, like every controller here. Every rule is `TimerService`'s and every "may I" is
 * `TimeEntryPolicy`'s.
 */
class TimeController extends Controller
{
    use BuildsTimerState;

    /** How far back the page looks by default. A fortnight covers "what did I do last week". */
    private const DEFAULT_DAYS = 14;

    /** The furthest back the range may reach, so one URL cannot ask for every entry ever. */
    private const MAX_DAYS = 92;

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', TimeEntry::class);

        $employee = $this->employee($request);
        [$from, $to] = $this->range($request);

        $entries = TimeEntry::query()
            ->visibleTo($request->user())
            ->where('employee_id', $employee->getKey())
            ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])
            ->with(['task:id,title,project_id', 'project:id,name'])
            ->orderByDesc('work_date')
            ->orderByDesc('started_at')
            ->get();

        return Inertia::render('Employee/Time/Index', [
            'timer' => $this->timerState($request, $employee),
            'tasks' => $this->timeableTasks($request),
            'days' => $this->byDay($request, $entries),
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            // Counted once here rather than derived in Vue from a list the range may have cut
            // off: "three entries need a look" has to be true of the whole record, not of the
            // fortnight that happens to be on screen.
            'flagged_count' => TimeEntry::query()
                ->where('employee_id', $employee->getKey())
                ->flagged()
                ->count(),
        ]);
    }

    /**
     * The bar's reconciliation call — and the answer to "did my timer survive the night".
     *
     * JSON rather than an Inertia visit, because it is polled beside whatever page the person is
     * actually on: a visit here would re-render that page every minute and take its scroll,
     * its open drawer and its half-typed comment with it.
     */
    public function current(Request $request): JsonResponse
    {
        Gate::authorize('track', TimeEntry::class);

        return response()->json([
            ...$this->timerState($request, $this->employee($request)),
            // Only here, and not on the once-a-minute heartbeat: the picker's options are a
            // list that barely changes, and sending two hundred of them every minute beside a
            // state block that is four numbers would be the wrong shape of request entirely.
            'tasks' => $this->timeableTasks($request),
        ]);
    }

    /**
     * Group the range into days, newest first, each with its own totals.
     *
     * The totals are summed here from the same rows the day lists, so a day's heading and its
     * rows cannot disagree — and `counted` is the one predicate the whole phase uses for "does
     * this count", asked here exactly as `TimerService` asks it.
     *
     * @param  Collection<int, TimeEntry>  $entries
     * @return list<array<string, mixed>>
     */
    private function byDay(Request $request, $entries): array
    {
        return $entries
            ->groupBy(fn (TimeEntry $entry): string => $entry->work_date->toDateString())
            ->map(function ($rows, string $date) use ($request): array {
                $counted = $rows->filter(fn (TimeEntry $entry): bool => $entry->isStopped() && $entry->counts());
                $pending = $rows->filter(fn (TimeEntry $entry): bool => $entry->awaitsApproval());

                return [
                    'date' => $date,
                    'label' => Carbon::parse($date)->isoFormat('ddd D MMM'),
                    'counted_seconds' => (int) $counted->sum('duration_seconds'),
                    'pending_seconds' => (int) $pending->sum('duration_seconds'),
                    'flagged_count' => $rows->filter(fn (TimeEntry $entry): bool => $entry->isFlagged())->count(),
                    'entries' => $rows
                        ->map(fn (TimeEntry $entry): array => (new TimeEntryResource($entry))->resolve($request))
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * The window the page is showing. Read straight off the query string rather than through a
     * Form Request, so a stale bookmark with a nonsense date shows the default fortnight instead
     * of an error page — and so the route answers 200 to anybody allowed to open it.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function range(Request $request): array
    {
        $to = $this->date($request->query('to')) ?? Carbon::today();
        $from = $this->date($request->query('from')) ?? $to->copy()->subDays(self::DEFAULT_DAYS - 1);

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to->copy(), $from->copy()];
        }

        if ($from->diffInDays($to) > self::MAX_DAYS) {
            $from = $to->copy()->subDays(self::MAX_DAYS);
        }

        return [$from->startOfDay(), $to->startOfDay()];
    }

    private function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The signed-in person's employee record. The policy has already established that they have
     * one and that it tracks by timer, so this cannot be null by the time it is reached.
     */
    private function employee(Request $request): Employee
    {
        $employee = $request->user()?->employee;

        abort_if($employee === null, 403);

        return $employee;
    }
}
