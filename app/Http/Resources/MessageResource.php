<?php

namespace App\Http\Resources;

use App\Models\Conversation;
use App\Models\File;
use App\Models\Message;
use App\Models\MessageReaction;
use App\Models\User;
use App\Support\ConversationType;
use App\Support\Permission;
use App\Support\UnreadLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * The only way a message leaves the server.
 *
 * The attachments go through FileResource, unchanged — the same signed expiring URL, the same
 * `is_previewable`, the same `permissions` block the task attachment panel gets — with `kind`
 * and `duration_seconds` from the `message_attachments` pivot laid beside it. Those two are how
 * the file rides on the bubble rather than facts about the file, which is why they are here and
 * not inside FileResource.
 *
 * `is_mine` is resolved on the server so the panel does not have to compare ids to decide which
 * side of the thread a bubble sits on. It is presentation, and it is the only thing in this
 * payload that depends on who is asking.
 *
 * Since 12-79 a message may be edited and deleted-for-everyone, and carries reactions and (for
 * the viewer's own DM messages) a `seen` flag. A deleted message blanks its content keys. The
 * per-conversation facts `can_edit` / `can_delete` / `seen` need are computed once per request
 * (see `context()`), so a thread costs a constant number of queries.
 *
 * @mixin Message
 */
class MessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $deleted = $this->resource->isDeleted();
        $mine = $user !== null
            && $this->author_id !== null
            && (int) $this->author_id === (int) $user->getKey();
        $context = $this->context($request);

        return [
            'id' => $this->id,
            'body' => $deleted ? null : $this->body,
            'author' => $this->person($this->resource->author),
            'is_mine' => $mine,
            // Milliseconds, not `toIso8601String()`'s whole seconds: the thread compares this
            // to `last_read_at` to draw the new-messages line, and at second precision a reply
            // posted in the same second as a read arrived in the browser EQUAL to it and was
            // drawn as already-read (decision M-15 — see App\Support\UnreadLine).
            'created_at' => UnreadLine::iso($this->created_at),
            'attachments' => $deleted ? [] : $this->attachments($request),

            // Who this message named. The thread highlights them, and `mentions_me` is
            // resolved here for the same reason `is_mine` is: it is presentation, it depends on
            // who is asking, and a screen comparing ids to decide whether a line is addressed
            // to it would be a second copy of a fact the server already knows.
            //
            // A mention is NOT a permission — `ConversationPolicy` never reads
            // `message_mentions` — so this list is safe to send to everybody who can read the
            // thread: it names people they can already see named in the body above it.
            'mentions' => $deleted ? [] : $this->mentions(),
            'mentions_me' => ! $deleted && $user !== null && in_array((int) $user->getKey(), $this->mentionIds(), true),

            // 12-79: edit / delete-for-everyone, reactions and the DM "seen" tick.
            'edited_at' => $this->edited_at?->toIso8601String(),
            'is_deleted' => $deleted,
            'can_edit' => $mine
                && ! $deleted
                && trim((string) $this->body) !== ''
                && $context['can_post'],
            'can_delete' => ! $deleted && (
                $mine
                || ($context['may_manage'] && $context['type'] !== ConversationType::Dm)
            ),
            'reactions' => $deleted ? [] : $this->reactions($user),
            'seen' => $mine && $context['type'] === ConversationType::Dm
                ? $context['peer_last_read_at'] !== null && $this->created_at !== null
                    && $context['peer_last_read_at']->greaterThanOrEqualTo($this->created_at)
                : null,
        ];
    }

    /**
     * The facts about this message's conversation the per-viewer keys need, computed once per
     * conversation per request and kept on the request.
     *
     * `peer_last_read_at` is taken from the request attribute `peer_last_read_at` when the
     * controller set it (MessageController::show reads it alongside the `peer`), and otherwise
     * read here with one query.
     *
     * @return array{type: ConversationType|null, can_post: bool, may_manage: bool, peer_last_read_at: Carbon|null}
     */
    private function context(Request $request): array
    {
        $id = (int) $this->conversation_id;
        $all = (array) $request->attributes->get('message_context', []);

        if (isset($all[$id])) {
            return $all[$id];
        }

        $user = $request->user();
        $conversation = $this->resource->relationLoaded('conversation')
            ? $this->resource->conversation
            : Conversation::query()->find($id);

        $type = $conversation?->type;
        $peerLastReadAt = null;

        if ($user !== null && $conversation !== null && $type === ConversationType::Dm) {
            if ($request->attributes->has('peer_last_read_at')) {
                $peerLastReadAt = $request->attributes->get('peer_last_read_at');
            } else {
                $peer = $conversation->dmCounterpartFor($user);
                $raw = $peer === null ? null : $conversation->members()->whereKey($peer->getKey())->first()?->pivot?->last_read_at;
                $peerLastReadAt = $raw === null ? null : Carbon::parse($raw);
            }
        }

        $all[$id] = [
            'type' => $type,
            'can_post' => $user !== null && $conversation !== null && $user->can('post', $conversation),
            'may_manage' => $user !== null && $user->isActive() && $user->hasPermission(Permission::MessagesManage),
            'peer_last_read_at' => $peerLastReadAt instanceof Carbon ? $peerLastReadAt : null,
        ];

        $request->attributes->set('message_context', $all);

        return $all[$id];
    }

    /**
     * Grouped by emoji in first-reaction order, each with the first ten reactors' names.
     *
     * @return list<array{emoji: string, count: int, mine: bool, names: list<string>}>
     */
    private function reactions(?User $user): array
    {
        if (! $this->resource->relationLoaded('reactions')) {
            return [];
        }

        return $this->resource->reactions
            ->groupBy('emoji')
            ->map(fn ($group, $emoji): array => [
                'emoji' => (string) $emoji,
                'count' => $group->count(),
                'mine' => $user !== null && $group->contains(
                    fn (MessageReaction $reaction): bool => (int) $reaction->user_id === (int) $user->getKey(),
                ),
                'names' => $group->take(10)
                    ->map(fn (MessageReaction $reaction): string => (string) $reaction->user?->name)
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: int, name: string|null}>
     */
    private function mentions(): array
    {
        if (! $this->resource->relationLoaded('mentions')) {
            return [];
        }

        return $this->resource->mentions
            ->map(fn (User $user): array => ['id' => (int) $user->getKey(), 'name' => $user->name])
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    private function mentionIds(): array
    {
        return array_map(
            static fn (array $person): int => $person['id'],
            $this->mentions(),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function attachments(Request $request): array
    {
        if (! $this->resource->relationLoaded('attachments')) {
            return [];
        }

        return $this->resource->attachments
            ->map(fn (File $file): array => (new FileResource($file))->resolve($request) + [
                // How this file is shown: `image` renders inline, `file` is a download, and
                // `voice` gets the player — the one kind the composer asserts rather than the
                // server deriving it, because no MIME type tells a recording from an upload.
                'kind' => $file->pivot?->kind,
                'duration_seconds' => $file->pivot?->duration_seconds === null
                    ? null
                    : (int) $file->pivot->duration_seconds,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{id: int, name: string|null}|null
     */
    private function person(?User $user): ?array
    {
        return $user === null ? null : ['id' => $user->id, 'name' => $user->name];
    }
}
