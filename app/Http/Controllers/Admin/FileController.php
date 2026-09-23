<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ManagesFiles;
use App\Http\Controllers\Controller;
use App\Http\Requests\File\StoreFileRequest;
use App\Models\File;
use App\Services\FileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The per-file actions on the Admin surface: read the version history, upload a new version,
 * delete one.
 *
 * Addressed by the FILE rather than by its owner, because they are the same two actions
 * whichever of the three kinds of record the file hangs off — and a route per owner would be
 * six more routes saying the same thing. Which record it is, and therefore who may touch it,
 * comes off the row: `visibleFile()` asks FilePolicy, which asks the owner's policy.
 */
class FileController extends Controller
{
    use ManagesFiles;

    public function __construct(private readonly FileService $files) {}

    /**
     * This file's version history, oldest first and including the current version.
     *
     * A GET beside the POST that creates a version, on the same `{file}` — replacing and
     * reading the chain are the same act whichever kind of record owns the file, which is why
     * both hang off the file rather than off six owner routes.
     */
    public function versions(Request $request, File $file): JsonResponse
    {
        return $this->fileHistory($request, $this->visibleFile($request, $file));
    }

    public function storeVersion(StoreFileRequest $request, File $file): RedirectResponse
    {
        return $this->fileVersion($request, $this->visibleFile($request, $file));
    }

    public function destroy(Request $request, File $file): RedirectResponse
    {
        return $this->fileDestroy($request, $this->visibleFile($request, $file));
    }
}
