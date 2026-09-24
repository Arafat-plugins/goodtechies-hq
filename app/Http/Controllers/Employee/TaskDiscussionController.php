<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Concerns\ManagesDiscussion;
use App\Http\Controllers\Controller;
use App\Http\Requests\Conversation\StoreMessageRequest;
use App\Models\Task;
use App\Services\ConversationService;
use App\Services\MessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A task's discussion on the Employee surface — the plan's "comments", stored as the messages of
 * the task's own `task` conversation.
 *
 * The task is resolved through Task::visibleTo() exactly as TaskController and
 * TaskFileController resolve it, so a task this requester may not see answers 404 before the
 * question of a discussion arises. Who may post is ConversationPolicy, which asks TaskPolicy
 * about the same task — never the surface the request arrived on.
 *
 * This is where the spec's privacy line lands: "an employee sees the discussion only of tasks
 * they are assigned to". Nothing in this file says so. Task::visibleTo() does, once, for the
 * list, the board, the calendar, the attachments and now the discussion.
 */
class TaskDiscussionController extends Controller
{
    use ManagesDiscussion;

    public function __construct(
        private readonly ConversationService $conversations,
        private readonly MessageService $messages,
    ) {}

    public function index(Request $request, Task $task): JsonResponse
    {
        return $this->discussionIndex($request, $task);
    }

    public function store(StoreMessageRequest $request, Task $task): RedirectResponse
    {
        return $this->discussionStore($request, $task);
    }
}
