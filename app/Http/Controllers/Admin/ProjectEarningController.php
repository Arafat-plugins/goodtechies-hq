<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ProjectStateException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Project\StoreProjectEarningRequest;
use App\Models\Project;
use App\Models\ProjectEarningEntry;
use App\Services\ProjectEarningService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * A project's hand-recorded financials (contract value, earned, received). The same permission
 * as the project's price; recording an entry creates nothing anywhere else.
 */
class ProjectEarningController extends Controller
{
    public function __construct(
        private readonly ProjectEarningService $earnings,
    ) {}

    public function store(StoreProjectEarningRequest $request, Project $project): RedirectResponse
    {
        $this->authorizeFinance($project);

        try {
            $this->earnings->record($request->user(), $project, $request->entryAttributes());
        } catch (ProjectStateException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Project earning recorded.');
    }

    public function update(StoreProjectEarningRequest $request, Project $project, ProjectEarningEntry $entry): RedirectResponse
    {
        abort_if((int) $entry->project_id !== (int) $project->getKey(), 404);
        $this->authorizeFinance($project);

        try {
            $this->earnings->update($request->user(), $entry, $request->entryAttributes());
        } catch (ProjectStateException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Project earning updated.');
    }

    public function destroy(Request $request, Project $project, ProjectEarningEntry $entry): RedirectResponse
    {
        abort_if((int) $entry->project_id !== (int) $project->getKey(), 404);
        $this->authorizeFinance($project);

        try {
            $this->earnings->delete($request->user(), $entry);
        } catch (ProjectStateException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Project earning deleted.');
    }

    /**
     * Archived is a state, not a permission: it answers with a flash error, not a 403.
     */
    private function authorizeFinance(Project $project): void
    {
        if (! $project->isArchived()) {
            Gate::authorize('updateFinance', $project);
        }
    }
}
