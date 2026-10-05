<?php

namespace App\Console\Commands;

use App\Services\SettingsService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Daily at 03:30 (docs/extension-api.md §8): the per-minute activity rows older than
 * `settings.activity_retention_days` go. The rollup columns on `time_entries` stay — they are
 * what is kept once the minutes are gone.
 */
#[Signature('hq:prune-activity')]
#[Description('Delete activity samples and sites older than the retention setting')]
class PruneActivity extends Command
{
    private const CHUNK = 5000;

    public function handle(SettingsService $settings): int
    {
        $days = max(1, (int) $settings->get('activity_retention_days'));
        $cutoff = Carbon::now()->subDays($days);

        $samples = $this->prune('activity_samples', $cutoff);
        $sites = $this->prune('activity_sites', $cutoff);

        $this->info(sprintf('Pruned %d activity samples and %d activity sites.', $samples, $sites));

        return self::SUCCESS;
    }

    private function prune(string $table, Carbon $cutoff): int
    {
        $total = 0;

        do {
            $ids = DB::table($table)
                ->where('minute_at', '<', $cutoff)
                ->orderBy('id')
                ->limit(self::CHUNK)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $total += DB::table($table)->whereIn('id', $ids)->delete();
        } while ($ids->count() === self::CHUNK);

        return $total;
    }
}
