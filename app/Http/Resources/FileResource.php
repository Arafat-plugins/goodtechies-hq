<?php

namespace App\Http\Resources;

use App\Models\File;
use App\Models\User;
use App\Services\FileService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * The only way a file leaves the server — the task attachment panel, the project Files tab and
 * the client Files tab all read this one shape, because they are all the same thing.
 *
 * Two fields are not columns and could not be:
 *
 *   - `url` is minted per request, signed and expiring. It is not stored anywhere, because a
 *     stored URL outlives its own signature. `url_expires_at` rides beside it so a page that
 *     has been open for an hour can tell that its links are stale rather than discovering it
 *     one click at a time.
 *   - `permissions.can_delete` mirrors FilePolicy so the panel hides a control it could not
 *     use. The UI is never the enforcement point; the endpoint checks the same policy.
 *
 * `path`, `disk` and `checksum` are deliberately absent. The first two are where the bytes are,
 * which is nobody's business outside FileService, and the third is evidence for an audit
 * reader rather than something a Files tab does anything with.
 *
 * ## Why there is no `versions` key
 *
 * There was one, fed by `whenLoaded('versions')`, and it never resolved to anything. The
 * relation behind it is `hasMany(File::class, 'version_of')`, and `version_of` holds the ROOT's
 * id on every row of a chain — so it is empty on every row except the root, and the root is
 * superseded the instant a replacement exists, which is precisely when `FileService::for()`
 * stops returning it. Every caller therefore got a key that was either absent or `[]`, and the
 * panel's "Earlier versions" disclosure was unreachable code hanging off it.
 *
 * A key that only ever resolves to nothing is a promise the payload does not keep, so it is
 * gone rather than patched. A chain is not a property of one of its rows: it is resolved from
 * `File::chainId()` by `FileService::history()` and served by
 * `ManagesFiles::fileHistory()` — `GET /{surface}/files/{file}/versions` — which returns the
 * whole chain, oldest first, as a flat list of THIS resource. Each row there carries its own
 * signed `url`, its own `uploaded_by`, and its own `permissions`, answered by the policy for
 * that row: a superseded version is not deletable or replaceable because it is old, and
 * `is_current` is what says which row is the head. Nesting the same shape inside itself would
 * have given every row two sets of permissions and two expiries to keep straight.
 *
 * @mixin File
 */
class FileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $files = app(FileService::class);
        $expiresAt = $files->expiry();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'extension' => $this->extension,
            'mime_type' => $this->mime_type,
            'size' => (int) $this->size,
            'size_label' => $this->resource->sizeLabel(),

            // What the panel may draw in place rather than offer as a download. Decided on the
            // server from the same list the download response sets its disposition from, so a
            // preview is never attempted for something that would arrive as an attachment.
            'is_image' => $this->resource->isImage(),
            'is_pdf' => $this->resource->isPdf(),
            'is_previewable' => $this->resource->isInlineRenderable(),

            'version' => (int) $this->version,
            'is_current' => $this->resource->isCurrent(),
            'superseded_at' => $this->superseded_at?->toIso8601String(),

            'uploaded_by' => $this->person($this->resource->uploader),
            'uploaded_at' => $this->created_at?->toIso8601String(),

            'url' => $files->url($this->resource, $expiresAt),
            'url_expires_at' => $expiresAt->toIso8601String(),

            'permissions' => $this->permissions($request->user()),
        ];
    }

    /**
     * @return array{id: int, name: string|null}|null
     */
    private function person(?User $user): ?array
    {
        return $user === null ? null : ['id' => $user->id, 'name' => $user->name];
    }

    /**
     * @return array<string, bool>
     */
    private function permissions(?User $user): array
    {
        if ($user === null) {
            return ['can_delete' => false, 'can_replace' => false];
        }

        $gate = Gate::forUser($user);

        return [
            'can_delete' => $gate->allows('delete', $this->resource),
            'can_replace' => $gate->allows('replace', $this->resource),
        ];
    }
}
