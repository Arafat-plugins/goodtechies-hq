<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ProjectStateException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Project\UpdateProjectFinanceRequest;
use App\Models\Project;
use App\Services\ProjectFinanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * A project's money, on its own route because it is its own permission: being allowed to edit
 * a project does not make you allowed to change what it is billed at.
 */
class ProjectFinanceController extends Controller
{
    public function __construct(
        private readonly ProjectFinanceService $finance,
    ) {}

    public function update(UpdateProjectFinanceRequest $request, Project $project): RedirectResponse
    {
        // Archived is a state, not a permission: it answers with a flash error, not a 403.
        if (! $project->isArchived()) {
            Gate::authorize('updateFinance', $project);
        }

        try {
            $this->finance->upsert($request->user(), $project, $request->validated());
        } catch (ProjectStateException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Project finance updated.');
    }
}
