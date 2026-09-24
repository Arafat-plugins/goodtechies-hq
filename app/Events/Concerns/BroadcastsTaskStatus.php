<?php

namespace App\Events\Concerns;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Queue\SerializesModels;

/**
 * The live half of a status move: `private-task.{task}`, once (master prompt Part D §10).
 *
 * ## Why a trait and not one event
 *
 * A task's status moves through exactly three events, because two destinations have a
 * notification of their own: `TaskSubmittedForReview` for In review, `TaskCompleted` for
 * Completed and `TaskStatusChanged` for the other six — never two of them
 * (`TaskService::transition()` picks one). That split is §11's, it is right, and it is nothing
 * to do with the socket: to a screen watching a task, all three are "the status is now X".
 *
 * So the broadcast is written once here and worn by all three. The alternative was a fourth
 * event dispatched alongside the real one, which would have meant a second thing for
 * `transition()` to remember and a day when it remembered it for two of the three moves — the
 * shape this repo already refuses for status writes (`Task::applyTransition()`).
 *
 * ## What it sends, and why that is not a privacy decision
 *
 * The id, the new status, its label and its tone. Every one of those is in the `TaskResource`
 * payload the same person already receives over HTTP, and the channel's callback is
 * `TaskPolicy::view` — the same policy that put the task in their list. Realtime is a delivery
 * mechanism, not a permission model: nothing is visible here that was not visible before, and
 * nothing is sent that a reader would have to be told again.
 *
 * Deliberately absent: the title, the actor and the reason. Not because they are secret — they
 * are on the task — but because a socket payload that carries prose is a second place for the
 * status vocabulary to be written, and the screens read `status_label` from the enum exactly
 * as every list does.
 *
 * ## Queued, like every broadcast here
 *
 * `ShouldBroadcast`, so a stopped Reverb costs a retried job rather than a 500 on the drag
 * that moved the card. See App\Events\NotificationFeedChanged for the whole argument.
 */
trait BroadcastsTaskStatus
{
    use InteractsWithSockets, SerializesModels;

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('task.'.$this->task->getKey())];
    }

    /**
     * One name for all three events. A screen watching a task wants "the status is now X" and
     * should not have to know which of §11's three notifications went out alongside it.
     */
    public function broadcastAs(): string
    {
        return 'status.changed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $status = $this->task->status;

        return [
            'task_id' => (int) $this->task->getKey(),
            'status' => $status?->value,
            'status_label' => $status?->label(),
            'tone' => $status?->tone(),
            'at' => now()->toIso8601String(),
        ];
    }
}
