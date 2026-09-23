<?php

namespace App\Events;

use App\Models\Task;
use App\Models\User;

/**
 * A task was deleted — soft-deleted, Admin/Manager only, and already audit-logged by the time
 * this is fired.
 *
 * The task instance still exists in memory and its row still exists in the database (the delete
 * is soft precisely so the audit row keeps pointing at something), so the dispatcher can still
 * ask who was assigned to it and whether they may see it.
 */
class TaskDeleted
{
    public function __construct(
        public readonly Task $task,
        public readonly User $actor,
    ) {}
}
