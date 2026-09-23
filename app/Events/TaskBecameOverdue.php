<?php

namespace App\Events;

use App\Models\Task;
use Illuminate\Support\Carbon;

/**
 * A task passed its due date and nobody has been told yet (spec §19 "Task overdue → notify
 * assignee + manager/admin").
 *
 * Not one of the seven task events, and deliberately shaped differently: nobody DID this, so
 * there is no actor. It is fired by `hq:flag-overdue` and by nothing else.
 *
 * "Newly" is the load-bearing word and it is not decided here — the command asks
 * NotificationService::alreadySentFor() which tasks have had one before, and fires this only
 * for the rest. The overdue BUCKETS stay query-time (Task::scopeOverdue, TaskService::overdue);
 * nothing in this slice stores an overdue flag, because a stored flag is wrong every midnight.
 */
class TaskBecameOverdue
{
    public function __construct(
        public readonly Task $task,
        public readonly Carbon $asOf,
    ) {}
}
