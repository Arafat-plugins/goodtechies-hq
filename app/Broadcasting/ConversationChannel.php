<?php

namespace App\Broadcasting;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * `private-conversation.{conversation}` — one conversation's live messages (Part D §10).
 *
 * `ConversationPolicy::view`, and nothing else. That file is the one place membership is
 * decided, and it decides it by COMPUTING it — a `task` conversation asks the linked task's
 * `TaskPolicy::view` (decision 2-24), and `conversation_members` grants nothing. So a person
 * reassigned away from a task is off this channel at their next subscription, with no
 * membership list to sync and nothing here to remember.
 *
 * It is also why this callback did not have to change when the other four conversation types
 * were built: the policy widened, and the socket followed. A rule that is not expressible
 * through the policy is a finding, not a licence to write it twice here.
 *
 * `{conversation}` is implicitly bound; an id that matches no row is 403 before `join()` runs.
 */
class ConversationChannel
{
    public function join(User $user, Conversation $conversation): bool
    {
        return Gate::forUser($user)->allows('view', $conversation);
    }
}
