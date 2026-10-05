<?php

namespace App\Services;

use App\Models\ActivitySample;
use App\Models\IdleDecision;
use App\Models\TimeEntry;
use App\Support\ActivityState;
use App\Support\AuditEvent;
use App\Support\IdleDecisionKind;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The server's auto-pause (docs/extension-api.md §6).
 *
 * When the latest `idle_pause_minutes` stored minutes of a running entry are consecutive and all
 * `idle`, and nobody has decided about that stretch, the entry is paused AT the stretch's first
 * minute and the stretch is recorded as `auto_pause`. The prompt's answer arrives later through
 * `idle-decision`.
 */
class IdleRule
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly TimerService $timer,
        private readonly AuditLogger $audit,
    ) {}

    public function apply(TimeEntry $entry, Carbon $now): bool
    {
        if (! $entry->isRunning()) {
            return false;
        }

        $minutes = max(1, (int) $this->settings->get('idle_pause_minutes'));

        $latest = ActivitySample::query()
            ->where('time_entry_id', $entry->getKey())
            ->orderByDesc('minute_at')
            ->limit($minutes)
            ->get(['minute_at', 'state']);

        if ($latest->count() < $minutes) {
            return false;
        }

        $previous = null;

        foreach ($latest as $sample) {
            if ($sample->state !== ActivityState::Idle) {
                return false;
            }

            if ($previous !== null && ! $sample->minute_at->copy()->addMinute()->equalTo($previous)) {
                return false;
            }

            $previous = $sample->minute_at;
        }

        $idleStart = $latest->last()->minute_at->copy()->setTimezone((string) config('app.timezone'));

        $decided = IdleDecision::query()
            ->where('time_entry_id', $entry->getKey())
            ->where('idle_from', $idleStart)
            ->exists();

        if ($decided) {
            return false;
        }

        return DB::transaction(function () use ($entry, $idleStart, $now): bool {
            $this->timer->pause($entry, $idleStart);

            $entry->forceFill([
                'idle_pending_from' => $idleStart,
                'idle_auto_paused_at' => $now,
            ])->save();

            IdleDecision::query()->create([
                'time_entry_id' => $entry->getKey(),
                'idle_from' => $idleStart,
                'idle_to' => $now,
                'decision' => IdleDecisionKind::AutoPause,
                'discarded_seconds' => 0,
                'source' => 'server',
                'decided_at' => $now,
            ]);

            $this->audit->record(
                AuditEvent::TimerIdleDecision,
                $entry,
                null,
                ['decision' => IdleDecisionKind::AutoPause->value, 'idle_from' => $idleStart->toIso8601String()],
            );

            return true;
        });
    }
}
