<?php

namespace App\Events;

use App\Models\Task;
use Illuminate\Support\Carbon;

/**
 * A task is due tomorrow and its assignee has not been reminded (spec §19, "Task due tomorrow →
 * notify assignee"). The last of Part D §19's fixed rules to be built.
 *
 * Shaped like TaskBecameOverdue and for the same reason: nobody DID this, a date approached, so
 * there is no actor. It is fired by `hq:notify-due-tomorrow` and by nothing else, and — exactly
 * like its overdue twin — the command decides which tasks are new by asking the notifications
 * table, not by writing a flag on the task.
 */
class TaskDueTomorrow
{
    public function __construct(
        public readonly Task $task,
        /** The day the reminder was sent on. The task is due the day after. */
        public readonly Carbon $asOf,
    ) {}
}
