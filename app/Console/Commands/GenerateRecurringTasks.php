<?php

namespace App\Console\Commands;

use App\Jobs\GenerateRecurringTask;
use App\Services\RecurringTaskEngine;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Daily at 00:05: generate the period's instance for every recurring template that is due
 * (master prompt Phase 3, §6, §19 "Recurring period rolls over → generate next task instance").
 *
 * ## The command decides nothing
 *
 * It asks `RecurringTaskEngine::due()` which templates to attempt and dispatches one job each.
 * Every rule — the period, the duplicate, the project's state, who the actor is — lives in the
 * engine, so a "Generate now" button pressed on a screen and this command at five past midnight
 * cannot end up with different behaviour. The same shape as `hq:flag-overdue`, which only sends.
 *
 * ## 00:05, and why a missed run is not a missed month
 *
 * Five past midnight because the period has to have rolled over before anything is generated,
 * and because nothing else is running then. The five minutes are slack, not precision: the
 * engine decides what to generate from the period the RUN DATE falls in, not from a `next_run_at`
 * that has to be hit exactly, so a run that does not happen until Tuesday still generates
 * Monday's period. See RecurringTaskEngine::due() for the catch-up rule.
 */
#[Signature('hq:generate-recurring-tasks {--as-of= : The date to generate for (default: today)}')]
#[Description('Generate this period\'s task for every recurring template that is due')]
class GenerateRecurringTasks extends Command
{
    public function handle(RecurringTaskEngine $engine): int
    {
        $asOf = $this->asOf();
        $due = $engine->due($asOf);

        if ($due->isEmpty()) {
            $this->info(sprintf('No recurring template is due as of %s.', $asOf->toDateString()));

            return self::SUCCESS;
        }

        foreach ($due as $template) {
            GenerateRecurringTask::dispatch((int) $template->getKey(), $asOf->toDateString());
        }

        // What each attempt DID is in recurring_generation_log, not here: the jobs are queued,
        // so this command cannot honestly report an outcome it has not waited for.
        $this->info(sprintf(
            '%d recurring template%s due as of %s; one job dispatched each. Outcomes are in recurring_generation_log.',
            $due->count(),
            $due->count() === 1 ? '' : 's',
            $asOf->toDateString(),
        ));

        return self::SUCCESS;
    }

    /**
     * The date to generate for. A parameter rather than a call to today() inside the engine, for
     * the same reason `hq:flag-overdue`'s is: it is what makes the rule testable at a fixed date,
     * and it is how an operator replays a night the scheduler was down. An unreadable value falls
     * back to today rather than throwing — a cron entry with a typo in it should still generate
     * the month's work.
     */
    private function asOf(): Carbon
    {
        $value = trim((string) ($this->option('as-of') ?? ''));

        if ($value === '') {
            return Carbon::today();
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            $this->warn(sprintf('Could not read --as-of=%s; generating for today.', $value));

            return Carbon::today();
        }
    }
}
