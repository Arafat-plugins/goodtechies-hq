<?php

namespace App\Events;

use App\Models\Task;
use App\Models\User;

/**
 * A task reached Completed (spec §19 "Task completed → notify original assigner/reviewer").
 *
 * The "original assigner" is `tasks.created_by` — TaskResource already says so, and there is no
 * second column for it. The reviewer comes from TaskService::reviewersFor().
 *
 * Fired instead of TaskStatusChanged for this one destination — see that class.
 */
class TaskCompleted
{
    public function __construct(
        public readonly Task $task,
        public readonly User $actor,
    ) {}
}
