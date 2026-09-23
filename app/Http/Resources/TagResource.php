<?php

namespace App\Http\Resources;

use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * A tag as the MANAGEMENT panel needs it.
 *
 * Deliberately not the shape the pickers use. A picker — the tag chip on a board card, the
 * filter bar, the assign control on a task — carries `id`, `name`, `colour`, `is_global` and
 * nothing else, because that is all it can act on; those four are built inline by the task
 * controllers and are asserted key-for-key by tests/Feature/Resources/TaskResourceTest.php. A
 * management row needs three things a picker must never carry: which project owns the tag, how
 * many tasks are wearing it, and whether THIS person may edit or remove it.
 *
 * `task_count` is what makes the delete honest: TagService::delete() removes the label from
 * every one of those tasks, so the number is the blast radius and the panel has to be able to
 * say it before the click, not after.
 *
 * `permissions` mirrors TagPolicy so the panel can hide a control it could not use. The UI is
 * never the enforcement point; the endpoint asks the same policy.
 *
 * @mixin Tag
 */
class TagResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            // The token NAME — `progress`, `changes`, … — which app.css resolves per theme.
            // Never a hex: see App\Support\TagColour.
            'colour' => $this->colour?->value,
            'colour_label' => $this->colour?->label(),
            'is_global' => $this->resource->isGlobal(),

            // Null for a global tag. A name and an id, never a ProjectResource: this is a row
            // in a settings list, and a project's commercial fields have no business in it.
            'project' => $this->resource->project === null ? null : [
                'id' => $this->resource->project->id,
                'name' => $this->resource->project->name,
            ],

            // How many tasks would lose this label if it were deleted. Loaded by
            // TagService::manageable(); zero rather than absent when nobody asked for it, so a
            // panel never has to guess whether the key means "none" or "unknown".
            'task_count' => (int) ($this->tasks_count ?? 0),

            'permissions' => $this->permissions($request),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function permissions(Request $request): array
    {
        $user = $request->user();

        if ($user === null) {
            return ['can_update' => false, 'can_delete' => false];
        }

        $gate = Gate::forUser($user);

        return [
            'can_update' => $gate->allows('update', $this->resource),
            'can_delete' => $gate->allows('delete', $this->resource),
        ];
    }
}
