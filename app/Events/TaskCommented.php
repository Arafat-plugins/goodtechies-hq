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
 */
class TaskCommented
{
    public function __construct(
        public readonly Task $task,
        public readonly User $actor,
        public readonly Message $message,
    ) {}
}
