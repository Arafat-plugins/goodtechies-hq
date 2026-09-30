<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Something happened to a task that a Tasks screen should re-read — flow F1, task-live-update.
 *
 * ## Why a broadcast-only event, and why per USER
 *
 * The Board used to re-read every twenty seconds whether anything had happened or not, because
 * there was no channel a board could listen on: `task.{id}` only reaches a screen that already
 * knows the task, and "a task was just assigned to me" is precisely the task my board does not
 * know. So this event goes to `tasks.{user}` — one private channel per person, which only that
 * person may join (`App\Broadcasting\UserTasksChannel`) — and it is sent to exactly the people
 * `TaskPolicy::view` lets see the task, asked BEFORE and AFTER the change so the person just
 * unassigned is told as well. `TaskService::viewerIds()` computes that list; this class only
 * carries it.
 *
 * It is not one of §11's notification events and `NotificationDispatcher` does not listen to it:
 * the bell and the screens are two different questions, and a notification row for "the
 * priority changed" is not something anybody asked for.
 *
 * ## The frame is a doorbell, not a payload
 *
 * `{task_id, kind}` — an id and one word from a fixed list, never a title, a name or a status.
 * The screen answers by re-reading its own props over HTTP, so what it paints is what the policy
 * and `TaskResource` built for this reader. `kind` exists for a reader of the network tab, not
 * for the screen, which re-reads the same way whatever it says. Same rule, same reason as
 * `App\Events\Concerns\BroadcastsTaskStatus`.
 *
 * ## After the commit, and not back to the tab that did it
 *
 * `ShouldDispatchAfterCommit`, because `TaskService` fires this inside its write transaction and
 * `config/queue.php` has `after_commit => false` (POLISH-BACKLOG §E.4): without it a change that
 * rolls back would already have told every board. The socket id of the request that made the
 * change is taken in the constructor, so Reverb skips the acting tab — it re-reads by itself
 * when its own request lands.
 *
 * `ShouldBroadcast` (queued), so a stopped Reverb costs a retried job rather than a 500 on the
 * write that caused it.
 */
class TaskChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use InteractsWithSockets;

    /** Every word `kind` can be. Anything else is a bug in the caller. */
    public const KINDS = [
        'assigned',
        'unassigned',
        'created',
        'deleted',
        'archived',
        'restored',
        'updated',
        'status',
        'reordered',
        'commented',
        // Flow F3: a task timer started, paused, resumed or stopped on this task.
        'timer',
    ];

    /**
     * @param  list<int>  $userIds  who may see the task, before or after the change
     */
    public function __construct(
        public readonly int $taskId,
        public readonly string $kind,
        public readonly array $userIds,
    ) {
        $this->dontBroadcastToCurrentUser();
    }

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return array_map(
            fn (int $userId): PrivateChannel => new PrivateChannel('tasks.'.$userId),
            $this->userIds,
        );
    }

    /** Nobody to tell — a task nobody may see — is no call to Reverb at all. */
    public function broadcastWhen(): bool
    {
        return $this->userIds !== [];
    }

    public function broadcastAs(): string
    {
        return 'task.changed';
    }

    /**
     * Exactly two keys. The flow test asserts the KEY SET, so a third is a failing test rather
     * than a quiet privacy decision.
     *
     * @return array{task_id: int, kind: string}
     */
    public function broadcastWith(): array
    {
        return ['task_id' => $this->taskId, 'kind' => $this->kind];
    }
}
