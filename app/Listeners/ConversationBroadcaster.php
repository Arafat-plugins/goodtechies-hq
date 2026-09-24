<?php

namespace App\Listeners;

use App\Events\ConversationActivity;
use App\Events\MessagePosted;

/**
 * The live half of a post: one `MessagePosted` in, one `ConversationActivity` out.
 *
 * ## Why a listener and not a line in MessageService
 *
 * `MessageService::post()` already says what it means — it writes a message and fires the event
 * that says so. Nothing in it knows Reverb exists, and that is the property worth keeping:
 * `MessagePosted` carries the conversation, the message and the actor, which is everything a
 * `conversation.{id}` broadcast needs, so the transport hangs off the seam instead of being
 * threaded through the write. One service call site did not change.
 *
 * ## Every conversation type, because there is one event
 *
 * A DM, the team channel, a project channel, an announcement and a task discussion are all
 * `MessagePosted` and all have a `conversation.{id}` channel, so all five ring by construction.
 * There is no type check here and there must not be one: a sixth type would inherit this the
 * day it is added, and a `match` on the type is how four of the five end up working and the
 * fifth does not.
 *
 * ## It is not queued, and the broadcast is
 *
 * This method runs inline inside `post()` and does one thing: construct an event holding two
 * ints and hand it to the dispatcher. The dispatcher, seeing `ShouldDispatchAfterCommit`, parks
 * it on the transaction; the commit releases it; `ShouldBroadcast` then pushes ONE queued job.
 * So the cost added to `POST /messages/{id}` is an object and an array push — the socket call
 * itself happens in the worker, where a stopped Reverb is a retry rather than a failed post.
 */
class ConversationBroadcaster
{
    /**
     * Registered in AppServiceProvider with `Event::subscribe()`, exactly as
     * NotificationDispatcher is, so the map of "which events produce a broadcast" is in the
     * class that produces them.
     *
     * It is a subscriber rather than a `handle(MessagePosted $event)` listener for a reason
     * that is mechanical, not stylistic: event auto-discovery registers anything in
     * `app/Listeners` whose `handle()` type-hints an event (that is how the login listeners are
     * registered). A class with both a discovered `handle()` and an explicit registration is
     * subscribed twice and broadcasts twice, and the second frame is invisible until somebody
     * counts. `subscribe()` is not discovered, so this class has exactly one registration.
     *
     * @return array<class-string, string>
     */
    public function subscribe(): array
    {
        return [
            MessagePosted::class => 'onMessagePosted',
        ];
    }

    public function onMessagePosted(MessagePosted $event): void
    {
        ConversationActivity::dispatch(
            (int) $event->conversation->getKey(),
            (int) $event->message->getKey(),
        );
    }
}
