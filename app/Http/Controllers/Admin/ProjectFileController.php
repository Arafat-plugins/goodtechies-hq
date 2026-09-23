<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ManagesFiles;
use App\Http\Controllers\Controller;
use App\Http\Requests\File\StoreFileRequest;
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
}
