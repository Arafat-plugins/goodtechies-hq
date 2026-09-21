<?php

namespace App\Http\Resources;

use App\Models\Client;
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
        ];

        if ($user !== null && Gate::forUser($user)->allows('view', $this->resource)) {
            $data['contacts'] = $this->contact_info ?? [];
            $data['internal_notes'] = $this->internal_notes;
        }

        return $data;
    }
}
