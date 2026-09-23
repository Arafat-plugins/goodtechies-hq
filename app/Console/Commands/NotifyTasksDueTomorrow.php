<?php

namespace App\Console\Commands;

use App\Events\TaskDueTomorrow;
use App\Models\Task;
use App\Services\NotificationService;
use App\Support\NotificationType;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Daily at 08:00: one reminder per task due TOMORROW, to its assignees. The last of Part D §19's
 * fixed automation rules ("Task due tomorrow → notify assignee").
 *
 * Deliberately a near-copy of `hq:flag-overdue`, because it is the same shape of job and the two
 * should be readable side by side:
 *
 *   - **It only sends.** Nothing here writes to a task. "Due tomorrow" is `Task::scopeDueOn()`
 *     against the day after the as-of date and stays a query, like the overdue bucket, so it is
 *     right at every hour of every day and cannot go stale at midnight.
 *   - **"Once per task" is answered by the notifications table**, through
 *     NotificationService::alreadySentFor(), not by a column. A second run at 08:05 because
 *     somebody was watching sends nothing, and so does the first run after a weekend of
 *     downtime.
 *
 * The one thing that memory costs: a task rescheduled from Friday to the following Monday is
 * "due tomorrow" a second time and is NOT reminded again, because it already has a row. That is
 * the same trade `hq:flag-overdue` makes — see its docblock — and it is the right way round: a
 * reminder that did not arrive costs a day's notice, a reminder that arrives every time a date
 * is touched costs the bell its credibility.
 */
#[Signature('hq:notify-due-tomorrow {--as-of= : The date to measure against (default: today)}')]
#[Description('Remind assignees about the tasks due tomorrow')]
class NotifyTasksDueTomorrow extends Command
{
    public function handle(NotificationService $notifications): int
    {
        $asOf = $this->asOf();
        $due = $asOf->copy()->addDay();

        // notArchived for the same reason the overdue sweep excludes them: an archived task is
        // not work anybody is being asked to do. dueOn() is already open-only, so a task
        // completed this afternoon is not reminded about tonight.
        $tasks = Task::query()
            ->dueOn($due)
            ->notArchived()
            ->orderBy('id')
            ->get();

        if ($tasks->isEmpty()) {
            $this->info(sprintf('No tasks due on %s.', $due->toDateString()));

            return self::SUCCESS;
        }

        $alreadySent = $notifications->alreadySentFor(NotificationType::TaskDueTomorrow, $tasks);

        $sent = 0;

        foreach ($tasks as $task) {
            if (in_array((int) $task->getKey(), $alreadySent, true)) {
                continue;
            }

            event(new TaskDueTomorrow($task, $asOf));

            $sent++;
        }

        $this->info(sprintf(
            '%d task%s due on %s: %d reminded, %d already reminded.',
            $tasks->count(),
            $tasks->count() === 1 ? '' : 's',
            $due->toDateString(),
            $sent,
            $tasks->count() - $sent,
        ));

        return self::SUCCESS;
    }

    /**
     * The date to measure against — see FlagOverdueTasks::asOf(), which this mirrors exactly.
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
