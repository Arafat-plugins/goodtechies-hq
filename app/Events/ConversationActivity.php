<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Something was said in a conversation — `private-conversation.{id}` (master prompt Part D §10).
 *
 * This is the thing that hangs off the seam `MessagePosted` was built to be. The channel and its
 * policy-backed callback (App\Broadcasting\ConversationChannel) already existed and nothing ever
 * broadcast on them, which is why a DM did not move until the reader hit reload
 * (POLISH-BACKLOG §A.2 item 1).
 *
 * ## The frame is a doorbell, not a payload
 *
 * Two ids and nothing else. The screen answers a ping by asking `GET /messages/{conversation}`
 * for the truth, so what it paints is what `MessagePolicy` and `MessageResource` built — never
 * something assembled from a socket frame. That is already the rule the bell follows for the
 * opposite reason (decision 6-8: the bell sends the WHOLE feed so the client never has to add
 * up), and both rules come out of the same sentence: **there is one place that decides what a
 * person may see, and it is not the frame.**
 *
 * The moment this carries the body or the author's name, the frame becomes a second such place.
 * A subscriber is authorised for the ROOM — `ConversationPolicy::view` — and that is not the
 * same question as "may this person read this message": `MessagePolicy` is still asked per row
 * by the endpoint, and a redaction, a deletion or a policy that narrows tomorrow would be
 * enforced on the HTTP read and silently not on the socket. Two ids cannot be wrong that way:
 * a reader who should not have the message asks for it and is told no.
 *
 * It also makes the payload the same on both transports. A polling build re-reads the thread; a
 * socket build re-reads the thread sooner. Nothing is delivered live that was not deliverable
 * cold, so the socket saves a wait, it does not widen an answer.
 *
 * ## Ids, not models
 *
 * `conversation_id` and `message_id` are plain ints, so the queued broadcast job carries two
 * numbers rather than two serialized models. A queued job holding a model re-fetches it when it
 * runs, and a message deleted between the post and the worker picking the job up would throw a
 * ModelNotFoundException in the worker for a frame that only ever needed the id. The client
 * treats `message_id` as a hint ("something at least this new"), not as a thing to fetch.
 *
 * ## Queued, and only after the write is real
 *
 * `ShouldBroadcast`, not `ShouldBroadcastNow`: broadcasting is an HTTP call to Reverb and a
 * stopped Reverb must cost a retried job, not a 500 on a message that was already written. See
 * App\Events\NotificationFeedChanged for the whole argument.
 *
 * `ShouldDispatchAfterCommit` is the other half, and it is load-bearing here. `MessagePosted` is
 * fired INSIDE `MessageService::post()`'s transaction — deliberately, so a post that rolls back
 * notifies nobody. Notifications inherit that for free because they are database writes in the
 * same transaction. A broadcast does not: `config/queue.php` sets `after_commit => false` on
 * every connection, so without this interface the BroadcastEvent job would be pushed to Redis
 * the instant the listener ran, and a transaction that rolled back a second later would already
 * have rung every open thread about a `message_id` that no longer exists. With it, the dispatch
 * itself waits for the outermost commit and a rolled-back post broadcasts nothing.
 */
class ConversationActivity implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $conversationId,
        public readonly int $messageId,
    ) {}

    /**
     * The channel that already existed, authorised by the policy that already existed. No new
     * channel, no second auth path: a person off `ConversationPolicy::view` is off this frame.
     *
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('conversation.'.$this->conversationId)];
    }

    /**
     * The name the client listens for. Spelled out rather than left as the class name, because
     * a namespaced PHP class is not a name a front end should have to know — the same reason
     * `feed.changed` and `status.changed` are spelled out.
     */
    public function broadcastAs(): string
    {
        return 'conversation.message';
    }

    /**
     * Exactly two keys. `ConversationBroadcastTest` asserts the KEY SET, not just the values,
     * so adding a third is a failing test rather than a quiet privacy decision.
     *
     * @return array{conversation_id: int, message_id: int}
     */
    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'message_id' => $this->messageId,
        ];
    }
}
