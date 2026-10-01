<?php

namespace App\Http\Controllers\Shared;

use App\Http\Controllers\Controller;
use App\Http\Requests\Messages\ToggleReactionRequest;
use App\Http\Requests\Messages\UpdateMessageRequest;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\ConversationService;
use App\Services\MessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Edit, delete-for-everyone and react (decision 12-79). JSON only.
 *
 * Refusals follow MessageController: a conversation this person may not open, or a message that
 * is not in the conversation the URL names, is **404**; a message they can see but may not
 * change is **403** from MessagePolicy.
 */
class MessageEditController extends Controller
{
    public function __construct(private readonly MessageService $messages) {}

    public function update(UpdateMessageRequest $request, Conversation $conversation, Message $message): JsonResponse
    {
        $message = $this->resolve($request, $conversation, $message);

        Gate::forUser($request->user())->authorize('update', $message);

        return $this->respond($request, $this->messages->edit($request->user(), $message, $request->body()));
    }

    public function destroy(Request $request, Conversation $conversation, Message $message): JsonResponse
    {
        $message = $this->resolve($request, $conversation, $message);

        Gate::forUser($request->user())->authorize('delete', $message);

        return $this->respond($request, $this->messages->delete($request->user(), $message));
    }

    public function react(ToggleReactionRequest $request, Conversation $conversation, Message $message): JsonResponse
    {
        $message = $this->resolve($request, $conversation, $message);

        Gate::forUser($request->user())->authorize('react', $message);

        return $this->respond($request, $this->messages->toggleReaction($request->user(), $message, $request->emoji()));
    }

    /**
     * 404 for a conversation this person may not open, and for a message from another one.
     */
    private function resolve(Request $request, Conversation $conversation, Message $message): Message
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless(Gate::forUser($user)->allows('view', $conversation), 404);
        abort_unless((int) $message->conversation_id === (int) $conversation->getKey(), 404);

        return $message->setRelation('conversation', $conversation);
    }

    private function respond(Request $request, Message $message): JsonResponse
    {
        $fresh = $message->fresh(ConversationService::MESSAGE_RELATIONS);

        return response()->json(['message' => (new MessageResource($fresh))->resolve($request)]);
    }
}
