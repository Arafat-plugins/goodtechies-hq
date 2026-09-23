<?php

use App\Http\Resources\ClientResource;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Project;
use App\Models\User;
use App\Support\ClientStatus;
use App\Support\RoleName;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\Request;

/** The keys every requester gets, whatever their role. */
// `permissions` is here, not in the commercial half: what you may *do* to a client is not a
// commercial fact about that client, and a screen needs it before it knows whether to draw a
// control. Its two keys are false for anyone who may not, which is the whole answer.
const PUBLIC_CLIENT_KEYS = ['id', 'name', 'status', 'status_label', 'permissions'];

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->admin = clientResourceUser(RoleName::ADMIN);
    $this->manager = clientResourceUser(RoleName::MANAGER);
    $this->otherManager = clientResourceUser(RoleName::MANAGER);
    $this->employee = clientResourceUser(RoleName::EMPLOYEE);

    $this->contacts = [
        ['name' => 'Rina Haque', 'role' => 'Marketing Lead', 'email' => 'rina@acme.test', 'phone' => '+8801000000'],
    ];

    $this->client = Client::factory()->create([
        'name' => 'Acme Ltd',
        'contact_info' => $this->contacts,
        'internal_notes' => 'Renewal talk in March.',
        'status' => ClientStatus::Active,
    ]);

    $this->project = Project::factory()->forClient($this->client)->create([
        'pm_id' => $this->manager->employee->id,
    ]);
});

/**
 * A user with the given role and an employee record, built from factories because the seeded
 * team has no Manager.
 */
function clientResourceUser(RoleName $role): User
{
    $user = User::factory()->create();
    Employee::factory()->for($user)->forRole($role)->create();

    return $user->fresh();
}

/**
 * The resource array as the given user would receive it, with no HTTP involved.
 *
 * @return array<string, mixed>
 */
function clientPayload(Client $client, ?User $user): array
{
    $request = Request::create('/');
    $request->setUserResolver(fn () => $user);

    return (new ClientResource($client))->resolve($request);
}

it('gives an admin the contacts and the internal notes', function () {
    $payload = clientPayload($this->client, $this->admin);

    expect(array_keys($payload))->toEqualCanonicalizing(array_merge(PUBLIC_CLIENT_KEYS, [
        'contacts',
        'internal_notes',
    ]));

    expect($payload['name'])->toBe('Acme Ltd')
        ->and($payload['status'])->toBe('active')
        ->and($payload['status_label'])->toBe('Active')
        ->and($payload['contacts'])->toBe($this->contacts)
        ->and($payload['internal_notes'])->toBe('Renewal talk in March.');
})->group('phase1');

it('gives a manager on one of the client\'s projects the same keys', function () {
    $payload = clientPayload($this->client, $this->manager);

    expect($payload)->toHaveKey('contacts')
        ->and($payload['contacts'])->toBe($this->contacts)
        ->and($payload['internal_notes'])->toBe('Renewal talk in March.');
})->group('phase1');

it('hides them from a manager with no project for that client', function () {
    $payload = clientPayload($this->client, $this->otherManager);

    expect(array_keys($payload))->toEqualCanonicalizing(PUBLIC_CLIENT_KEYS);

    $this->assertArrayNotHasKey('contacts', $payload);
    $this->assertArrayNotHasKey('internal_notes', $payload);
})->group('phase1');

it('hides them from an employee and from an unauthenticated request', function () {
    foreach ([$this->employee, null] as $user) {
        $payload = clientPayload($this->client, $user);

        expect(array_keys($payload))->toEqualCanonicalizing(PUBLIC_CLIENT_KEYS);

        $this->assertArrayNotHasKey('contacts', $payload);
        $this->assertArrayNotHasKey('internal_notes', $payload);
    }
})->group('phase1');

it('adds the project count and the projects only when they are loaded', function () {
    $loaded = Client::query()->withCount('projects')->with('projects')->findOrFail($this->client->id);

    $request = Request::create('/');
    $request->setUserResolver(fn () => $this->admin);

    $payload = (new ClientResource($loaded))->resolve($request);
    $projects = $payload['projects']->toArray($request);

    expect($payload['projects_count'])->toBe(1)
        ->and($projects)->toHaveCount(1)
        ->and($projects[0]['id'])->toBe($this->project->id)
        ->and($projects[0])->toHaveKey('finance');
})->group('phase1');

it('labels an inactive client', function () {
    $client = Client::factory()->inactive()->create();

    $payload = clientPayload($client, $this->admin);

    expect($payload['status'])->toBe('inactive')
        ->and($payload['status_label'])->toBe('Inactive');
})->group('phase1');

it('answers what each requester may do to the client, from the policy', function () {
    // The Files tab on the client page needs this before it draws an upload control, and the
    // alternative it used first — "only an Admin is on this surface, so reaching the page is
    // the permission" — is an inference that stops being true the day a role is added.
    expect(clientPayload($this->client, $this->admin)['permissions'])
        ->toBe(['can_update' => true, 'can_delete' => true]);

    foreach ([$this->manager, $this->employee] as $user) {
        expect(clientPayload($this->client, $user)['permissions'])
            ->toBe(['can_update' => false, 'can_delete' => false]);
    }

    expect(clientPayload($this->client, null)['permissions'])
        ->toBe(['can_update' => false, 'can_delete' => false]);
})->group('phase1');
