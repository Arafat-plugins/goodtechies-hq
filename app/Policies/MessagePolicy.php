<?php

namespace App\Policies;

use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Who may see a message, and who may hang a file on one.
 *
 * Both questions delegate. Seeing a message is seeing its conversation, which for a task is
 * seeing the task — so the chain a message attachment's visibility runs down is
 * file → message → conversation → task → TaskPolicy, with each link stating nothing of its own.
 * That is what makes "an employee sees the discussion only of tasks they are assigned to" true
 * of the attachments as well, without the word "assigned" appearing anywhere in this file.
 *
 * There is no `update` in the editing sense and no `delete`: a message cannot be changed or
 * removed, by anybody, and there is no endpoint for either.
 */
class MessagePolicy extends Policy
{
    public function view(User $user, Message $message): bool
    {
        if (! $user->isActive()) {
            return false;
        }

        $conversation = $message->conversation;

        return $conversation !== null && Gate::forUser($user)->allows('view', $conversation);
    }

    /**
     * "May this user attach a file to this message?"
     *
     * It is named `update` because that is the ability FileService::guardMayAttach() asks the
     * OWNER of a file for — one ability for every kind of owner, so that attaching to a record
     * takes exactly what changing that record takes and the rule cannot drift into a second set
     * of file permissions. Here the owner is a message, and the answer is: the author, on a
     * conversation they may still post in.
     *
     * The author clause is what makes a message's attachments the author's own. Somebody else
     * hanging a file on your message would be putting words in your mouth; they post their own
     * message with their own file.
     *
     * In practice this is reached exactly once per file, inside ConversationService::post(),
     * microseconds after the message was created by the same person. FilePolicy::replace()
     * refuses a message attachment outright, so nothing reaches it later.
     */
    public function update(User $user, Message $message): bool
    {
        if (! $this->view($user, $message)) {
            return false;
        }

        if ($message->author_id === null || (int) $message->author_id !== (int) $user->getKey()) {
            return false;
        }

        $conversation = $message->conversation;

        return $conversation !== null && Gate::forUser($user)->allows('post', $conversation);
    }
}
