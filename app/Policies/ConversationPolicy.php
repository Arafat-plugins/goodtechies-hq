<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;
use App\Support\ConversationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Who may read and post in a conversation (master prompt Part C; Phase 2 slice 4).
 *
 * ## The decision this file is
 *
 * The spec says the task conversation's membership "follows task access". `conversation_members`
 * is a stored list; "who may see this task" is computed from assignment and role and changes
 * whenever a task is reassigned. Those two cannot both be the answer, so one of them has to
 * stop being an answer at all.
 *
 * **What was chosen:** for a `task` conversation, access is COMPUTED — every read and every
 * post asks TaskPolicy::view about the linked task, in the same call the task list does.
 * `conversation_members` holds read state and grants nothing. Nothing in this file touches it.
 *
 * That makes the three awkward cases free rather than handled:
 *
 *   - a task is REASSIGNED away from somebody: the next request they make to the discussion
 *     asks TaskPolicy, which now says no, and the endpoint 404s. No sync ran, because there is
 *     nothing to sync. The member row they leave behind holds a `last_read_at` and nothing else.
 *   - somebody's ROLE changes, or they are deactivated: same, and no task was touched at all,
 *     which is precisely the case a sync-on-assignment scheme would have missed.
 *   - a task moves PROJECT, or a Manager is added: same again.
 *
 * **What was rejected, and why:**
 *
 *   - *Stored membership, synced on every write that changes task access.* Correct only while
 *     every such write remembers to call the sync — and two of the three cases above touch no
 *     task row, so the sync would have to hang off role changes and deactivation as well. It is
 *     the shape where a stale row silently outlives somebody's access, which is exactly the
 *     privacy hole this repo tests for.
 *   - *A trigger or a database view maintaining the membership.* Same rule, written a second
 *     time in another language. TaskPolicy's rule includes "is this user active" and "does this
 *     user hold tasks.view", which are not things a trigger on `task_assignees` can see.
 *   - *Treating a member row as a grant "as well as" the computed answer.* Strictly wider than
 *     the computed answer, which means the only rows it can ever add are the wrong ones.
 *
 * ## The other four types
 *
 * Denied outright. Phase 6 builds team, project, DM and announcement conversations on these
 * same tables and will decide their rules then; until it does, deny by default means a row of
 * one of those types — however it got there — is a row nobody can open.
 */
class ConversationPolicy extends Policy
{
    /**
     * Reading the discussion.
     *
     * Delegates to the SUBJECT's own policy, the way FilePolicy delegates to a file's owner. A
     * rule stated twice is a rule that can be changed once.
     */
    public function view(User $user, Conversation $conversation): bool
    {
        if (! $user->isActive()) {
            return false;
        }

        $subject = $this->computedSubject($conversation);

        return $subject !== null && Gate::forUser($user)->allows('view', $subject);
    }

    /**
     * Posting a message.
     *
     * The same answer as reading, deliberately: everybody in the room may speak in it. A
     * discussion where some of the people who can read it cannot reply is a discussion that
     * needs a second concept — "observers" — which nothing in the spec asks for.
     *
     * Note what it is NOT gated on: TaskPolicy::update. An ARCHIVED task is read-only for
     * every field, and a comment on it is not a field — saying "this was archived by mistake"
     * on the task it happened to is more useful than being silently unable to.
     */
    public function post(User $user, Conversation $conversation): bool
    {
        return $this->view($user, $conversation);
    }

    /**
     * The record a conversation's access is computed FROM, or null when there is not one.
     *
     * Null for the four Phase 6 types, and null for a `task` conversation whose task has gone —
     * both end the same way, at a denial, because a conversation whose access rule cannot be
     * evaluated is one nobody may open.
     */
    private function computedSubject(Conversation $conversation): ?Model
    {
        if (! $conversation->membershipIsComputed()) {
            return null;
        }

        return $conversation->type === ConversationType::Task
            ? $conversation->task
            : null;
    }
}
