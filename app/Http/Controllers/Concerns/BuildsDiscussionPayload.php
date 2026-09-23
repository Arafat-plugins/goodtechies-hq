<?php

namespace App\Http\Controllers\Concerns;

use App\Http\Resources\MessageResource;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * One shape for a task's discussion, wherever it is sent from.
 *
 * Four places send it: the two detail pages, which inline it into their Inertia props so the
 * panel has its thread on first paint, and the two `…/discussion` endpoints, which the panel
 * calls to refresh after posting. A payload built twice is a payload that gains a field on one
 * surface and not the other — this repo has already been through that with the tag pickers —
 * so it is built here and nowhere else.
 *
 * The using class supplies `private readonly ConversationService $conversations` through its
 * constructor, the way every other controller here takes its service.
 */
trait BuildsDiscussionPayload
{
    /**
     * The discussion, and where this person's unread line sits in it.
     *
     * The caller has ALREADY resolved the task through Task::visibleTo(). That is the whole of
     * the read rule, so nothing here re-asks it — but `can_post` does ask ConversationPolicy,
     * because whether you may speak in a room you can see is a different question, and the
     * panel needs to know before it draws a composer. The endpoint asks the same policy again
     * when a message actually arrives; this is for the UI, and the UI is never the enforcement
     * point.
     *
     * @return array<string, mixed>
     */
    private function discussionPayload(Request $request, Task $task): array
    {
        $conversation = $this->conversations->forTask($task);

        /** @var User $user */
        $user = $request->user();

        return [
            'conversation_id' => $conversation->id,
            'messages' => MessageResource::collection(
                $this->conversations->messages($conversation),
            )->toArray($request),
            'can_post' => $user->can('post', $conversation),
        ] + $this->conversations->readState($user, $conversation);
    }
}
