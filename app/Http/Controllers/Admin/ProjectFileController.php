<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\FileStateException;
use App\Http\Controllers\Concerns\ManagesFiles;
use App\Http\Controllers\Controller;
use App\Http\Requests\File\StoreFileRequest;
use App\Http\Resources\FileResource;
use App\Models\Project;
use App\Services\FileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The project detail page's Files tab (spec §7) — the same FileService the task panel uses.
 *
 * `Gate::authorize` rather than a visibleTo() scope, matching ProjectController on this
 * surface: only an Admin gets through `surface:admin`, and an Admin sees every project, so
 * there is no record here that is visible-but-not-listed for a 404 to be about.
 *
 * Attaching takes ProjectPolicy::update, which is also what makes an archived project read-only
 * for files: a project that accepts no edits accepts no new documents either, and the tab still
 * lists and downloads what is already on it.
 */
class ProjectFileController extends Controller
{
    use ManagesFiles;

    public function __construct(private readonly FileService $files) {}

    public function index(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        return $this->fileIndex($request, $project);
    }

    public function store(StoreFileRequest $request, Project $project): RedirectResponse
    {
        Gate::authorize('view', $project);

        return $this->fileStore($request, $project, 'File added to the project.');
    }

    /**
     * The attachments on the project's INTERNAL NOTES — the same JSON shape as `index`, but
     * behind `viewCommercial`, the ability that shows the internal notes themselves. Replace,
     * delete and history use the ordinary per-file routes; FilePolicy::view guards them.
     */
    public function internalIndex(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('viewCommercial', $project);

        return response()->json([
            'files' => FileResource::collection(
                $this->files->internalFor($project),
            )->toArray($request),
        ]);
    }

    /**
     * Attach a file to the internal notes. `viewCommercial` to see them, `update` to change the
     * project (which is also what refuses an archived one); FileService checks `update` again.
     */
    public function internalStore(StoreFileRequest $request, Project $project): RedirectResponse
    {
        Gate::authorize('viewCommercial', $project);
        Gate::authorize('update', $project);

        try {
            $this->files->store($request->user(), $project, $request->upload(), internal: true);
        } catch (FileStateException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'File added to the internal notes.');
    }
}
