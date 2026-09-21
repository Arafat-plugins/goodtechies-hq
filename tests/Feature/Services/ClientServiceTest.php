<?php

use App\Models\ActivityLog;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Employee;
use App\Models\User;
use App\Services\ClientService;
use App\Support\ClientStatus;
use App\Support\RoleName;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->service = app(ClientService::class);

    $this->admin = clientServiceUser(RoleName::ADMIN);
    $this->manager = clientServiceUser(RoleName::MANAGER);
    $this->employee = clientServiceUser(RoleName::EMPLOYEE);

    $this->contacts = [
        ['name' => 'Rina Haque', 'role' => 'Marketing Lead', 'email' => 'rina@acme.test', 'phone' => '+8801000000'],
    ];
});

/**
 * A user with the given role and an employee record, built from factories because the seeded
 * team has no Manager.
 */
function clientServiceUser(RoleName $role): User
{
    $user = User::factory()->create();
    Employee::factory()->for($user)->forRole($role)->create();

    return $user->fresh();
}

it('creates a client with encrypted contacts and an activity line', function () {
    $client = $this->service->create($this->admin, [
        'name' => 'Acme Ltd',
        'contacts' => $this->contacts,
        'internal_notes' => 'Renewal talk in March.',
        'status' => ClientStatus::Active,
    ]);

    $stored = DB::table('clients')->where('id', $client->id)->value('contact_info');

    expect($client->name)->toBe('Acme Ltd')
        ->and($client->contact_info)->toBe($this->contacts)
        ->and($client->status)->toBe(ClientStatus::Active)
        ->and($stored)->not->toContain('rina@acme.test')
        ->and(ActivityLog::where('object_id', $client->id)->sole()->description)->toBe('Client created')
        ->and(AuditLog::count())->toBe(0);
})->group('phase1');

it('names the changed fields when a client is updated', function () {
    $client = Client::factory()->create(['name' => 'Acme Ltd', 'contact_info' => $this->contacts]);

    $this->service->update($this->admin, $client, [
        'name' => 'Acme International',
        'contacts' => [],
    ]);

    $activity = ActivityLog::where('object_id', $client->id)->sole();

    expect($client->fresh()->name)->toBe('Acme International')
        ->and($client->fresh()->contact_info)->toBe([])
        ->and($activity->description)->toContain('name')
        ->and($activity->description)->toContain('contacts')
        ->and($activity->actor_id)->toBe($this->admin->id);
})->group('phase1');

it('deactivates rather than deleting', function () {
    $client = Client::factory()->create();

    $this->service->deactivate($this->admin, $client);

    expect(Client::find($client->id))->not->toBeNull()
        ->and($client->fresh()->status)->toBe(ClientStatus::Inactive)
        ->and(ActivityLog::where('object_id', $client->id)->sole()->description)->toBe('Client deactivated');
})->group('phase1');

it('refuses every write to a manager and an employee', function () {
    $client = Client::factory()->create(['name' => 'Acme Ltd']);

    foreach ([$this->manager, $this->employee] as $actor) {
        expect(fn () => $this->service->create($actor, ['name' => 'New Co']))
            ->toThrow(AuthorizationException::class)
            ->and(fn () => $this->service->update($actor, $client, ['name' => 'Renamed']))
            ->toThrow(AuthorizationException::class)
            ->and(fn () => $this->service->deactivate($actor, $client))
            ->toThrow(AuthorizationException::class);
    }

    expect($client->fresh()->name)->toBe('Acme Ltd')
        ->and($client->fresh()->status)->toBe(ClientStatus::Active)
        ->and(Client::where('name', 'New Co')->exists())->toBeFalse()
        ->and(ActivityLog::count())->toBe(0);
})->group('phase1');
