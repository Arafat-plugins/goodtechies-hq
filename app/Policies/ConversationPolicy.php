<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;
use App\Support\ConversationType;
use App\Support\Permission;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Who may read and post in a conversation (master prompt Part C; Part D §10).
 *
 * ## The decision this file is, and what Phase 6 did to it
 *
 * Phase 2 decided (2-24) that a `task` conversation's membership is COMPUTED: every read and
 * every post asks `TaskPolicy::view` about the linked task, in the same call the task list does,
 * and `conversation_members` holds read state and grants nothing.
 *
 * Phase 6 adds four types and **generalises that rule rather than bending it**. Every type has
 * a source to compute its audience from, and for none of them is that source
 * `conversation_members`:
 *
 *   - `task` — `TaskPolicy::view` on the linked task. Unchanged, down to the method it calls.
 *   - `project` — `ProjectPolicy::view` on the linked project. The same delegation, a different
 *     subject: an employee reaches a project channel exactly when they reach the project.
 *   - `team` and `announcement` — holding `messages.use`, and being active. A permission is the
 *     same kind of computed answer a policy is, and it is the one this codebase insists on in
 *     place of a role name (decisions 2-13, 2-31). The ACCOUNTANT is refused here without being
 *     mentioned, because they hold no `messages.use`.
 *   - `dm` — being one of the two users named on the conversation ROW (`dm_one_id`,
 *     `dm_two_id`), plus the same `messages.use`. The row is the source; the membership table
 *     is still read state.
 *
 * That is what keeps one sentence true with no exception: **nothing reads `conversation_members`
 * to decide anything**, for any type. A stale member row buys its holder zero in a DM, in a
 * project channel and in a task discussion alike.
 *
 * ## The three awkward cases are still free rather than handled
 *
 *   - a task REASSIGNED, a role changed, a user deactivated: the next request asks the policy,
 *     which now says no. No sync ran, because there is nothing to sync.
 *   - somebody taken OFF a project: their next read of its channel is refused by
 *     `ProjectPolicy::view`, and their `last_read_at` row is inert.
 *   - somebody DEACTIVATED: `isActive()` is the first line of every ability here.
 *
 * ## What was rejected
 *
 *   - *Stored membership for the new types, synced on project-member and role writes.* Correct
 *     only while every such write remembers the sync — and two of the three cases above touch
 *     no project row at all.
 *   - *A DM's two people as `conversation_members` rows.* It would have made that table the
 *     grant for one type out of five, which is the ambiguity 2-24 exists to prevent. The pair
 *     is on the conversation instead; see the `2026_09_27_000101` migration.
 *   - *Treating a member row as a grant "as well as" the computed answer.* Strictly wider than
 *     the computed answer, so the only rows it can add are the wrong ones.
 */
class ConversationPolicy extends Policy
{
    /**
     * Reading a conversation.
     *
     * Delegates to the SUBJECT's own policy where there is one, the way FilePolicy delegates to
     * a file's owner. A rule stated twice is a rule that can be changed once.
     */
    public function view(User $user, Conversation $conversation): bool
    {
        if (! $user->isActive() || ! $conversation->membershipIsComputed()) {
            return false;
        }

        $type = $conversation->type;

        if ($type === null) {
            return false;
        }

        // The task discussion is the Phase 2 rule, untouched — and deliberately NOT gated on
        // `messages.use`. It is reached from a task page, not from Messages, its audience is
        // already exactly "whoever may see this task", and the one role without `messages.use`
        // is the one role TaskPolicy refuses anyway. Adding the key here would change no
        // answer and would put a second reason in front of the first.
        if ($type === ConversationType::Task) {
            $subject = $this->subjectOf($conversation);

            return $subject !== null && Gate::forUser($user)->allows('view', $subject);
        }

        // Everything the Messages page lists takes the messaging key first.
        if (! $this->allows($user, Permission::MessagesUse)) {
            return false;
        }

        return match ($type) {
            // A project channel is the project, asked the same way its detail page asks.
            ConversationType::Project => ($subject = $this->subjectOf($conversation)) !== null
                && Gate::forUser($user)->allows('view', $subject),

            // A DM is its two columns and nothing else.
            ConversationType::Dm => $conversation->isDmParticipant($user),

            // Company-wide: the permission above was the whole question.
            ConversationType::Team, ConversationType::Announcement => true,

            ConversationType::Task => false,
        };
    }

    /**
     * Posting a message.
     *
     * The same answer as reading for four of the five types, deliberately: everybody in the room
     * may speak in it. A conversation where some readers cannot reply would need a second
     * concept — "observers" — which nothing in the spec asks for.
     *
     * The exception is `announcement`, which is a broadcast by definition: reading it is the
     * audience test above, writing it additionally takes `announcements.send`. That is the one
     * asymmetry, and it is stated on the TYPE (`everybodyMayPost()`) so the rule is not this
     * method's private opinion.
     *
     * Note what none of this is gated on: `update` on the subject. An ARCHIVED task or project
     * is read-only for every field, and a message is not a field — saying "this was archived by
     * mistake" where it happened is more useful than being silently unable to.
     */
    public function post(User $user, Conversation $conversation): bool
    {
        if (! $this->view($user, $conversation)) {
            return false;
        }

        if ($conversation->type?->everybodyMayPost() === true) {
            return true;
        }

        return $this->allows($user, Permission::AnnouncementsSend);
    }

    /**
     * The record a conversation's access is DELEGATED to, or null when there is not one.
     *
     * Null for a task or project conversation whose subject has gone, which ends at a denial:
     * a conversation whose access rule cannot be evaluated is one nobody may open.
     */
    private function subjectOf(Conversation $conversation): ?Model
    {
        return $conversation->type?->delegatesToSubject() === true
            ? $conversation->subject()
            : null;
    }
}
