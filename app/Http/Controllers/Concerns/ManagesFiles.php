<?php

namespace App\Http\Controllers\Concerns;

use App\Exceptions\FileStateException;
use App\Http\Requests\File\StoreFileRequest;
use App\Http\Resources\FileResource;
use App\Models\File;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The file endpoints' plumbing, shared by the six thin controllers that expose them.
 *
 * Six controllers because there are six places a file is reached from — a task on each of the
 * two surfaces, a project, a client, and the per-file actions on each surface — and one trait
 * because they are the same four operations every time. The alternative is the same twenty
 * lines copied six times, which is how the project Files tab ends up with a slightly different
 * idea of what "delete" means from the task panel's.
 *
 * Nothing here decides anything. Visibility is the owning record's policy, resolved by the
 * caller before it gets here; the write rules are FileService's and FilePolicy's. This is the
 * HTTP shape around them: resolve, call, turn a state refusal into a flash error, redirect back.
 *
 * The using class supplies `private readonly FileService $files` through its constructor, the
 * way every other controller here takes its service.
 */
trait ManagesFiles
{
    /**
     * Everything a record owns, current versions only, newest first.
     *
     * JSON rather than an Inertia page: this is the endpoint the attachment panel and the two
     * Files tabs will call, and those panels do not exist yet. A page would have to be invented
     * now and thrown away by the brief that builds them.
     */
    private function fileIndex(Request $request, Model $owner): JsonResponse
    {
        return response()->json([
            'files' => FileResource::collection(
                $this->files->for($owner),
            )->toArray($request),
        ]);
    }

    /**
     * One file's version history: the whole chain, oldest first, including the current version.
     *
     * JSON for the same reason `fileIndex` is — it is what `FilePanel.vue`'s "Version history"
     * disclosure fetches when somebody opens it, which is the only moment anybody wants it. A
     * Files tab lists current versions; history is asked for one file at a time, so putting it
     * in the index payload would mint a signed URL and run the policy twice per superseded row
     * on every render of every tab, for a disclosure most readers never open.
     *
     * The chain is read through `FileService::history()`, which resolves it from `chainId()`.
     * It is NOT `File::versions()` and could not be: `version_of` holds the ROOT's id on every
     * row, so the relation is empty on every row except the root — and the root is superseded
     * the moment a replacement exists, so `FileService::for()` never hands one out. That is the
     * defect this endpoint closes, and the reason `FileResource` no longer carries a `versions`
     * key.
     *
     * ## Visibility
     *
     * The caller has already run `visibleFile()`, so a file the requester may not see answered
     * 404 before this ran — including the COUNT of its versions, which is the half of the rule
     * a history endpoint could quietly break.
     *
     * One check covers the chain because a chain has one owner: `FileService::replace()` takes
     * the owner off the row being replaced and `write()` stamps that same owner column on the
     * new row, so every version of a file hangs off exactly the record the requested version
     * hangs off. There is no shortcut here either — each row's `url` is minted by the same
     * `temporarySignedRoute`, and `GET /files/{file}` re-runs `FilePolicy::view` on the fetch.
     * An older version's link is subject to exactly the check a current one's is.
     *
     * Rows that were deleted are absent: `delete()` takes the bytes, and a link to them would
     * 404 at the disk. SoftDeletes leaves them out of this query for free.
     */
    private function fileHistory(Request $request, File $file): JsonResponse
    {
        $history = $this->files->history($file);

        // The same eager load `visibleFile()` does for the one row, for the rest of the chain:
        // FileResource asks FilePolicy per row and FilePolicy asks the OWNER's policy, so
        // without this each superseded row resolves its owner with its own query.
        $history->loadMissing(['task', 'project', 'client', 'message', 'uploader']);

        return response()->json([
            // `versions`, not `files`: this is one file's chain and not a record's collection,
            // and the two answer different questions with the same shape of row.
            'versions' => FileResource::collection($history)->toArray($request),
        ]);
    }

    private function fileStore(StoreFileRequest $request, Model $owner, string $success): RedirectResponse
    {
        try {
            $this->files->store($request->user(), $owner, $request->upload());
        } catch (FileStateException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', $success);
    }

    /**
     * Upload a new version of an existing file. The old one is superseded, never overwritten.
     */
    private function fileVersion(StoreFileRequest $request, File $file): RedirectResponse
    {
        try {
            $new = $this->files->replace($request->user(), $file, $request->upload());
        } catch (FileStateException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'New version uploaded (version '.$new->version.').');
    }

    private function fileDestroy(Request $request, File $file): RedirectResponse
    {
        try {
            $this->files->delete($request->user(), $file);
        } catch (FileStateException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'File deleted.');
    }

    /**
     * The file, if this requester may see it at all.
     *
     * 404 and never 403: a file hangs off a record, and a record they may not see is absent —
     * so its attachments have to be absent in exactly the same way, or the count of files on an
     * invisible task becomes a way of asking questions about it.
     *
     * The `delete` and `replace` rules are checked afterwards, by the service, and those DO
     * answer 403: by then the requester has been told the file exists, and "you may not remove
     * somebody else's upload" is a fact about them rather than about the record.
     */
    private function visibleFile(Request $request, File $file): File
    {
        // `message` joined the list in slice 4: a message attachment is a file like any other,
        // and its owner has to be loaded for FilePolicy to delegate to it.
        $file->loadMissing(['task', 'project', 'client', 'message', 'uploader']);

        abort_unless(
            $request->user() !== null && $request->user()->can('view', $file),
            404,
        );

        return $file;
    }
}
