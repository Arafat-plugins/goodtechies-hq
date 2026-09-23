<?php

namespace App\Http\Controllers\Concerns;

use App\Exceptions\ConversationStateException;
use App\Exceptions\FileStateException;
use App\Http\Requests\Conversation\StoreMessageRequest;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The task discussion endpoints' plumbing, shared by the two surfaces that expose them.
 *
 * Two controllers, one trait, for the same reason the file endpoints have six and one: the
 * discussion is reached from the Admin task page and the Employee task page, and it is the same
 * two operations either way. Identical code on both surfaces is what stops them developing
 * different ideas about who may read a thread.
 *
 * ## Why the task is resolved through Task::visibleTo() first
 *
 * Because the answer to "may I see this discussion" IS "may I see this task", and the way this
 * codebase says no to a record you may not see is to fail to find it. `visibleTask()` is
 * character for character what TaskFileController does, so an employee asking for the
 * discussion of a task they are not assigned to gets the same 404 they get for its attachments,
 * its checklist and the task itself — not a 403, which would confirm the task exists.
 *
 * ConversationPolicy is then asked as well, inside the service. That is not redundant: the
 * controller's query is the list-shaped half of the rule and the policy is the object-shaped
 * half, and a service called from a job or a console command only ever meets the second.
 *
 * ## Why the list is JSON
 *
 * The discussion panel is a later brief's Vue file. The payload is also inlined into the task
 * detail page's props (see `discussionPayload()`), so the panel has its messages on first paint
 * and calls this endpoint only to refresh after posting.
 */
trait ManagesDiscussion
{
    // The payload shape itself is shared with the two detail pages, which inline it into
    // their props — see BuildsDiscussionPayload.
    use BuildsDiscussionPayload;

    /**
     * The discussion, and this person's place in it. Opening it marks it read.
     */
    private function discussionIndex(Request $request, Task $task): JsonResponse
    {
        $task = $this->visibleTask($request, $task);
        $conversation = $this->conversations->forTask($task);

        /** @var User $user */
        $user = $request->user();

        // Marking read on a GET is a write on a read, which is worth being deliberate about:
        // this endpoint is the panel SAYING it has displayed the thread, which is exactly the
        // event `last_read_at` records. The task detail page does not do it — rendering a page
        // that happens to contain a panel is not the same as reading it.
        $this->conversations->markRead($user, $conversation);

        return response()->json($this->discussionPayload($request, $task));
    }

    /**
     * Post a message, with or without a file on it.
     */
    private function discussionStore(StoreMessageRequest $request, Task $task): RedirectResponse
    {
        $task = $this->visibleTask($request, $task);
        $conversation = $this->conversations->forTask($task);

        try {
            $this->conversations->post(
                $request->user(),
                $conversation,
                $request->body(),
                $request->upload(),
            );
        } catch (ConversationStateException|FileStateException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Message posted.');
    }

    /**
     * The task, if this requester may see it at all. A record they may not see is absent, so
     * this is a 404 and never a 403 — and that is the whole of the discussion's read rule.
     */
    private function visibleTask(Request $request, Task $task): Task
    {
        return Task::query()
            ->visibleTo($request->user())
            ->whereKey($task->getKey())
            ->firstOrFail();
    }
}
