<?php

namespace App\Events;

use App\Events\Concerns\BroadcastsTaskStatus;
use App\Models\Task;
use App\Models\User;
use App\Support\TaskStatus;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

/**
 * A task moved along the status machine.
 *
 * ## One event per move, not two
 *
 * Two of the eight destinations have a notification of their own in the spec — §19 gives "Task
 * marked In Review → notify reviewer" and "Task completed → notify original assigner/reviewer"
 * their own rows — so TaskService::transition() fires TaskSubmittedForReview for a move to In
 * review, TaskCompleted for a move to Completed, and THIS for every other move. Never two.
 *
 * That is §11's "one event = one notification" taken literally: a reviewer who received both
 * "waiting for your review" and "status changed to In review" would have been told the same
 * thing twice, and the second one would be the less useful of the two.
 */
class TaskStatusChanged implements ShouldBroadcast
{
    use BroadcastsTaskStatus;

    public function __construct(
        public readonly Task $task,
        public readonly User $actor,
        public readonly TaskStatus $from,
        public readonly TaskStatus $to,
        public readonly ?string $reason = null,
    ) {}
}
