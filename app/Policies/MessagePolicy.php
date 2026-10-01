<?php

namespace App\Policies;

use App\Models\Message;
use App\Models\User;
use App\Support\ConversationType;
use App\Support\Permission;
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
 * Since 12-79 `update` is also the edit ability (the author, while they may still post), and
 * `delete` / `react` exist. A deleted message can be neither edited, deleted again, nor reacted
 * to.
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

        if ($message->isDeleted()) {
            return false;
        }

        if ($message->author_id === null || (int) $message->author_id !== (int) $user->getKey()) {
            return false;
        }

        $conversation = $message->conversation;

        return $conversation !== null && Gate::forUser($user)->allows('post', $conversation);
    }

    /**
     * Delete for everyone (12-79): the author, or — outside a DM — a holder of
     * `messages.manage`. Never a message already deleted.
     */
    public function delete(User $user, Message $message): bool
    {
        if ($message->isDeleted() || ! $this->view($user, $message)) {
            return false;
        }

        if ($message->author_id !== null && (int) $message->author_id === (int) $user->getKey()) {
            return true;
        }

        return $this->allows($user, Permission::MessagesManage)
            && $message->conversation->type !== ConversationType::Dm;
    }

    /**
     * Anyone who can see a live message may react to it (12-79).
     */
    public function react(User $user, Message $message): bool
    {
        return ! $message->isDeleted() && $this->view($user, $message);
    }
}
