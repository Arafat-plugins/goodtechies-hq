<?php

namespace App\Http\Controllers\Shared;

use App\Exceptions\FileStateException;
use App\Http\Controllers\Controller;
use App\Models\File;
use App\Services\FileService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Fetching the bytes: one route for every file, on no surface in particular.
 *
 * It is shared rather than duplicated per surface because the answer does not depend on the
 * surface. A file is as visible as the record it hangs off, and that is a fact about the
 * requester and the record — the same employee gets the same answer whichever shell they
 * happen to be in, and a route per surface would be three places for that to stop being true.
 *
 * The route carries `signed`, so a link that has been edited or has expired never reaches this
 * method: the middleware answers 403 first. What happens HERE is the other half, and it is the
 * half that matters. The signature does not say who may look — it says the link has not been
 * tampered with and has not run out. So the policy runs again, on every single fetch, against
 * whoever is actually holding the link.
 *
 * That is the deliberate choice recorded in FileService: a signed URL is not a bearer token
 * here. Forward one to somebody who may not see the task and they get a 404 — the same 404 the
 * task itself would give them — for as long as the link lives and afterwards.
 */
class FileDownloadController extends Controller
{
    public function __construct(private readonly FileService $files) {}

    public function __invoke(Request $request, File $file): StreamedResponse
    {
        $file->loadMissing(['task', 'project', 'client']);

        // 404, not 403: a file on a record they may not see is absent, exactly as the record is.
        abort_unless(
            $request->user() !== null && $request->user()->can('view', $file),
            404,
        );

        try {
            return $this->files->download($file);
        } catch (FileStateException) {
            // The row says there are bytes and the disk disagrees. That is a 404 about the
            // file, not a 500 about us — and it is what a download of a row whose blob was
            // already removed looks like.
            abort(404);
        }
    }
}
