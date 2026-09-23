<?php

namespace App\Events;

use App\Models\Task;
use App\Models\User;

/**
 * A task was given to somebody for the first time (master prompt Phase 2, §19 "Task assigned").
 *
 * ## Why these are events at all
 *
 * Every event in this directory is fired by the service that made the change, from INSIDE the
 * transaction that made it, next to the audit and activity rows. Three things follow, and all
 * three are the point:
 *
 *   - **A notification is exactly as durable as the change that caused it.** The listener runs
 *     synchronously (there is no queue in this phase and nothing here implements ShouldQueue),
 *     so the `notifications` rows are written in the same transaction. A transition that throws
 *     on its last line takes its notification with it — nobody is told about a move that did
 *     not happen.
 *   - **An event is not a write path.** Nothing in app/Listeners touches a task. A status still
 *     moves only through TaskService::transition() and the model's one door; firing an event
 *     afterwards adds a reader, not a second writer.
 *   - **Recipients are decided in one place.** The service says what happened;
 *     NotificationDispatcher says who should hear about it. Neither knows the other's half.
 *
 * `$assigneeIds` are `employees.id`, the same ids the audit row's snapshot carries.
 */
class TaskAssigned
{
    /**
     * @param  list<int>  $assigneeIds
     */
    public function __construct(
        public readonly Task $task,
        public readonly User $actor,
        public readonly array $assigneeIds,
    ) {}
}
