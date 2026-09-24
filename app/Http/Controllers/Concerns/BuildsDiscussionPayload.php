<?php

namespace App\Http\Controllers\Concerns;

use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * One shape for a thread, wherever it is sent from.
 *
 * Six places send it now: the two task detail pages, which inline it into their Inertia props so
 * the panel has its thread on first paint; the two `…/discussion` endpoints, which the panel
 * calls to refresh after posting; the project Discussion tab; and the Messages page. A payload
 * built twice is a payload that gains a field on one surface and not the other — this repo has
 * already been through that with the tag pickers — so it is built here and nowhere else.
 *
 * The using class supplies `private readonly ConversationService $conversations` through its
 * constructor, the way every other controller here takes its service.
 */
trait BuildsDiscussionPayload
{
    /**
     * A task's discussion, in the shape the panel reads.
     *
     * The caller has ALREADY resolved the task through `Task::visibleTo()`. That is the whole of
     * the read rule, so nothing here re-asks it.
     *
     * @return array<string, mixed>
     */
    private function discussionPayload(Request $request, Task $task, ?int $before = null): array
    {
        return $this->threadPayload($request, $this->conversations->forTask($task), $before);
    }

    /**
     * Any conversation, in the shape the thread component reads.
     *
     * `can_post` asks ConversationPolicy, because whether you may speak in a room you can see is
     * a different question, and the composer needs to know before it draws itself. The endpoint
     * asks the same policy again when a message actually arrives; this is for the UI, and the UI
     * is never the enforcement point.
     *
     * `mentionable` is the picker's option list, and it is **the same set the server will accept
     * a mention from** — `ConversationService::mentionableIn()` computed once, used twice — so a
     * name the composer offers is never one the write then drops. It carries a name and an id
     * and nothing else: a mention picker is not a directory, and a role or an availability on it
     * would be a second, unpoliced copy of the Team page's payload.
     *
     * @return array<string, mixed>
     */
    private function threadPayload(Request $request, Conversation $conversation, ?int $before = null): array
    {
        /** @var User $user */
        $user = $request->user();

        $messages = $this->conversations->messages($conversation, $before);
        $oldest = $messages->first()?->getKey();

        return [
            'conversation_id' => $conversation->id,
            'type' => $conversation->type?->value,
            'label' => $conversation->labelFor($user),
            'messages' => MessageResource::collection($messages)->toArray($request),
            'has_more' => $this->conversations->hasOlderThan($conversation, $oldest === null ? null : (int) $oldest),
            'can_post' => $user->can('post', $conversation),
            'mentionable' => $this->conversations->mentionableIn($conversation)
                ->reject(fn (User $person): bool => (int) $person->getKey() === (int) $user->getKey())
                ->map(fn (User $person): array => ['id' => (int) $person->getKey(), 'name' => (string) $person->name])
                ->values()
                ->all(),
        ] + $this->conversations->readState($user, $conversation);
    }
}
