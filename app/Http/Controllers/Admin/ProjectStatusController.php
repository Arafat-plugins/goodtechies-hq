<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ProjectStateException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Project\ChangeProjectStatusRequest;
use App\Models\Project;
use App\Services\ProjectService;
use App\Support\ProjectStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * A project's lifecycle: the status transitions, and archiving, which is not one of them.
 *
 * An illegal transition is the project's state refusing, not the user being refused, so it
 * comes back as a flash error on the page the request came from.
 */
class ProjectStatusController extends Controller
{
    public function __construct(
        private readonly ProjectService $projects,
    ) {}

    public function update(ChangeProjectStatusRequest $request, Project $project): RedirectResponse
    {
        $status = $request->status();

        // Archived is a state, not a permission: it answers with a flash error, not a 403.
        if (! $project->isArchived()) {
            Gate::authorize($status === ProjectStatus::Cancelled ? 'cancel' : 'update', $project);
        }

        try {
            $this->projects->changeStatus($request->user(), $project, $status, $request->reason());
        } catch (ProjectStateException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Project status changed to '.$status->label().'.');
    }

    public function archive(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('archive', $project);

        try {
            $this->projects->archive($request->user(), $project);
        } catch (ProjectStateException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Project archived.');
    }

    public function unarchive(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('unarchive', $project);

        try {
            $this->projects->unarchive($request->user(), $project);
        } catch (ProjectStateException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Project unarchived.');
    }
}
