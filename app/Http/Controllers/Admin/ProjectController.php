<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ProjectStateException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Project\StoreProjectRequest;
use App\Http\Requests\Project\UpdateProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Services\ActivityLogger;
use App\Services\ConversationService;
use App\Services\ProjectService;
use App\Services\TaskTimerService;
use App\Support\BillingFrequency;
use App\Support\BillingType;
use App\Support\Permission;
use App\Support\Priority;
use App\Support\ProjectClientBoard;
use App\Support\ProjectRecurrenceFrequency;
use App\Support\ProjectStatus;
use App\Support\ProjectTaskList;
use App\Support\ProjectType;
use App\Support\RoleName;
use App\Support\UserStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Projects on the Admin surface.
 *
 * Even here the list is scoped through Project::visibleTo(): the surface a request arrives on
 * is never what decides which rows it sees.
 */
class ProjectController extends Controller
{
    /** How many rows a project list page carries. */
    private const PER_PAGE = 15;

    /** How far back a project's timeline is shown on the detail page. */
    private const ACTIVITY_LIMIT = 20;

    /** The relations every project payload needs, so a list is not a query per row. */
    private const RELATIONS = ['client', 'pm.user', 'members.user', 'finance'];

    public function __construct(
        private readonly ProjectService $projects,
        private readonly ActivityLogger $activity,
        private readonly ConversationService $conversations,
        private readonly TaskTimerService $timers,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Project::class);

        $filters = $this->filters($request);

        $projects = Project::query()
            ->visibleTo($request->user())
            ->with(self::RELATIONS)
            ->when($filters['search'], fn (Builder $query, string $search) => $query->where('name', 'ilike', '%'.$search.'%'))
            ->when($filters['client_id'], fn (Builder $query, int $clientId) => $query->where('client_id', $clientId))
            ->when($filters['project_type'], fn (Builder $query, string $type) => $query->where('project_type', $type))
            ->when($filters['status'], fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['pm_id'], fn (Builder $query, int $pmId) => $query->where('pm_id', $pmId))
            // Two views, not a widening switch: `archived=1` is the archive and nothing else.
            ->when(
                $filters['archived'],
                fn (Builder $query) => $query->whereNotNull('archived_at'),
                fn (Builder $query) => $query->notArchived(),
            )
            ->orderBy('name')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        // Polish 030: the page opens BY CLIENT (the client's ClickUp-style picture); the table
        // is the "List" layout. `?project=` picks the board on the right.
        $layout = $request->query('layout') === 'list' ? 'list' : 'clients';
        $selected = $layout === 'clients' ? $this->id($request->query('project')) : null;
        // Polish 033: `?client=` (an id, or `internal`) opens that client's service boxes.
        $client = $layout === 'clients' && is_string($request->query('client')) ? $request->query('client') : null;

