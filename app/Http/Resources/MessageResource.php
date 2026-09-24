<?php

namespace App\Http\Resources;

use App\Models\File;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
 * Deliberately absent: an `edited_at`, a `can_edit` and a `can_delete`. A message cannot be
 * changed or removed by anybody, so there is no permission to report and nothing for a control
 * to be wired to.
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

        return [
            'id' => $this->id,
            'body' => $this->body,
            'author' => $this->person($this->resource->author),
            'is_mine' => $user !== null
                && $this->author_id !== null
                && (int) $this->author_id === (int) $user->getKey(),
            'created_at' => $this->created_at?->toIso8601String(),
            'attachments' => $this->attachments($request),

            // Who this message named. The thread highlights them, and `mentions_me` is
            // resolved here for the same reason `is_mine` is: it is presentation, it depends on
            // who is asking, and a screen comparing ids to decide whether a line is addressed
            // to it would be a second copy of a fact the server already knows.
            //
            // A mention is NOT a permission — `ConversationPolicy` never reads
            // `message_mentions` — so this list is safe to send to everybody who can read the
            // thread: it names people they can already see named in the body above it.
            'mentions' => $this->mentions(),
            'mentions_me' => $user !== null && in_array((int) $user->getKey(), $this->mentionIds(), true),
        ];
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
                // How this file is shown: `image` renders inline, `file` is a download,
                // `voice` is Phase 6 and never written here.
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
