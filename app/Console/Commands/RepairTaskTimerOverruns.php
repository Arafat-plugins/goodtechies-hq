<?php

namespace App\Console\Commands;

use App\Services\TimerService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Brief 028's data repair: re-end every task-timer BREAKDOWN row that was closed long after its
 * last heartbeat at that heartbeat, and recompute the task totals it fed.
 *
 * The rules are `TimerService::repairOverruns()`'s — breakdown rows only (`counts_toward_hours =
 * false`, so no hours, attendance or pay figure can move), unedited timer rows only, and
 * idempotent. `start-hq.bat` runs it on every start, which is safe for that reason: once the
 * rows are right it finds nothing.
 */
#[Signature('hq:repair-task-timer-overruns')]
#[Description('End task-timer breakdown entries that were closed long after their last heartbeat at that heartbeat')]
class RepairTaskTimerOverruns extends Command
{
    public function handle(TimerService $timer): int
    {
        $repaired = $timer->repairOverruns();

        if ($repaired === []) {
            $this->info('No task-timer entries needed repair.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%d task-timer entr%s re-ended at the last heartbeat: #%s.',
            count($repaired),
            count($repaired) === 1 ? 'y' : 'ies',
            implode(', #', $repaired),
        ));

        return self::SUCCESS;
    }
}
