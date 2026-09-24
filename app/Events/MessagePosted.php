<?php

namespace App\Events;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;

/**
 * Somebody posted a message, in any conversation of any type.
 *
 * Fired by MessageService::post() and by nothing else, for every type including `task` — so a
 * task discussion produces this AND the Phase 2 `TaskCommented`, and the two do not overlap:
 * `TaskCommented` is about the task and notifies the people working on it, this one is about
 * the message and notifies the people it NAMED, the other half of a DM and the readers of an
 * announcement. NotificationDispatcher keeps them apart in one method each.
 *
 * ## This is the realtime seam
 *
 * It carries the conversation, the message and the author, which is everything a
 * `conversation.{id}` broadcast needs. A listener that broadcasts subscribes to this event;
 * nothing in MessageService knows Reverb exists, and because the event is dispatched inside the
 * transaction that wrote the message, a post that rolls back broadcasts nothing.
 */
class MessagePosted
{
    /**
     * @param  list<int>  $mentionedUserIds  the people this message named, as `message_mentions`
     *                                       recorded them — already filtered to people who can
     *                                       read the conversation
     */
    public function __construct(
        public readonly Conversation $conversation,
        public readonly Message $message,
        public readonly User $actor,
        public readonly array $mentionedUserIds = [],
    ) {}
}
