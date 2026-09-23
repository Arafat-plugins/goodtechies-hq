<?php

namespace App\Events;

use App\Models\Task;
use App\Models\User;

/**
 * A task that already had assignees changed hands (spec §19 "Task reassigned").
 *
 * It carries both sides of the change rather than just the new one, because the people who need
 * telling are on both: somebody taken OFF a task needs to know at least as much as somebody put
 * on it. NotificationDispatcher takes the symmetric difference; nothing here decides.
 *
 * A hand-off — TaskService::handOff(), which moves "primary" between two people who are both
 * already assigned — fires this too, with identical assignee lists and different primaries.
 * That is why the primary is its own pair of fields and not inferred from the lists.
 *
 * Ids are `employees.id`, matching the audit row's snapshot.
 */
class TaskReassigned
{
    /**
     * @param  list<int>  $previousAssigneeIds
     * @param  list<int>  $currentAssigneeIds
     */
    public function __construct(
        public readonly Task $task,
        public readonly User $actor,
        public readonly array $previousAssigneeIds,
        public readonly array $currentAssigneeIds,
        public readonly ?int $previousPrimaryId = null,
        public readonly ?int $currentPrimaryId = null,
    ) {}
}
