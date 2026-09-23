<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Concerns\ManagesFiles;
use App\Http\Controllers\Controller;
use App\Http\Requests\File\StoreFileRequest;
use App\Models\File;
use App\Services\FileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The per-file actions on the Employee surface.
 *
 * It exists because the delete rule is "the uploader or an Admin", and most uploaders are on
 * this surface: an employee who attaches the wrong screenshot to their own task has to be able
 * to take it off again without an Admin. FilePolicy is what makes that safe — they reach only
 * files on tasks they are assigned to, and among those only their own uploads.
 *
 * There is no project or client Files TAB on this surface, but that is a fact about the tabs
 * and not about these routes: `visibleFile()` asks FilePolicy, which delegates to the owner's
 * own policy, so a file on a project this employee is a member of is visible here exactly as
 * the project is — and one on a project or client they are not on answers 404. The surface
 * decides nothing; the owning record does.
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
