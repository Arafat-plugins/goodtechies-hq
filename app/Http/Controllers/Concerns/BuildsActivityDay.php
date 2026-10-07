<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Employee;
use App\Models\TimeEntry;
use App\Services\ActivityService;
use App\Support\ActivityState;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * One person's day of activity: each timer session with its minutes, the day's totals, and the
 * website domains the time was spent on (docs/extension-api.md §5 and §8).
 *
 * Built once and read by two pages — the employee's own "My activity" and the Admin's activity
 * day — so the two cannot disagree about a minute. It carries domains and states only: never a
 * URL, a page title, a score, a rank or a category.
 */
trait BuildsActivityDay
{
    /** Polish 031: websites under five minutes in a day are not listed. */
    private const SITE_MIN_SECONDS = 300;

    /**
     * @return array<string, mixed>
     */
    private function activityDay(Employee $employee, Carbon $date): array
    {
        $activity = app(ActivityService::class);

        $entries = TimeEntry::query()
            ->where('employee_id', $employee->getKey())
            ->whereDate('work_date', $date->toDateString())
            ->with(['task:id,title', 'project:id,name'])
            ->orderBy('started_at')
            ->get();

        $summary = [
            'tracked_seconds' => 0,
            'active_minutes' => 0,
            'media_minutes' => 0,
            'call_minutes' => 0,
            'idle_minutes' => 0,
            'idle_percent' => 0,
            'discarded_seconds' => 0,
        ];

        $sessions = $entries->map(function (TimeEntry $entry) use ($activity, &$summary): array {
            $minutes = array_map(fn (array $minute): array => [
                'minute' => $minute['minute'],
                'state' => $minute['state'],
                'call_source' => $minute['call_source'],
                'host' => $minute['host'],
            ], $activity->timeline($entry));

            // The rollup columns are written on pause and stop (§8), so a running entry is
            // counted from its samples as they stand.
            $counts = $entry->isRunning()
                ? array_count_values(array_column($minutes, 'state'))
                : [
                    ActivityState::Active->value => (int) $entry->active_minutes,
                    ActivityState::Media->value => (int) $entry->media_minutes,
                    ActivityState::Call->value => (int) $entry->call_minutes,
                    ActivityState::Idle->value => (int) $entry->idle_minutes,
                ];

            $elapsed = $entry->elapsedSeconds();

            $summary['tracked_seconds'] += $elapsed;
            $summary['active_minutes'] += (int) ($counts[ActivityState::Active->value] ?? 0);
            $summary['media_minutes'] += (int) ($counts[ActivityState::Media->value] ?? 0);
            $summary['call_minutes'] += (int) ($counts[ActivityState::Call->value] ?? 0);
            $summary['idle_minutes'] += (int) ($counts[ActivityState::Idle->value] ?? 0);
            $summary['discarded_seconds'] += (int) $entry->discarded_seconds;

            return [
                'id' => (int) $entry->getKey(),
                'task' => $entry->task === null ? null : ['id' => (int) $entry->task->id, 'name' => (string) $entry->task->title],
                'project' => $entry->project === null ? null : ['id' => (int) $entry->project->id, 'name' => (string) $entry->project->name],
                'started_at' => $entry->started_at->toIso8601String(),
                'ended_at' => $entry->ended_at?->toIso8601String(),
                'state' => $entry->stateKey(),
                'state_label' => match ($entry->stateKey()) {
                    'running' => 'Running',
                    'paused' => 'Paused',
                    default => 'Stopped',
                },
                'elapsed_seconds' => $elapsed,
                'activity_source' => $entry->activity_source,
                'has_activity_data' => $minutes !== [],
                'minutes' => $minutes,
            ];
        })->values()->all();

        $sampled = $summary['active_minutes'] + $summary['media_minutes'] + $summary['call_minutes'] + $summary['idle_minutes'];
        $summary['idle_percent'] = $sampled === 0 ? 0 : (int) round($summary['idle_minutes'] / $sampled * 100);

        // Polish 031: a website with less than five minutes in the day is noise, not work —
        // it is left out of the list (and of the shares), and the page says how many were.
        $allSites = $activity->sitesFor($employee, $date);
        $sites = array_values(array_filter($allSites, fn (array $site): bool => $site['seconds'] >= self::SITE_MIN_SECONDS));
        $hidden = array_values(array_filter($allSites, fn (array $site): bool => $site['seconds'] < self::SITE_MIN_SECONDS));
        $siteSeconds = array_sum(array_column($sites, 'seconds'));

        return [
            'date' => [
                'value' => $date->toDateString(),
                'label' => $date->isoFormat('dddd, D MMMM YYYY'),
                'previous' => $date->copy()->subDay()->toDateString(),
                'next' => $date->copy()->addDay()->toDateString(),
                'today' => Carbon::today()->toDateString(),
            ],
            'employee' => [
                'id' => (int) $employee->getKey(),
                'name' => $employee->user?->name ?? 'Unknown',
            ],
            'sessions' => $sessions,
            'summary' => $summary,
            'sites' => array_map(fn (array $site): array => [
                'kind' => $site['kind'],
                'host' => $site['host'],
                'seconds' => $site['seconds'],
                'share' => $siteSeconds === 0 ? 0 : (int) round($site['seconds'] / $siteSeconds * 100),
            ], $sites),
            'sites_hidden' => [
                'count' => count($hidden),
                'seconds' => (int) array_sum(array_column($hidden, 'seconds')),
                'min_minutes' => intdiv(self::SITE_MIN_SECONDS, 60),
            ],
            // Polish 031: the month around this day, tracked time per date, for the calendar.
            'month' => $this->activityMonth($employee, $date),
            'legend' => [
                ['state' => ActivityState::Active->value, 'label' => 'Active'],
                ['state' => ActivityState::Media->value, 'label' => 'Video or audio'],
                ['state' => ActivityState::Call->value, 'label' => 'Call'],
                ['state' => ActivityState::Idle->value, 'label' => 'Idle'],
            ],
        ];
    }

    /**
     * The day asked for in `?date=`. A value that is not a real `Y-m-d` date falls back to today,
     * the same rule the Admin Time page keeps.
     */
    /**
     * Every day of the month containing `$date`, with the time tracked on it.
     *
     * @return array{label: string, previous: string, next: string, days: list<array{date: string, seconds: int}>}
     */
    private function activityMonth(Employee $employee, Carbon $date): array
    {
        $start = $date->copy()->startOfMonth();
        $end = $date->copy()->endOfMonth();

        $seconds = [];

        TimeEntry::query()
            ->where('employee_id', $employee->getKey())
            ->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->each(function (TimeEntry $entry) use (&$seconds): void {
                $key = Carbon::parse($entry->work_date)->toDateString();
                $seconds[$key] = ($seconds[$key] ?? 0) + $entry->elapsedSeconds();
            });

        $days = [];

        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            $days[] = ['date' => $day->toDateString(), 'seconds' => (int) ($seconds[$day->toDateString()] ?? 0)];
        }

        return [
            'label' => $start->isoFormat('MMMM YYYY'),
            'previous' => $start->copy()->subMonth()->toDateString(),
            'next' => $start->copy()->addMonth()->toDateString(),
            'days' => $days,
        ];
    }

    private function activityDate(Request $request): Carbon
    {
        $value = trim((string) $request->query('date', ''));

        if ($value === '') {
            return Carbon::today();
        }

        try {
            $date = Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
        } catch (\Throwable) {
            return Carbon::today();
        }

        return $date->toDateString() === $value ? $date : Carbon::today();
    }
}
