<?php

namespace App\Broadcasting;

use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * `private-task.{task}` — one task's live updates (master prompt Part D §10).
 *
 * One line, and that is the point: **who may watch a task is who may read it**, which
 * `TaskPolicy::view` has answered since Phase 2 and which `Task::visibleTo()` states in SQL
 * for the lists. Nothing is restated here. An employee is on this channel while they are
 * assigned and off it the moment they are not, because the policy is asked on every
 * subscription and Echo re-authorises on every reconnect.
 *
 * Note what that does NOT mean: nothing new becomes visible because it arrived live. Every
 * payload broadcast on this channel is a subset of what `TaskResource` already sends to the
 * same person over HTTP — see `App\Events\Concerns\BroadcastsToTaskChannel`.
 *
 * `{task}` is implicitly bound. A deleted task — `Task` is soft-deleted — resolves to nothing
 * and is refused with 403 before `join()` runs, so a channel does not outlive its subject.
 */
class TaskChannel
{
    public function join(User $user, Task $task): bool
    {
        return Gate::forUser($user)->allows('view', $task);
    }
}
