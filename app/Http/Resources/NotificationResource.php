<?php

namespace App\Http\Resources;

use App\Models\Notification;
use App\Models\Project;
use App\Models\Task;
use App\Support\Surface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Route;

/**
 * The only way a notification leaves the server.
 *
 * It sends what the bell draws and nothing else. In particular it does NOT send the raw
 * payload: the payload is the engine's storage format and gains a key whenever a new type needs
 * one, and a screen reading it directly would be reading a shape nobody promised it.
 *
 * ## The deep link is built here, not stored
 *
 * §11 asks for a "deep-link to the object", and the object's URL depends on the READER: the
 * same task is `/admin/tasks/41` for Shahadat and `/employee/tasks/41` for Tapu. A link written
 * into the payload at dispatch time would be one of those two forever, and would be the wrong
 * one for anybody whose role changed in between. So the payload stores the object — the morph
 * class and the id, the same pair `audit_logs` stores — and this resolves it against the
 * requester's own surface every time it is read.
 *
 * A link that does not resolve comes back null rather than as a guess. The Accountant reaches
 * no notification route at all, so their surface is here only for completeness.
 *
 * @mixin Notification
 */
class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type?->value,

            // Which Center tab this belongs on, and how loud it is. Both are facts about the
            // TYPE (see NotificationType) rather than columns, so they are resolved on the
            // server and the screen never maps one to the other itself.
            'tab' => $this->type?->tab()->value,
            'priority' => $this->type?->priority()->value,

            // The line the bell shows, in its singular or its grouped form — "New comment in
            // …" or "12 new comments in …". Composed by the type, because the grouped wording
            // is the visible half of the dedup rule and must not be re-derived from `count` by
            // a screen.
            'summary' => $this->resource->summary(),

            'title' => (string) ($this->payload['title'] ?? ''),
            'actor' => $this->payload['actor'] ?? null,

            // How many events this row stands for. 1 for an ordinary notification.
            'count' => (int) $this->count,

            'is_read' => (bool) $this->is_read,
            'read_at' => $this->read_at?->toIso8601String(),

            // When the group started, and when it last grew. They differ only for a grouped
            // row, which is exactly when the difference is worth having.
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            'link' => $this->link($request),
        ];
    }

    /**
     * The object's URL on the requester's own surface, or null when there is not one.
     */
    private function link(Request $request): ?string
    {
        $target = $this->resource->target();
        $surface = $request->user()?->surface();

        if ($target === null || $surface === null) {
            return null;
        }

        $prefix = match ($surface) {
            Surface::Admin => 'admin',
            Surface::Employee => 'employee',
            // The Accountant has no task or project screens at all (Part C: 403 on every
            // project route), so there is nowhere for a notification of theirs to point. They
            // cannot reach these endpoints in this phase either.
            Surface::Accountant => null,
        };

        if ($prefix === null) {
            return null;
        }

        $name = match ($target['type']) {
            Task::class => $prefix.'.tasks.show',
            Project::class => $prefix.'.projects.show',
            default => null,
        };

        // Route::has(), because a surface does not necessarily have a screen for every kind of
        // object a notification can be about — and a link to a route that does not exist is a
        // 500 on a page somebody was only glancing at.
        return $name !== null && Route::has($name) ? route($name, $target['id']) : null;
    }
}
