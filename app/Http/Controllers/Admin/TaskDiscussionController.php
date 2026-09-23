<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ManagesDiscussion;
use App\Http\Controllers\Controller;
use App\Http\Requests\Conversation\StoreMessageRequest;
use App\Models\Task;
use App\Services\ConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A task's discussion on the Admin surface — the plan's "comments", stored as the messages of
 * the task's own `task` conversation.
 *
 * The task is resolved through Task::visibleTo() exactly as TaskController and
 * TaskFileController resolve it, so a task this requester may not see answers 404 before the
 * question of a discussion arises. Who may post is ConversationPolicy, which asks TaskPolicy
 * about the same task — never the surface the request arrived on.
 */
class TaskDiscussionController extends Controller
{
    use ManagesDiscussion;

    public function __construct(private readonly ConversationService $conversations) {}

    public function index(Request $request, Task $task): JsonResponse
    {
        return $this->discussionIndex($request, $task);
    }

    public function store(StoreMessageRequest $request, Task $task): RedirectResponse
    {
        return $this->discussionStore($request, $task);
    }
}
