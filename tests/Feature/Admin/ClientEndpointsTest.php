<?php

use App\Models\Client;
use App\Models\Employee;
use App\Models\User;
use App\Support\ClientStatus;
use App\Support\RoleName;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
});

/**
 * A Manager with no seeded counterpart. MANAGER is a dormant role on the Employee shell, so
 * every Admin route is closed to it — the surface refuses before the policy is consulted.
 */
function managerUser(): User
{
    return Employee::factory()->forRole(RoleName::MANAGER)->create()->user->fresh();
}

it('lists clients with their project counts', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.clients.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Clients/Index')
            ->has('clients.data', 4)
            ->where('clients.data.0.name', 'ABC Ltd')
            ->where('clients.data.0.projects_count', 1)
            ->where('filters.search', null)
            ->where('filters.status', null),
        );
})->group('phase1');

it('filters the client list by name and by status', function () {
    Client::factory()->inactive()->create(['name' => 'Zebra Holdings']);

    $this->actingAs($this->admin)
        ->get(route('admin.clients.index', ['search' => 'buffalo']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('clients.data', 1)
            ->where('clients.data.0.name', 'Buffalo Modular Homes')
            ->where('filters.search', 'buffalo'),
        );

    $this->actingAs($this->admin)
        ->get(route('admin.clients.index', ['status' => ClientStatus::Inactive->value]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('clients.data', 1)
            ->where('clients.data.0.name', 'Zebra Holdings'),
        );
})->group('phase1');

it('renders the create form with the status options', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.clients.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Clients/Create')
            ->has('statuses', 2)
            ->where('statuses.0', ['value' => 'active', 'label' => 'Active']),
        );
})->group('phase1');

it('creates a client and redirects to its page', function () {
    $response = $this->actingAs($this->admin)->post(route('admin.clients.store'), [
        'name' => 'Northwind Joinery',
        'status' => ClientStatus::Active->value,
        'internal_notes' => 'Referred by Buffalo Modular.',
        'contacts' => [[
            'name' => 'Rita Northwind',
            'role' => 'Owner',
            'email' => 'rita@northwind.test',
            'phone' => '+44 7700 900555',
        ]],
    ]);

    $client = Client::where('name', 'Northwind Joinery')->firstOrFail();

    $response->assertRedirect(route('admin.clients.show', $client))
        ->assertSessionHas('success');

    expect($client->contact_info[0]['name'])->toBe('Rita Northwind')
        ->and($client->internal_notes)->toBe('Referred by Buffalo Modular.');
})->group('phase1');

it('refuses a client without a name', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.clients.store'), ['status' => ClientStatus::Active->value])
        ->assertSessionHasErrors('name');

    expect(Client::count())->toBe(4);
})->group('phase1');

it('carries the contacts, notes and projects on the client page', function () {
    $client = Client::where('name', 'Buffalo Modular Homes')->firstOrFail();

    $this->actingAs($this->admin)
        ->get(route('admin.clients.show', $client))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Clients/Show')
            ->where('client.data.name', 'Buffalo Modular Homes')
            ->where('client.data.contacts.0.name', 'Karen Buffalo')
            ->where('client.data.internal_notes', 'Long-standing client; pays on time, prefers email over calls.')
            ->has('client.data.projects', 3)
            ->has('activity'),
        );
})->group('phase1');

it('renders the edit form for a client', function () {
    $client = Client::where('name', 'ABC Ltd')->firstOrFail();

    $this->actingAs($this->admin)
        ->get(route('admin.clients.edit', $client))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Clients/Edit')
            ->where('client.data.id', $client->id)
            ->has('statuses', 2),
        );
})->group('phase1');

it('updates a client', function () {
    $client = Client::where('name', 'ABC Ltd')->firstOrFail();

    $this->actingAs($this->admin)
        ->put(route('admin.clients.update', $client), [
            'name' => 'ABC Limited',
            'status' => ClientStatus::Active->value,
            'internal_notes' => 'Renewed for another year.',
            'contacts' => [['name' => 'Sam Abc', 'role' => 'Director', 'email' => 'sam@abc.test', 'phone' => '+44 7700 900444']],
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $client->refresh();

    expect($client->name)->toBe('ABC Limited')
        ->and($client->internal_notes)->toBe('Renewed for another year.');
})->group('phase1');

it('deactivates a client instead of deleting it', function () {
    $client = Client::where('name', 'APH St Albans')->firstOrFail();

    $this->actingAs($this->admin)
        ->post(route('admin.clients.deactivate', $client))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($client->fresh()->status)->toBe(ClientStatus::Inactive)
        ->and(Client::count())->toBe(4)
        ->and($client->fresh()->projects()->count())->toBe(1);
})->group('phase1');

it('keeps every client route on the admin shell', function () {
    $manager = managerUser();
    $client = Client::first();

    $this->actingAs($manager)->get(route('admin.clients.index'))->assertForbidden();
    $this->actingAs($manager)->get(route('admin.clients.show', $client))->assertForbidden();
    $this->actingAs($manager)->post(route('admin.clients.store'), ['name' => 'X', 'status' => 'active'])->assertForbidden();
    $this->actingAs($manager)->put(route('admin.clients.update', $client), ['name' => 'X', 'status' => 'active'])->assertForbidden();
    $this->actingAs($manager)->post(route('admin.clients.deactivate', $client))->assertForbidden();

    expect($client->fresh()->status)->toBe(ClientStatus::Active);
})->group('phase1');
