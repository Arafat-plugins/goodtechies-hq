<?php

namespace App\Services;

use App\Models\ActivitySample;
use App\Models\ActivitySite;
use App\Models\Employee;
use App\Models\TimeEntry;
use App\Support\ActivityState;
use App\Support\SiteKind;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Minutes of activity on a time entry, and the hosts they were spent on
 * (docs/extension-api.md §5 and §8).
 *
 * Idempotent by construction: `activity_samples` is unique on `(time_entry_id, minute_at)`, so a
 * replayed sample is counted as `repeated` and never stored twice. Ownership (decision 11-04): an
 * `extension` sample overwrites a `web` one for the same minute; a `web` sample never overwrites
 * an `extension` one.
 */
class ActivityService
{
    public const SOURCE_EXTENSION = 'extension';

    public const SOURCE_WEB = 'web';

    /**
     * @param  list<array{minute: string, state: string, call_source?: string|null, sites?: list<array{kind: string, host: string, seconds: int}>}>  $samples
     * @return array{accepted: int, repeated: int, rejected: list<array{minute: string, reason: string}>}
     */
    public function storeSamples(TimeEntry $entry, array $samples, string $source): array
    {
        $zone = (string) config('app.timezone');
        $now = Carbon::now($zone);
        $start = $entry->started_at->copy()->setTimezone($zone)->startOfMinute();

        $accepted = 0;
        $repeated = 0;
        $rejected = [];

        DB::transaction(function () use ($entry, $samples, $source, $zone, $now, $start, &$accepted, &$repeated, &$rejected): void {
            foreach ($samples as $sample) {
                $minute = Carbon::parse((string) $sample['minute'])->setTimezone($zone)->startOfMinute();

                if ($minute->lessThan($start)) {
                    $rejected[] = ['minute' => $minute->toIso8601String(), 'reason' => 'before_start'];

                    continue;
                }

                if ($minute->greaterThan($now)) {
                    $rejected[] = ['minute' => $minute->toIso8601String(), 'reason' => 'in_future'];

                    continue;
                }

                /** @var ActivitySample|null $existing */
                $existing = ActivitySample::query()
                    ->where('time_entry_id', $entry->getKey())
                    ->where('minute_at', $minute)
                    ->lockForUpdate()
                    ->first();

                $state = ActivityState::from((string) $sample['state']);
                $values = [
                    'state' => $state,
                    'source' => $source,
                    'call_source' => $state === ActivityState::Call ? ($sample['call_source'] ?? null) : null,
                ];

                if ($existing === null) {
                    ActivitySample::query()->create(['time_entry_id' => $entry->getKey(), 'minute_at' => $minute] + $values);
                } elseif ($source === self::SOURCE_EXTENSION && $existing->source === self::SOURCE_WEB) {
                    $existing->forceFill($values)->save();
                } else {
                    $repeated++;

                    continue;
                }

                $this->replaceSites($entry, $minute, $sample['sites'] ?? []);
                $accepted++;
            }

            if ($accepted > 0) {
                $activitySource = $entry->activity_source === self::SOURCE_EXTENSION || $source === self::SOURCE_EXTENSION
                    ? self::SOURCE_EXTENSION
                    : self::SOURCE_WEB;

                if ($entry->activity_source !== $activitySource) {
                    $entry->forceFill(['activity_source' => $activitySource])->saveQuietly();
                }
            }
        });

        return ['accepted' => $accepted, 'repeated' => $repeated, 'rejected' => $rejected];
    }

    /**
     * The web timer's own heartbeat (§5): one `active` minute with no sites, stored only when
     * the minute has no sample yet.
     */
    public function recordWebMinute(TimeEntry $entry, Carbon $now): void
    {
        // Nothing is stored for a paused or stopped entry (§5).
        if (! $entry->isRunning()) {
            return;
        }

        $minute = $now->copy()->setTimezone((string) config('app.timezone'))->startOfMinute();

        $this->storeSamples($entry, [[
            'minute' => $minute->toIso8601String(),
            'state' => ActivityState::Active->value,
            'sites' => [],
        ]], self::SOURCE_WEB);
    }

