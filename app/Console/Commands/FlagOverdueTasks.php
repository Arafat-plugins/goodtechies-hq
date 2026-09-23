<?php

namespace App\Console\Commands;

use App\Events\TaskBecameOverdue;
use App\Models\Task;
use App\Services\NotificationService;
use App\Support\NotificationType;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Daily at 08:00: one notification per NEWLY overdue task, to its assignees and their
 * manager (or every Admin where they have none). Spec §19, "Task overdue → notify assignee +
 * manager/admin".
 *
 * ## The job only sends
 *
 * The overdue BUCKET is a query and stays one: `Task::scopeOverdue()` is `due_date < as-of AND
 * the status is still owed`, computed every time it is asked and never stored, because a stored
 * flag is wrong every midnight. This command runs that same scope and does nothing to the rows
 * it finds — no status moves, no column is written on a task. It is not a second write path and
 * there is nothing here for one to hide in.
 *
 * ## "Newly", without a column
 *
 * The spec's word is "once per task, not per day", and the memory it needs already exists: a
 * task that has been flagged has a `task.overdue` notification with that task's group key, and
 * one that has not, has not. NotificationService::alreadySentFor() asks that in one query for
 * the whole batch.
 *
 * That is not just the cheapest answer, it is the most robust one. "Overdue since yesterday" —
 * the other obvious definition — is right only if this job has run every single morning since
 * the task was created. This one is right after a weekend of downtime, after a re-run at 08:05
 * because somebody was watching, and after a restore.
 *
 * It also means a task is flagged once and for all: a task that goes overdue, is completed, is
 * reopened and goes overdue again is not flagged a second time. That is the spec's rule read
 * literally, and the Overdue bucket — which is what people actually work from — never stopped
 * showing it for a moment.
 */
#[Signature('hq:flag-overdue {--as-of= : The date to measure against (default: today)}')]
#[Description('Notify assignees and their managers about tasks that have newly become overdue')]
class FlagOverdueTasks extends Command
{
    public function handle(NotificationService $notifications): int
    {
        $asOf = $this->asOf();

        // Archived tasks are excluded for the same reason they are excluded from every list:
        // an archived task is not work anybody is being asked to do.
        $overdue = Task::query()
            ->overdue($asOf)
            ->notArchived()
            ->orderBy('id')
            ->get();

        if ($overdue->isEmpty()) {
            $this->info(sprintf('No overdue tasks as of %s.', $asOf->toDateString()));

            return self::SUCCESS;
        }

        $alreadyFlagged = $notifications->alreadySentFor(NotificationType::TaskOverdue, $overdue);

        $sent = 0;

        foreach ($overdue as $task) {
            if (in_array((int) $task->getKey(), $alreadyFlagged, true)) {
                continue;
            }

            event(new TaskBecameOverdue($task, $asOf));

            $sent++;
        }

        $this->info(sprintf(
            '%d overdue task%s as of %s: %d newly flagged, %d already flagged.',
            $overdue->count(),
            $overdue->count() === 1 ? '' : 's',
            $asOf->toDateString(),
            $sent,
            $overdue->count() - $sent,
        ));

        return self::SUCCESS;
    }

    /**
     * The date to measure against. A parameter rather than a call to today() inside the query,
     * for the same reason TaskService's `as_of` filter is one: it is what makes the rule
     * testable at a fixed date. An unparseable value falls back to today rather than throwing —
     * a cron entry with a typo in it should still flag the overdue work.
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
            $this->warn(sprintf('Could not read --as-of=%s; measuring against today.', $value));

            return Carbon::today();
        }
    }
}
