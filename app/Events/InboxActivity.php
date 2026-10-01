<?php

namespace App\Events;

use App\Models\Conversation;
use App\Models\User;
use App\Support\Permission;
use App\Support\UserStatus;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Gate;

/**
 * A new message somewhere this reader can see — rung on each viewer's own
 * `private-notifications.{user}` channel (decision 12-78).
 *
 * ## A doorbell with ids only
 *
 * `ConversationActivity` rings the people who have a thread OPEN. This rings everybody else's
 * inbox: the Messages rail, the unread badge and the chime, which until now only moved on the
 * 15/30-second polls. It follows flow F1's rule exactly — the frame carries the conversation id
 * and the message id and nothing else, and the client answers it by re-reading the endpoints
 * that already decide what this person may see. No body, no author name, no conversation title.
 *
 * ## Recipients are decided by the policy, at send time
 *
 * `broadcastOn()` runs in the queue worker, after the post has committed. It asks
 * `ConversationPolicy::view` about every active user holding `messages.use` (minus the author),
 * so a DM rings its other participant, a project channel rings exactly the people who may see the
 * project, and the Accountant — who holds no `messages.use` — is never asked. No channel and no
 * permission were added: `notifications.{user}` already exists and is authorised to its owner.
 *
 * Queued and after commit for the same reasons as `ConversationActivity`: a stopped Reverb costs
 * a retried job, not a failed post, and a rolled-back post rings nobody.
 */
class InboxActivity implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $conversationId,
        public readonly int $messageId,
        public readonly int $authorId,
    ) {}

    /**
     * One `notifications.{user}` channel per active viewer the policy allows, author excluded.
     *
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $conversation = Conversation::query()->find($this->conversationId);

        if ($conversation === null) {
            return [];
        }

        return User::query()
            ->with('employee')
            ->where('status', UserStatus::Active)
            ->whereKeyNot($this->authorId)
            ->orderBy('id')
            ->get()
            ->filter(fn (User $user): bool => $user->hasPermission(Permission::MessagesUse))
            ->filter(fn (User $user): bool => Gate::forUser($user)->allows('view', $conversation))
            ->map(fn (User $user): PrivateChannel => new PrivateChannel('notifications.'.$user->getKey()))
            ->values()
            ->all();
    }

    public function broadcastAs(): string
    {
        return 'inbox.message';
    }

    /**
     * Exactly two keys; `InboxActivityTest` asserts the key set.
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
