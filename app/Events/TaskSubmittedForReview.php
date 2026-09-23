<?php

namespace App\Events;

use App\Models\Task;
use App\Models\User;

/**
 * A task was submitted for review (spec §19 "Task marked In Review → notify reviewer").
 *
 * Fired instead of TaskStatusChanged for this one destination — see that class. Who the
 * reviewer is is NOT decided here: NotificationDispatcher asks TaskService::reviewersFor(),
 * which is TaskReviewers, which is the same answer TaskPolicy::review() gives. There is one
 * definition of "the reviewer" in this application and a notification does not get a second.
 */
class TaskSubmittedForReview
{
    public function __construct(
        public readonly Task $task,
        public readonly User $actor,
    ) {}
}
