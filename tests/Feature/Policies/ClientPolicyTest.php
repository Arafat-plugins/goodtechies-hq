<?php

use App\Models\Client;
use App\Models\Employee;
use App\Models\Project;
use App\Models\User;
use App\Support\RoleName;
use App\Support\UserStatus;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->admin = clientPolicyUser(RoleName::ADMIN);
    $this->manager = clientPolicyUser(RoleName::MANAGER);
    $this->employee = clientPolicyUser(RoleName::EMPLOYEE);
    $this->remote = clientPolicyUser(RoleName::REMOTE_EMPLOYEE);
    $this->accountant = clientPolicyUser(RoleName::ACCOUNTANT);

    $this->client = Client::factory()->create();
    $this->otherClient = Client::factory()->create();

    Project::factory()->forClient($this->client)->create(['pm_id' => $this->manager->employee->id]);
    Project::factory()->forClient($this->otherClient)->create();
});

/**
 * A user with the given role and an employee record, built from factories because the seeded
 * team has no Manager.
 */
function clientPolicyUser(RoleName $role): User
{
    $user = User::factory()->create();
    Employee::factory()->for($user)->forRole($role)->create();

    return $user->fresh();
}

it('lets clients.view_full roles list clients, and nobody else', function () {
    expect(Gate::forUser($this->admin)->allows('viewAny', Client::class))->toBeTrue()
        ->and(Gate::forUser($this->manager)->allows('viewAny', Client::class))->toBeTrue()
        ->and(Gate::forUser($this->employee)->allows('viewAny', Client::class))->toBeFalse()
        ->and(Gate::forUser($this->remote)->allows('viewAny', Client::class))->toBeFalse()
        ->and(Gate::forUser($this->accountant)->allows('viewAny', Client::class))->toBeFalse();
})->group('phase1');

it('scopes a manager to clients whose projects they are on', function () {
    expect(Gate::forUser($this->admin)->allows('view', $this->otherClient))->toBeTrue()
        ->and(Gate::forUser($this->manager)->allows('view', $this->client))->toBeTrue()
        ->and(Gate::forUser($this->manager)->allows('view', $this->otherClient))->toBeFalse()
        ->and(Gate::forUser($this->employee)->allows('view', $this->client))->toBeFalse()
        ->and(Gate::forUser($this->remote)->allows('view', $this->client))->toBeFalse()
        ->and(Gate::forUser($this->accountant)->allows('view', $this->client))->toBeFalse();
})->group('phase1');

it('counts membership, not only the pm seat, for a manager', function () {
    $project = Project::factory()->forClient($this->otherClient)->create();
    $project->members()->attach($this->manager->employee->id, ['role_on_project' => 'lead']);

    expect(Gate::forUser($this->manager)->allows('view', $this->otherClient))->toBeTrue();
})->group('phase1');

it('keeps writing clients to admins', function () {
    foreach (['update', 'delete'] as $ability) {
        expect(Gate::forUser($this->admin)->allows($ability, $this->client))->toBeTrue()
            ->and(Gate::forUser($this->manager)->allows($ability, $this->client))->toBeFalse()
            ->and(Gate::forUser($this->employee)->allows($ability, $this->client))->toBeFalse()
            ->and(Gate::forUser($this->accountant)->allows($ability, $this->client))->toBeFalse();
    }

    expect(Gate::forUser($this->admin)->allows('create', Client::class))->toBeTrue()
        ->and(Gate::forUser($this->manager)->allows('create', Client::class))->toBeFalse()
        ->and(Gate::forUser($this->employee)->allows('create', Client::class))->toBeFalse()
        ->and(Gate::forUser($this->accountant)->allows('create', Client::class))->toBeFalse();
})->group('phase1');

it('denies an inactive admin every client ability', function () {
    $this->admin->update(['status' => UserStatus::Inactive]);
    $admin = $this->admin->fresh();

    expect(Gate::forUser($admin)->allows('viewAny', Client::class))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('view', $this->client))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('update', $this->client))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('delete', $this->client))->toBeFalse();
})->group('phase1');
