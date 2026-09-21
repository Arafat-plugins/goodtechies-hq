<?php

use App\Models\Permission as PermissionModel;
use App\Models\Project;
use App\Models\User;
use App\Models\UserProjectPermission;
use App\Support\Permission;
use Illuminate\Testing\TestResponse;

/*
|--------------------------------------------------------------------------
| Project privacy — the negative suite (master prompt Part C §1, Part F §2)
|--------------------------------------------------------------------------
|
| A field a requester may not see is ABSENT from the payload, not null and not
| masked. These tests walk the whole Inertia props tree, at every depth, so a
| restricted field cannot hide inside a relation, a collection or a nested
| resource and still count as "not in the payload".
|
| Each of the four endpoints that can carry a project is checked from the only
| surface its role can reach: an Admin is refused the Employee shell and an
| Employee the Admin shell, so the two halves of each rule are asserted on the
| endpoint each role actually gets.
|
*/

/** The fields an employee must never receive about a project or its client. */
const PRIVACY_RESTRICTED_KEYS = [
    'client',
    'internal_notes',
    'billing_type',
    'finance',
    'price',
    'recurring_amount',
    'contract_value',
    'contract_terms',
    'profitability_snapshot',
];

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();
    $this->seoProject = Project::where('name', 'Buffalo Modular — SEO')->firstOrFail();
    $this->otherProject = Project::where('name', 'Heat Gap — SEO Retainer')->firstOrFail();
});

/**
 * Every key in the structure, at every depth. One helper, reused by every assertion below.
 *
 * @return list<string>
 */
function keysAtEveryDepth(mixed $payload): array
{
    if (! is_array($payload)) {
        return [];
    }

    $keys = [];

    foreach ($payload as $key => $value) {
        if (is_string($key)) {
            $keys[] = $key;
        }

        $keys = [...$keys, ...keysAtEveryDepth($value)];
    }

    return array_values(array_unique($keys));
}

/**
 * @return list<string>
 */
function propKeys(TestResponse $response): array
{
    return keysAtEveryDepth($response->inertiaPage()['props']);
}

it('never sends a restricted project field to an employee', function (string $route, bool $parameterised) {
    $response = $this->actingAs($this->tapu)
        ->get($parameterised ? route($route, $this->seoProject) : route($route))
        ->assertOk();

    $keys = propKeys($response);

    foreach (PRIVACY_RESTRICTED_KEYS as $restricted) {
        expect($keys)->not->toContain($restricted);
    }
})->with([
    ['employee.projects.index', false],
    ['employee.projects.show', true],
])->group('phase1');

it('sends every restricted project field to an admin', function (string $route, bool $parameterised) {
    $response = $this->actingAs($this->admin)
        ->get($parameterised ? route($route, $this->seoProject) : route($route))
        ->assertOk();

    $keys = propKeys($response);

    foreach (PRIVACY_RESTRICTED_KEYS as $restricted) {
        expect($keys)->toContain($restricted);
    }
})->with([
    ['admin.projects.index', false],
    ['admin.projects.show', true],
])->group('phase1');

it('opens the finance of one project to an employee granted it, and no other', function () {
    UserProjectPermission::create([
        'user_id' => $this->tapu->id,
        'project_id' => $this->seoProject->id,
        'permission_id' => PermissionModel::where('key', Permission::ProjectsViewFinance->value)->firstOrFail()->id,
    ]);

    $listed = $this->actingAs($this->tapu)
        ->get(route('employee.projects.index'))
        ->assertOk()
        ->inertiaPage()['props']['projects']['data'];

    $byId = collect($listed)->keyBy('id');

    expect($byId[$this->seoProject->id])->toHaveKey('finance')
        ->and($byId[$this->seoProject->id]['finance']['recurring_amount'])->toBe('200.00')
        ->and($byId[$this->otherProject->id])->not->toHaveKey('finance')
        ->and($byId[$this->otherProject->id])->not->toHaveKey('billing_type');

    $granted = $this->actingAs($this->tapu)
        ->get(route('employee.projects.show', $this->seoProject))
        ->assertOk()
        ->inertiaPage()['props']['project']['data'];

    $ungranted = $this->actingAs($this->tapu)
        ->get(route('employee.projects.show', $this->otherProject))
        ->assertOk()
        ->inertiaPage()['props']['project']['data'];

    expect($granted)->toHaveKey('finance')
        ->and($granted['permissions']['can_view_finance'])->toBeTrue()
        // The grant is about money only: who the client is stays commercial data.
        ->and($granted)->not->toHaveKey('client')
        ->and($granted)->not->toHaveKey('internal_notes')
        ->and($ungranted)->not->toHaveKey('finance')
        ->and($ungranted['permissions']['can_view_finance'])->toBeFalse();
})->group('phase1');

it('refuses the accountant every client and project route', function (string $method, string $route, bool $parameterised) {
    $target = str_contains($route, 'clients') ? $this->seoProject->client : $this->seoProject;

    $this->actingAs($this->accountant)
        ->call($method, $parameterised ? route($route, $target) : route($route))
        ->assertForbidden();
})->with([
    ['GET', 'admin.clients.index', false],
    ['GET', 'admin.clients.create', false],
    ['POST', 'admin.clients.store', false],
    ['GET', 'admin.clients.show', true],
    ['GET', 'admin.clients.edit', true],
    ['PUT', 'admin.clients.update', true],
    ['POST', 'admin.clients.deactivate', true],
    ['GET', 'admin.projects.index', false],
    ['GET', 'admin.projects.create', false],
    ['POST', 'admin.projects.store', false],
    ['GET', 'admin.projects.show', true],
    ['GET', 'admin.projects.edit', true],
    ['PUT', 'admin.projects.update', true],
    ['PUT', 'admin.projects.finance.update', true],
    ['PUT', 'admin.projects.members.update', true],
    ['POST', 'admin.projects.status', true],
    ['POST', 'admin.projects.archive', true],
    ['POST', 'admin.projects.unarchive', true],
    ['GET', 'employee.projects.index', false],
    ['GET', 'employee.projects.show', true],
])->group('phase1');

it('shows the accountant no project at all, whatever the route', function () {
    expect(Project::query()->visibleTo($this->accountant)->count())->toBe(0);
})->group('phase1');
