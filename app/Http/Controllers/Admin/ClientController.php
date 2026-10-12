<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Client\StoreClientRequest;
use App\Http\Requests\Client\UpdateClientRequest;
use App\Http\Resources\ClientResource;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Services\ActivityLogger;
use App\Services\ClientService;
use App\Support\ClientStatus;
use App\Support\ClientTaskCounts;
use App\Support\ProjectClientBoard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Clients on the Admin surface. Everything a client is made of — its contact people, its
 * internal notes — is commercial data, so every action goes through ClientPolicy and every
 * payload through ClientResource.
 *
 * There is no destroy(): a finished client is deactivated so their projects keep resolving.
 */
class ClientController extends Controller
{
    /** How many rows a client list page carries. */
    private const PER_PAGE = 15;

    /** How far back a client's timeline is shown on the detail page. */
    private const ACTIVITY_LIMIT = 20;

    public function __construct(
        private readonly ClientService $clients,
        private readonly ActivityLogger $activity,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Client::class);

        $filters = $this->filters($request);

        $clients = Client::query()
            ->withCount('projects')
            ->with(['projects' => fn ($query) => $query->select(['id', 'client_id', 'name', 'domain', 'project_type'])->orderBy('name')])
            // Polish 055: name, company (nickname) or a contact's email. Contacts are encrypted,
            // so their emails are matched here in PHP over the (small) client table.
            ->when($filters['search'], fn ($query, string $search) => $query->where(
                fn ($inner) => $inner->where('name', 'ilike', '%'.$search.'%')
                    ->orWhere('nickname', 'ilike', '%'.$search.'%')
                    ->orWhereIn('id', $this->idsByContactEmail($search))
            ))
            ->when($filters['projects'] === 'with', fn ($query) => $query->has('projects'))
            ->when($filters['projects'] === 'without', fn ($query) => $query->doesntHave('projects'))
            ->when($filters['status'], fn ($query, string $status) => $query->where('status', $status))
            // Polish 049: "Clients with open tasks" / "No open tasks".
            ->when($filters['tasks'] !== null, function ($query) use ($filters) {
                $open = fn ($sub) => $sub->selectRaw('1')
                    ->from('tasks')
                    ->join('projects', 'projects.id', '=', 'tasks.project_id')
                    ->whereColumn('projects.client_id', 'clients.id')
                    ->whereNull('tasks.deleted_at')
                    ->whereIn('tasks.status', [...ClientTaskCounts::TODO, ...ClientTaskCounts::PROGRESS]);

                $filters['tasks'] === 'open' ? $query->whereExists($open) : $query->whereNotExists($open);
            })
            ->orderBy('name')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        // Polish 049: the list's task counts, project chips and last activity — one query each.
        $ids = $clients->getCollection()->modelKeys();
        $counts = ClientTaskCounts::forClients($ids);
        $touched = ClientTaskCounts::lastActivity($ids);

        $clients->getCollection()->each(function (Client $client) use ($counts, $touched): void {
            $client->setAttribute('task_counts', $counts[$client->id] ?? ClientTaskCounts::empty());
            $client->setAttribute('last_activity_at', $touched[$client->id] ?? $client->updated_at?->toDateTimeString());
            $client->setAttribute('project_chips', $client->projects->map(fn ($project): array => [
                'id' => $project->id,
                'name' => $project->name,
                'domain' => $project->domain,
                'type' => $project->project_type?->value,
                'type_label' => $project->project_type?->label(),
            ])->values()->all());
            $client->unsetRelation('projects');
        });

        return Inertia::render('Admin/Clients/Index', [
            'clients' => ClientResource::collection($clients),
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Client::class);

        return Inertia::render('Admin/Clients/Create', [
            'statuses' => $this->statuses(),
        ]);
    }

