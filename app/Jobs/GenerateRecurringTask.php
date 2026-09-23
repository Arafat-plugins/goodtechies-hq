<?php

namespace App\Jobs;

use App\Models\RecurringTask;
use App\Services\RecurringTaskEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

/**
 * One template, one period, one attempt (master prompt Phase 3: "queue job per template").
 *
 * One job per template rather than one job for the sweep, so that a template whose project has
 * been deleted, or whose rule is unreadable, costs that template's month and not the agency's.
 *
 * ## There is no lock on this job, on purpose
 *
 * `ShouldBeUnique` would put a cache lock in front of it, and a cache lock is an `if` with extra
 * infrastructure: it fails open when Redis blinks, it does not span two boxes' clocks, and it
 * would quietly become the thing everyone believed was preventing duplicates. The guarantee is
 * `tasks_recurring_task_period_unique` and nothing else. A retried job therefore re-runs the
 * whole attempt, finds the instance it created the first time, and logs one duplicate warning —
 * which is the honest record of what happened.
 *
 * The template is carried as an ID rather than as a serialised model for the same reason the
 * engine re-reads the project: by the time the worker picks this up, the template may have been
 * switched off, and a queued copy of the old row would not know.
 */
class GenerateRecurringTask implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $templateId,
        /** The date the sweep ran, as `Y-m-d`. Threaded through so a catch-up run generates the day it meant to. */
        public readonly string $asOf,
    ) {}

    public function handle(RecurringTaskEngine $engine): void
    {
        $template = RecurringTask::find($this->templateId);

        // Deleted between the sweep and the worker. Nothing to attempt and nothing to log —
        // recurring_generation_log cascades with its template, so there would be nowhere to
        // write it even if there were something to say.
        if ($template === null) {
            return;
        }

        $engine->generate($template, Carbon::parse($this->asOf));
    }
}
