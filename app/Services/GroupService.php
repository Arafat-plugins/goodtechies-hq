<?php

namespace App\Services;

use App\Events\ConversationActivity;
use App\Models\Conversation;
use App\Models\User;
use App\Support\AuditEvent;
use App\Support\ConversationType;
use App\Support\Permission;
use App\Support\UserStatus;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Message groups (decision 12-81): a named chat a holder of `messages.manage` creates for the
 * people they choose, with an optional picture.
 *
 * The only writer of `conversation_group_members`. Every write is one transaction and one audit
 * row, and rings `ConversationActivity` with message id 0 and kind `group` so open screens
 * re-read. Authorization (`messages.manage`, and `view` on the group) is the caller's: the Form
 * Requests and GroupController.
 */
class GroupService
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Create a group. Its members are the ids given plus the actor.
     *
     * @param  list<int>  $memberIds
     */
    public function create(User $actor, string $name, array $memberIds, ?UploadedFile $avatar): Conversation
    {
        $name = trim($name);
        $ids = $this->normaliseIds([...$memberIds, (int) $actor->getKey()]);

        if (count($ids) < 2) {
            throw ValidationException::withMessages([
                'member_ids' => 'Choose at least one person besides yourself.',
            ]);
        }

        $this->assertMessageable($ids, 'member_ids');

        $stored = null;

        try {
            return DB::transaction(function () use ($actor, $name, $ids, $avatar, &$stored): Conversation {
                $group = Conversation::create([
                    'type' => ConversationType::Group,
                    'title' => $name,
                    'created_by' => $actor->getKey(),
                ]);

                if ($avatar !== null) {
                    $stored = $this->storeAvatar($group, $avatar);
                    $group->forceFill(['avatar_path' => $stored])->save();
                }

                $group->groupMembers()->attach($this->pivotRows($ids, $actor));

                $this->audit->record(
                    AuditEvent::GroupCreated,
                    $group,
                    null,
                    [
                        'name' => $name,
                        'member_ids' => $ids,
                        'avatar' => $stored !== null,
                    ],
                    $actor,
                );

                $this->ring($group);

                return $group;
            });
        } catch (\Throwable $exception) {
            if ($stored !== null) {
                Storage::delete($stored);
            }

            throw $exception;
        }
    }

    /**
     * Rename the group, replace its picture, or remove it.
     */
    public function update(User $actor, Conversation $group, ?string $name, ?UploadedFile $avatar, bool $removeAvatar): Conversation
    {
        $stored = null;
        $previous = $group->avatar_path;

        try {
            $updated = DB::transaction(function () use ($actor, $group, $name, $avatar, $removeAvatar, &$stored, $previous): Conversation {
                $old = ['name' => $group->title, 'avatar' => $previous !== null];
                $changes = [];

                if ($name !== null && trim($name) !== '') {
                    $changes['title'] = trim($name);
                }

                if ($avatar !== null) {
                    $stored = $this->storeAvatar($group, $avatar);
                    $changes['avatar_path'] = $stored;
                } elseif ($removeAvatar) {
                    $changes['avatar_path'] = null;
                }

                $group->forceFill($changes)->save();

                $this->audit->record(
                    AuditEvent::GroupUpdated,
                    $group,
                    $old,
                    ['name' => $group->title, 'avatar' => $group->avatar_path !== null],
                    $actor,
                );

                $this->ring($group);

                return $group;
            });
        } catch (\Throwable $exception) {
            if ($stored !== null) {
                Storage::delete($stored);
            }

            throw $exception;
        }

        // The previous picture goes only once the new state is committed.
        if ($previous !== null && $previous !== $updated->avatar_path) {
            Storage::delete($previous);
        }

        return $updated;
    }

    /**
     * Add people to the group. Somebody already in it is left as they are.
     *
     * @param  list<int>  $userIds
     */
    public function addMembers(User $actor, Conversation $group, array $userIds): Conversation
    {
        $ids = $this->normaliseIds($userIds);

        $this->assertMessageable($ids, 'user_ids');

        return DB::transaction(function () use ($actor, $group, $ids): Conversation {
            $before = $this->memberIds($group);
            $added = array_values(array_diff($ids, $before));

            if ($added !== []) {
                $group->groupMembers()->attach($this->pivotRows($added, $actor));
            }

            $this->audit->record(
                AuditEvent::GroupMembersChanged,
                $group,
                ['member_ids' => $before],
                ['member_ids' => $this->memberIds($group), 'added' => $added],
                $actor,
            );

            $this->ring($group);

            return $group->unsetRelation('groupMembers');
        });
    }

    /**
     * Remove one person. The last member cannot be removed; an Admin may remove themselves.
     */
    public function removeMember(User $actor, Conversation $group, User $user): Conversation
    {
        return DB::transaction(function () use ($actor, $group, $user): Conversation {
            // Lock the group row so two removals cannot each see "two left" and empty it.
            Conversation::query()->whereKey($group->getKey())->lockForUpdate()->first();

            $before = $this->memberIds($group);

            if (! in_array((int) $user->getKey(), $before, true)) {
                throw ValidationException::withMessages([
                    'user' => 'That person is not in this group.',
                ]);
            }

            if (count($before) <= 1) {
                throw ValidationException::withMessages([
                    'user' => 'The last member of a group cannot be removed.',
                ]);
            }

            $group->groupMembers()->detach($user->getKey());

            $this->audit->record(
                AuditEvent::GroupMembersChanged,
                $group,
                ['member_ids' => $before],
                ['member_ids' => $this->memberIds($group), 'removed' => [(int) $user->getKey()]],
                $actor,
            );

            $this->ring($group);

            return $group->unsetRelation('groupMembers');
        });
    }

    /**
     * Client doc 2026-10-05 item 8: delete the group — its messages, reactions, mentions and
     * attachment rows go with it (every one of those foreign keys cascades from the
     * conversation). The audit row keeps who deleted what, with the member list and the
     * message count; the stored attachment files and the picture leave the disk only once the
     * delete has committed.
     */
    public function delete(User $actor, Conversation $group): void
    {
        $paths = DB::transaction(function () use ($actor, $group): array {
            Conversation::query()->whereKey($group->getKey())->lockForUpdate()->first();

            $messageIds = DB::table('messages')->where('conversation_id', $group->getKey())->pluck('id');
            $paths = DB::table('files')->whereIn('message_id', $messageIds)->pluck('path')->filter()->values()->all();

            if ($group->avatar_path !== null) {
                $paths[] = $group->avatar_path;
            }

            $this->audit->record(
                AuditEvent::GroupDeleted,
                $group,
                [
                    'name' => $group->title,
                    'member_ids' => $this->memberIds($group),
                    'messages' => $messageIds->count(),
                ],
                null,
                $actor,
            );

            $id = (int) $group->getKey();
            $group->delete();

            // Open screens re-read; the thread they had is now a 404 and they leave it.
            ConversationActivity::dispatch($id, 0, 'group');

            return $paths;
        });

        foreach ($paths as $path) {
            Storage::delete($path);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | The pieces
    |--------------------------------------------------------------------------
    */

    /**
     * Every id must be an active user holding `messages.use` — the same audience a group's
     * `ConversationPolicy::view` arm would let in.
     *
     * @param  list<int>  $ids
     */
    private function assertMessageable(array $ids, string $field): void
    {
        $users = User::query()
            ->whereKey($ids)
            ->where('status', UserStatus::Active->value)
            ->get();

        $eligible = $users->filter(fn (User $user): bool => $user->hasPermission(Permission::MessagesUse));

        if ($eligible->count() !== count($ids)) {
            throw ValidationException::withMessages([
                $field => 'Everybody in a group must be an active user who can use messaging.',
            ]);
        }
    }

    /**
     * @param  array<int, int|string>  $ids
     * @return list<int>
     */
    private function normaliseIds(array $ids): array
    {
        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array{added_by: int}>
     */
    private function pivotRows(array $ids, User $actor): array
    {
        $rows = [];

        foreach ($ids as $id) {
            $rows[$id] = ['added_by' => (int) $actor->getKey()];
        }

        return $rows;
    }

    /**
     * @return list<int>
     */
    private function memberIds(Conversation $group): array
    {
        return DB::table('conversation_group_members')
            ->where('conversation_id', $group->getKey())
            ->orderBy('user_id')
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private function storeAvatar(Conversation $group, UploadedFile $avatar): string
    {
        $extension = strtolower($avatar->guessExtension() ?: $avatar->getClientOriginalExtension() ?: 'png');
        $name = $group->getKey().'-'.Str::random(16).'.'.$extension;

        $path = Storage::putFileAs('group-avatars', $avatar, $name);

        if ($path === false) {
            throw new \RuntimeException('The group picture could not be stored.');
        }

        return $path;
    }

    private function ring(Conversation $group): void
    {
        ConversationActivity::dispatch((int) $group->getKey(), 0, 'group');
    }
}