    public function store(StoreClientRequest $request): RedirectResponse
    {
        Gate::authorize('create', Client::class);

        $client = $this->clients->create($request->user(), $request->validated());

        return redirect()
            ->route('admin.clients.show', $client)
            ->with('success', 'Client created.');
    }

    public function show(Request $request, Client $client): Response
    {
        Gate::authorize('view', $client);

        $client->loadMissing([
            'projects.client',
            'projects.pm.user',
            'projects.members.user',
            'projects.finance',
        ]);

        // Polish 049: each project's task counts, and the client's, for the cards and the overview.
        $projectCounts = ClientTaskCounts::forProjects($client->projects->modelKeys());

        // Polish 057: each project's open tasks (as on Admin → Projects?client=…), for the
        // project rows under each website card.
        $board = ProjectClientBoard::client($request->user(), (string) $client->getKey());
        $projectOpen = [];

        foreach ($board['boxes'] ?? [] as $box) {
            foreach ($box['projects'] as $project) {
                $projectOpen[$project['id']] = [
                    'tracked_seconds' => $project['tracked_seconds'],
                    'open_count' => $project['open_count'],
                    'tasks' => $project['tasks'],
                ];
            }
        }

        return Inertia::render('Admin/Clients/Show', [
            'client' => new ClientResource($client),
            'activity' => $this->activityFor($client),
            'projectTasks' => (object) $projectCounts,
            'projectOpen' => (object) $projectOpen,
            'taskCounts' => ClientTaskCounts::forClients([$client->id])[$client->id] ?? ClientTaskCounts::empty(),
        ]);
    }

    public function edit(Client $client): Response
    {
        Gate::authorize('update', $client);

        return Inertia::render('Admin/Clients/Edit', [
            'client' => new ClientResource($client),
            'statuses' => $this->statuses(),
        ]);
    }

    public function update(UpdateClientRequest $request, Client $client): RedirectResponse
    {
        Gate::authorize('update', $client);

        $this->clients->update($request->user(), $client, $request->validated());

        return back()->with('success', 'Client updated.');
    }

    public function deactivate(Request $request, Client $client): RedirectResponse
    {
        Gate::authorize('delete', $client);

        $this->clients->deactivate($request->user(), $client);

        return back()->with('success', 'Client deactivated.');
    }

    /**
     * The list filters, echoed back so the page can show what it is filtered by.
     *
     * @return array{search: string|null, status: string|null, tasks: string|null, projects: string|null}
     */
    private function filters(Request $request): array
    {
        $search = trim((string) $request->query('search', ''));

        return [
            'search' => $search === '' ? null : $search,
            'status' => ClientStatus::tryFrom((string) $request->query('status', ''))?->value,
            'tasks' => in_array($request->query('tasks'), ['open', 'none'], true) ? (string) $request->query('tasks') : null,
            'projects' => in_array($request->query('projects'), ['with', 'without'], true) ? (string) $request->query('projects') : null,
        ];
    }

    /**
     * ClientStatus carries no label(): active/inactive read straight back as words.
     *
     * @return list<array{value: string, label: string}>
     */
    /**
     * Clients with a contact whose email contains the term (case-insensitive).
     *
     * @return list<int>
     */
    private function idsByContactEmail(string $search): array
    {
        $needle = mb_strtolower($search);

        return Client::query()->get(['id', 'contact_info'])
            ->filter(fn (Client $client): bool => collect($client->contact_info ?? [])
                ->contains(fn ($contact): bool => is_array($contact) && str_contains(mb_strtolower((string) ($contact['email'] ?? '')), $needle)))
            ->modelKeys();
    }

    private function statuses(): array
    {
        return array_map(
            fn (ClientStatus $status): array => [
                'value' => $status->value,
                'label' => Str::headline($status->value),
            ],
            ClientStatus::cases(),
        );
    }

    /**
     * @return list<array{description: string, actor: string|null, at: string|null}>
     */
    private function activityFor(Client $client): array
    {
        return $this->activity->for($client)
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
