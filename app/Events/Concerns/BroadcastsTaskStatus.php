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
 * ## The frame is a doorbell, not a payload
 *
 * One id. The screen answers a ping by re-reading the task over HTTP, so what it paints is what
 * `TaskPolicy` and `TaskResource` built for this reader — never something assembled from a
 * socket frame. That is the same rule `App\Events\ConversationActivity` states at length and
 * for the same reason: **there is one place that decides what a person may see, and it is not
 * the frame.**
 *
 * Until Phase 12's polish pass this carried `status`, `status_label`, `tone` and `at`, and the
 * task detail page read the label straight out of it to compose a sentence. Every one of those
 * four was also in the `TaskResource` payload the same reader already had, so nothing leaked —
 * but the frame was a second place where the status vocabulary was written, and a screen that
 * paints out of a frame is a screen whose correctness depends on the frame staying as narrow
 * as the policy. `task_id` cannot be wrong that way: a reader who should not see the task asks
 * for it and is told no.
 *
 * It also makes the two transports say the same thing. A polling build re-reads the task; a
 * socket build re-reads it sooner. Nothing is delivered live that was not deliverable cold.
 *
 * ## Queued, and only after the write is real
 *
 * `ShouldBroadcast`, so a stopped Reverb costs a retried job rather than a 500 on the drag
 * that moved the card. See App\Events\NotificationFeedChanged for the whole argument.
 *
 * `ShouldDispatchAfterCommit` is the other half, and all three events that wear this trait
 * declare it. `TaskService::transition()` fires them INSIDE its write transaction, and
 * `config/queue.php` sets `after_commit => false` on every connection (POLISH-BACKLOG §E.4) —
 * so without it the BroadcastEvent job is pushed the instant the event is dispatched, and a
 * transition that throws `TaskStateException::workSummaryRequired()` two lines later has
 * already told every open board that the card moved. `Event::fake()` cannot see this: a fake
 * records a dispatch the moment it happens and knows nothing about the commit it was waiting
 * for, so the test of it in `tests/Feature/Realtime/TaskBroadcastTest.php` listens for real.
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
     * Exactly one key. `TaskBroadcastTest` asserts the KEY SET, not just the value, so adding a
     * second is a failing test rather than a quiet privacy decision.
     *
     * @return array{task_id: int}
     */
    public function broadcastWith(): array
    {
        return ['task_id' => (int) $this->task->getKey()];
    }
}
