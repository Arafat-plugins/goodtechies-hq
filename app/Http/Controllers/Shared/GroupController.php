<?php

namespace App\Http\Controllers\Shared;

use App\Http\Controllers\Controller;
use App\Http\Requests\Messages\GroupMembersRequest;
use App\Http\Requests\Messages\StoreGroupRequest;
use App\Http\Requests\Messages\UpdateGroupRequest;
use App\Models\Conversation;
use App\Models\User;
use App\Services\GroupService;
use App\Support\ConversationType;
use App\Support\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Message groups (decision 12-81): create, rename / re-picture, add and remove people, and the
 * picture itself.
 *
 * - **403** for writes by anybody without `messages.manage` — the Form Requests say so.
 * - **404** for a `{conversation}` that is not a group, or a group the actor may not `view`:
 *   whether it exists is not something a non-member learns (Part C).
 */
class GroupController extends Controller
{
    public function __construct(
        private readonly GroupService $groups,
    ) {}

    public function store(StoreGroupRequest $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $group = $this->groups->create($actor, $request->name(), $request->memberIds(), $request->avatar());

        return response()->json(['conversation_id' => (int) $group->getKey()], 201);
    }

    public function update(UpdateGroupRequest $request, Conversation $conversation): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $group = $this->visibleGroup($request, $conversation);

        $group = $this->groups->update($actor, $group, $request->name(), $request->avatar(), $request->removeAvatar());

        return response()->json(['group' => self::present($group, $actor)]);
    }

    public function addMembers(GroupMembersRequest $request, Conversation $conversation): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $group = $this->groups->addMembers($actor, $this->visibleGroup($request, $conversation), $request->userIds());

        return response()->json(['group' => self::present($group, $actor)]);
    }

    public function removeMember(GroupMembersRequest $request, Conversation $conversation, User $user): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $group = $this->groups->removeMember($actor, $this->visibleGroup($request, $conversation), $user);

        // An Admin who removed themselves still gets the group back, as it now stands.
        return response()->json(['group' => self::present($group, $actor)]);
    }

    /**
     * Client doc 2026-10-05 item 8: delete the group, for a holder of `messages.manage`.
     */
    public function destroy(GroupMembersRequest $request, Conversation $conversation): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $this->groups->delete($actor, $this->visibleGroup($request, $conversation));

        return response()->json(['deleted' => true]);
    }

    /**
     * The group's picture, for its members only. 404 when there is none.
     */
    public function avatar(Request $request, Conversation $conversation): StreamedResponse
    {
        $group = $this->visibleGroup($request, $conversation);

        $path = $group->avatar_path;

        abort_if($path === null || ! Storage::exists($path), 404);

        $mime = Storage::mimeType($path) ?: 'application/octet-stream';

        return Storage::response($path, basename($path), [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age=86400',
        ], 'inline');
    }

    /**
     * The `group` block of the thread payload and of every write here (frontend contract).
     *
     * @return array{id: int, name: string, avatar_url: string|null, members: list<array{id: int, name: string}>, can_manage: bool}
     */
    public static function present(Conversation $group, User $viewer): array
    {
        $group->loadMissing('groupMembers');

        return [
            'id' => (int) $group->getKey(),
            'name' => (string) $group->title,
            'avatar_url' => self::avatarUrl($group),
            'members' => $group->groupMembers
                ->map(fn (User $member): array => ['id' => (int) $member->getKey(), 'name' => (string) $member->name])
                ->values()
                ->all(),
            'can_manage' => $viewer->hasPermission(Permission::MessagesManage),
        ];
    }

    /**
     * The picture's URL, or null. The `v` query changes with the stored file, so a replaced
     * picture is not served from the browser's day-long cache.
     */
    public static function avatarUrl(Conversation $group): ?string
    {
        if ($group->type !== ConversationType::Group || $group->avatar_path === null) {
            return null;
        }

        return route('messages.groups.avatar', [
            'conversation' => $group->getKey(),
            'v' => substr(md5((string) $group->avatar_path), 0, 8),
        ], false);
    }

    private function visibleGroup(Request $request, Conversation $conversation): Conversation
    {
        abort_unless(
            $conversation->type === ConversationType::Group
                && Gate::forUser($request->user())->allows('view', $conversation),
            404,
        );

        return $conversation;
    }
}
