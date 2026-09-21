<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Support\ProjectStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The employee's own projects, read-only.
 *
 * A project they are not on does not exist as far as this surface is concerned: it is absent
 * from the list and answers 404 by id, never 403 (master prompt Part B §3 rule 1). Both the
 * list and the detail page ask Project::visibleTo() the same question, so an id cannot be
 * walked into a project the list would not have shown.
 *
 * An archived project stays readable — it is history the employee worked on.
 */
class ProjectController extends Controller
{
    /** The relations a project payload needs; ProjectResource decides what survives into it. */
    private const RELATIONS = ['client', 'pm.user', 'members.user', 'finance'];

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Project::class);

        $filters = $this->filters($request);

        $projects = Project::query()
            ->visibleTo($request->user())
            ->with(self::RELATIONS)
            ->when($filters['search'], fn (Builder $query, string $search) => $query->where('name', 'ilike', '%'.$search.'%'))
            ->when($filters['status'], fn (Builder $query, string $status) => $query->where('status', $status))
            ->orderByRaw('deadline asc nulls last')
            ->orderBy('name')
            ->get();

        return Inertia::render('Employee/Projects/Index', [
            'projects' => ProjectResource::collection($projects),
            'filters' => $filters,
        ]);
    }

    public function show(Request $request, Project $project): Response
    {
        $project = Project::query()
            ->visibleTo($request->user())
            ->with(self::RELATIONS)
            ->whereKey($project->getKey())
            ->firstOrFail();

        return Inertia::render('Employee/Projects/Show', [
            'project' => new ProjectResource($project),
        ]);
    }

    /**
     * @return array{search: string|null, status: string|null}
     */
    private function filters(Request $request): array
    {
        $search = trim((string) $request->query('search', ''));

        return [
            'search' => $search === '' ? null : $search,
            'status' => ProjectStatus::tryFrom((string) $request->query('status', ''))?->value,
        ];
    }
}
