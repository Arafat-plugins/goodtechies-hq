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
            ->when($filters['search'], fn ($query, string $search) => $query->where('name', 'ilike', '%'.$search.'%'))
            ->when($filters['status'], fn ($query, string $status) => $query->where('status', $status))
            ->orderBy('name')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

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

    public function show(Client $client): Response
    {
        Gate::authorize('view', $client);

        $client->loadMissing([
            'projects.client',
            'projects.pm.user',
            'projects.members.user',
            'projects.finance',
        ]);

        return Inertia::render('Admin/Clients/Show', [
            'client' => new ClientResource($client),
            'activity' => $this->activityFor($client),
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
     * @return array{search: string|null, status: string|null}
     */
    private function filters(Request $request): array
    {
        $search = trim((string) $request->query('search', ''));

        return [
            'search' => $search === '' ? null : $search,
            'status' => ClientStatus::tryFrom((string) $request->query('status', ''))?->value,
        ];
    }

    /**
     * ClientStatus carries no label(): active/inactive read straight back as words.
     *
     * @return list<array{value: string, label: string}>
     */
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