        return Inertia::render('Admin/Projects/Index', [
            'layout' => $layout,
            'clientTree' => fn (): array => $layout === 'clients' ? ProjectClientBoard::tree($request->user()) : [],
            'projectBoard' => function () use ($request, $selected): ?array {
                if ($selected === null) {
                    return null;
                }

                $project = Project::query()->visibleTo($request->user())->whereKey($selected)->first();

                return $project === null ? null : ProjectClientBoard::board($request->user(), $project);
            },
            'clientBoard' => fn (): ?array => $client === null ? null : ProjectClientBoard::client($request->user(), $client),
            'serviceBoxes' => fn (): ?array => $layout === 'clients' && $request->user()->hasPermission(Permission::SettingsManage)
                ? ProjectClientBoard::boxSettings()
                : null,
            'projects' => ProjectResource::collection($projects),
            'filters' => $filters,
            'clients' => $this->clients(),
            'projectManagers' => $this->projectManagers(),
            ...$this->optionLists(),
            // "Working now" per project (flow F3): open task timers grouped by project id, one
            // statement for the whole page. ABSENT without `watchLive`, never an empty map that
            // would read as "nobody is working".
            ...($request->user()->can('watchLive', TimeEntry::class)
                ? ['workingNowByProject' => fn (): array => $this->timers->workingNowByProject($request->user()) ?? []]
                : []),
        ]);
    }

    /**
     * Polish 031: the sidebar's Projects dropdown, fetched when it is opened — clients (by
     * nickname), their projects and how many tasks each has. JSON, because the sidebar is on
     * every page and must not make every page pay for it.
     */
    public function tree(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Project::class);

        return response()->json(['clients' => ProjectClientBoard::tree($request->user())]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Project::class);

        return Inertia::render('Admin/Projects/Create', [
            'clients' => $this->clients(),
            'assignableEmployees' => $this->assignableEmployees(),
            ...$this->optionLists(),
        ]);
    }

    public function store(StoreProjectRequest $request): RedirectResponse
    {
        Gate::authorize('create', Project::class);

        $data = $request->validated();

        // A project always starts Active — the service sets that; moving it on is the status
        // route's business.
        $project = $this->projects->create(
            $request->user(),
            Arr::except($data, ['members', 'finance']),
            array_map('intval', $data['members'] ?? []),
            $this->finance($data['finance'] ?? null),
        );

        return redirect()
            ->route('admin.projects.show', $project)
            ->with('success', 'Project created.');
    }

    public function show(Request $request, Project $project): Response
    {
        Gate::authorize('view', $project);

        $project->loadMissing(self::RELATIONS);

        return Inertia::render('Admin/Projects/Show', [
            'project' => new ProjectResource($project),
            'activity' => $this->activityFor($project),
            'assignableEmployees' => $this->assignableEmployees(),
            // Client request 2026-10-06: every task on this project, listed on its Tasks tab.
            'projectTasks' => fn (): array => ProjectTaskList::for($request->user(), $project, 'admin'),

            // The project's channel (Phase 6). Only its ID travels in the props: the Discussion
            // tab mounts the same thread component the Messages page does, and that component
            // fetches its own payload from `GET /messages/{conversation}` the way `FilePanel`
            // fetches its own list — so a project page that nobody opens the tab on costs
            // nothing, and the thread it then shows is the one the policy built.
            //
            // `forProject()` rather than a relation read, so a project created in the window
            // while the Phase 6 backfill ran gets its channel here rather than a missing tab.
            'discussionConversationId' => $this->conversations->forProject($project)->getKey(),

            // Who is timing a task on this project right now (flow F3) — absent without `watchLive`.
            ...($request->user()->can('watchLive', TimeEntry::class)
                ? ['workingNow' => fn (): array => $this->timers->workingNow($request->user(), (int) $project->getKey()) ?? []]
                : []),
        ]);
    }

    public function edit(Project $project): Response
    {
        Gate::authorize('view', $project);

        $project->loadMissing(self::RELATIONS);

        return Inertia::render('Admin/Projects/Edit', [
            'project' => new ProjectResource($project),
            'clients' => $this->clients(),
            'assignableEmployees' => $this->assignableEmployees(),
            ...$this->optionLists(),
        ]);
    }

    public function update(UpdateProjectRequest $request, Project $project): RedirectResponse
    {
        // An archived project is read-only for everyone, which is a state, not a permission:
        // it answers with a flash error rather than a 403 (ProjectStateException).
        if (! $project->isArchived()) {
            Gate::authorize('update', $project);
        }

        try {
            $this->projects->update($request->user(), $project, $request->validated());
        } catch (ProjectStateException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Project updated.');
    }

    /**
     * The finance payload as the service wants it: nothing at all rather than an array of nulls,
     * so creating a project without money does not write an empty finance row.
     *
     * @param  array<string, mixed>|null  $finance
     * @return array<string, mixed>|null
     */
    private function finance(?array $finance): ?array
    {
        if ($finance === null) {
            return null;
        }

        $finance = array_filter($finance, fn (mixed $value): bool => $value !== null && $value !== '');

        return $finance === [] ? null : $finance;
    }

    /**
     * @return array{search: string|null, client_id: int|null, project_type: string|null, status: string|null, pm_id: int|null, archived: bool}
     */
    private function filters(Request $request): array
    {
        $search = trim((string) $request->query('search', ''));

        return [
            'search' => $search === '' ? null : $search,
            'client_id' => $this->id($request->query('client_id')),
            'project_type' => ProjectType::tryFrom((string) $request->query('project_type', ''))?->value,
            'status' => ProjectStatus::tryFrom((string) $request->query('status', ''))?->value,
            'pm_id' => $this->id($request->query('pm_id')),
            'archived' => $request->boolean('archived'),
        ];
    }

    private function id(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Clients for the filter and the form selects. Not a ClientResource: this is a picker,
     * and a name with an id is all it may carry.
     *
     * @return list<array{id: int, name: string}>
     */
    private function clients(): array
    {
        return Client::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Client $client): array => ['id' => $client->id, 'name' => $client->name])
            ->values()
            ->all();
    }

    /**
     * Everyone who could be a project's PM: the employees already running one, plus the roles
     * that are allowed to.
     *
     * @return list<array{id: int, name: string|null}>
     */
    private function projectManagers(): array
    {
        return Employee::query()
            ->with('user')
            ->where(fn (Builder $query) => $query
                ->whereHas('managedProjects')
                ->orWhereHas('role', fn (Builder $role) => $role->whereIn('name', [
                    RoleName::ADMIN->value,
                    RoleName::MANAGER->value,
                ])))
            ->get()
            ->map(fn (Employee $employee): array => ['id' => $employee->id, 'name' => $employee->user?->name])
            ->sortBy('name')
            ->values()
            ->all();
    }

    /**
     * The active employees a project may be staffed with.
     *
     * @return list<array{id: int, name: string|null, role: string|null}>
     */
    private function assignableEmployees(): array
    {
        return Employee::query()
            ->with(['user', 'role'])
            ->where('status', UserStatus::Active->value)
            ->get()
            ->map(fn (Employee $employee): array => [
                'id' => $employee->id,
                'name' => $employee->user?->name,
                'role' => $employee->role?->name?->value,
            ])
            ->sortBy('name')
            ->values()
            ->all();
    }

    /**
     * The enum selects every project form and filter bar needs.
     *
     * @return array<string, list<array{value: string, label: string}>>
     */
    private function optionLists(): array
    {
        return [
            'projectTypes' => $this->options(ProjectType::cases()),
            'statuses' => $this->options(ProjectStatus::cases()),
            'priorities' => $this->options(Priority::cases()),
            'billingTypes' => $this->options(BillingType::cases()),
            'recurrenceFrequencies' => $this->options(ProjectRecurrenceFrequency::cases()),
            'billingFrequencies' => $this->options(BillingFrequency::cases()),
            // Polish 026: the Admin can rename the types beside the Type box.
            'projectTypeDefaults' => array_column(
                array_map(fn (ProjectType $type): array => [$type->value, $type->defaultLabel()], ProjectType::cases()),
                1,
                0,
            ),
            'canRenameProjectTypes' => Gate::allows('settings.manage'),
        ];
    }

    /**
     * @param  list<ProjectType|ProjectStatus|Priority|BillingType|ProjectRecurrenceFrequency|BillingFrequency>  $cases
     * @return list<array{value: string, label: string}>
     */
    private function options(array $cases): array
    {
        return array_map(
            fn (ProjectType|ProjectStatus|Priority|BillingType|ProjectRecurrenceFrequency|BillingFrequency $case): array => [
                'value' => $case->value,
                'label' => $case->label(),
            ],
            $cases,
        );
    }

    /**
     * @return list<array{description: string, actor: string|null, at: string|null}>
     */
    private function activityFor(Project $project): array
    {
        return $this->activity->for($project)
            ->take(self::ACTIVITY_LIMIT)
            ->load('actor')
            ->map(fn (ActivityLog $log): array => [
                'description' => $log->description,
                'actor' => $log->actor?->name,
                'at' => $log->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }
}
