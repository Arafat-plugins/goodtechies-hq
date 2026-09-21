<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ProjectStateException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Project\UpdateProjectMembersRequest;
use App\Models\Project;
use App\Services\ProjectService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Who works on a project. The list sent is the list the project ends up with; the service
 * records each addition and removal on the project's timeline.
 */
class ProjectMemberController extends Controller
{
    public function __construct(
        private readonly ProjectService $projects,
    ) {}

    public function update(UpdateProjectMembersRequest $request, Project $project): RedirectResponse
    {
        // Archived is a state, not a permission: it answers with a flash error, not a 403.
        if (! $project->isArchived()) {
            Gate::authorize('manageMembers', $project);
        }

        try {
            $this->projects->syncMembers(
                $request->user(),
                $project,
                $request->memberIds(),
                $request->rolesById(),
            );
        } catch (ProjectStateException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Project members updated.');
    }
}
