<?php

namespace App\Http\Resources;

use App\Models\Client;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * The only way a client leaves the server.
 *
 * The contact people are encrypted at rest and are commercial data: they and the internal
 * notes are **absent** unless the requester passes ClientPolicy::view.
 *
 * @mixin Client
 */
class ClientResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        $data = [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status?->value,
            // ClientStatus carries no label(): active/inactive read straight back as words.
            'status_label' => $this->status === null ? null : Str::headline($this->status->value),
            'projects_count' => $this->whenCounted('projects'),
            'projects' => ProjectResource::collection($this->whenLoaded('projects')),
            'permissions' => $this->permissions($user),
        ];

        if ($user !== null && Gate::forUser($user)->allows('view', $this->resource)) {
            $data['contacts'] = $this->contact_info ?? [];
            $data['internal_notes'] = $this->internal_notes;
        }

        return $data;
    }

    /**
     * What this requester may do to this client, answered by the policy rather than inferred.
     *
     * `ProjectResource` has carried one of these since Phase 1; the client payload did not,
     * and the Files tab on the client page had to assume "an Admin is the only role on this
     * surface, so reaching the page is the permission". That reasoning happened to be true
     * and is exactly the kind of thing that stops being true when a role is added — the
     * screen would keep showing an upload control that the endpoint then refuses. A key here
     * costs one gate call and removes the inference.
     *
     * @return array<string, bool>
     */
    private function permissions(?User $user): array
    {
        if ($user === null) {
            return ['can_update' => false, 'can_delete' => false];
        }

        $gate = Gate::forUser($user);

        return [
            // Attaching a file to a client takes ClientPolicy::update — see
            // FileService::guardMayAttach(), which asks the owner's own policy.
            'can_update' => $gate->allows('update', $this->resource),
            'can_delete' => $gate->allows('delete', $this->resource),
        ];
    }
}