    /**
     * Write the four `*_minutes` rollup columns from the entry's samples (§8).
     */
    public function rollup(TimeEntry $entry): void
    {
        $counts = ActivitySample::query()
            ->where('time_entry_id', $entry->getKey())
            ->selectRaw('state, count(*) as minutes')
            ->groupBy('state')
            ->pluck('minutes', 'state');

        $entry->forceFill([
            'active_minutes' => (int) ($counts[ActivityState::Active->value] ?? 0),
            'media_minutes' => (int) ($counts[ActivityState::Media->value] ?? 0),
            'call_minutes' => (int) ($counts[ActivityState::Call->value] ?? 0),
            'idle_minutes' => (int) ($counts[ActivityState::Idle->value] ?? 0),
        ])->saveQuietly();
    }

    /**
     * The samples in `[from, to)` become a manual call (§6, decision `meeting`).
     */
    public function markMeeting(TimeEntry $entry, Carbon $from, Carbon $to): int
    {
        $zone = (string) config('app.timezone');

        return ActivitySample::query()
            ->where('time_entry_id', $entry->getKey())
            ->where('minute_at', '>=', $from->copy()->setTimezone($zone))
            ->where('minute_at', '<', $to->copy()->setTimezone($zone))
            ->update([
                'state' => ActivityState::Call->value,
                'call_source' => 'manual',
                'updated_at' => Carbon::now(),
            ]);
    }

    /**
     * The entry's minutes in order, each with the host it spent the most seconds on.
     *
     * @return list<array{minute: string, state: string, call_source: string|null, host: string|null}>
     */
    public function timeline(TimeEntry $entry): array
    {
        $topHost = [];

        ActivitySite::query()
            ->where('time_entry_id', $entry->getKey())
            ->where('kind', SiteKind::Site->value)
            ->orderBy('minute_at')
            ->orderByDesc('seconds')
            ->orderBy('host')
            ->get(['minute_at', 'host', 'seconds'])
            ->each(function (ActivitySite $site) use (&$topHost): void {
                $key = $site->minute_at->getTimestamp();
                $topHost[$key] ??= $site->host;
            });

        return ActivitySample::query()
            ->where('time_entry_id', $entry->getKey())
            ->orderBy('minute_at')
            ->get(['minute_at', 'state', 'call_source'])
            ->map(fn (ActivitySample $sample): array => [
                'minute' => $sample->minute_at->toIso8601String(),
                'state' => $sample->state->value,
                'call_source' => $sample->call_source,
                'host' => $topHost[$sample->minute_at->getTimestamp()] ?? null,
            ])
            ->values()
            ->all();
    }

    /**
     * Seconds per host (and per non-site kind) across one employee's entries on one day.
     *
     * @return list<array{kind: string, host: string, seconds: int}>
     */
    public function sitesFor(Employee $employee, Carbon $day): array
    {
        return ActivitySite::query()
            ->join('time_entries', 'time_entries.id', '=', 'activity_sites.time_entry_id')
            ->where('time_entries.employee_id', $employee->getKey())
            ->whereDate('time_entries.work_date', $day->copy()->setTimezone((string) config('app.timezone'))->toDateString())
            ->groupBy('activity_sites.kind', 'activity_sites.host')
            ->selectRaw('activity_sites.kind as kind, activity_sites.host as host, sum(activity_sites.seconds) as seconds')
            ->orderByDesc('seconds')
            ->orderBy('host')
            ->toBase()
            ->get()
            ->map(fn (object $row): array => [
                'kind' => (string) $row->kind,
                'host' => (string) $row->host,
                'seconds' => (int) $row->seconds,
            ])
            ->values()
            ->all();
    }

    /**
     * A minute's sites are replaced, never merged, when its sample is accepted.
     *
     * @param  list<array{kind: string, host: string, seconds: int}>  $sites
     */
    private function replaceSites(TimeEntry $entry, Carbon $minute, array $sites): void
    {
        ActivitySite::query()
            ->where('time_entry_id', $entry->getKey())
            ->where('minute_at', $minute)
            ->delete();

        $rows = [];

        foreach ($sites as $site) {
            $kind = (string) $site['kind'];
            $host = $kind === SiteKind::Site->value ? (string) $site['host'] : '';
            $key = $kind."\0".$host;

            $rows[$key] ??= ['kind' => $kind, 'host' => $host, 'seconds' => 0];
            $rows[$key]['seconds'] = min(60, $rows[$key]['seconds'] + (int) $site['seconds']);
        }

        $stamp = Carbon::now();

        if ($rows !== []) {
            ActivitySite::query()->insert(array_map(fn (array $row): array => [
                'time_entry_id' => $entry->getKey(),
                'minute_at' => $minute,
                'kind' => $row['kind'],
                'host' => $row['host'],
                'seconds' => $row['seconds'],
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ], array_values($rows)));
        }
    }
}
