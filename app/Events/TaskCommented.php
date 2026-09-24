<?php

namespace App\Events;

use App\Models\Message;
use App\Models\Task;
use App\Models\User;

/**
 * Somebody posted in a task's discussion.
 *
 * The spec's "comments" are the messages of the task's own `task` conversation (the recorded
 * one-store decision — there is no `task_comments` table), so this is fired by
 * ConversationService::post() when the conversation's subject is a task, and by nothing else.
 *
 * This is the type §11's dedup example is about: twelve of these on one task inside the window
 * are one row reading "12 new comments in …".
 *
 * ## The fourth argument, and the question it settles
 *
 * Phase 6 lets a message NAME people, and a mention is a different fact from a comment: *"Tapu
 * mentioned you in Optimize Home Model pages"* is addressed to you, *"New comment in Optimize
 * Home Model pages"* is news about a task you happen to be on. Somebody who is both an assignee
 * and mentioned in the same sentence would otherwise get one of each — two rows in one bell
 * about one message, saying the weaker thing twice.
 *
 * So the mention wins: the ids travel on this event and NotificationDispatcher drops them from
 * the comment's recipient list. They are told once, by the notification that carries more
 * information. It is dropped at the RECIPIENT list rather than by suppressing one type, so
 * everybody else on the task is still told there is a new comment — which is the part that
 * would have been lost by deciding it the other way round.
 */
class TaskCommented
{
    /**
     * @param  list<int>  $mentionedUserIds  people this message named; they hear about it as a
     *                                       mention instead, and are dropped from this event's
     *                                       recipients
     */
    public function __construct(
        public readonly Task $task,
        public readonly User $actor,
        public readonly Message $message,
        public readonly array $mentionedUserIds = [],
    ) {}
}
