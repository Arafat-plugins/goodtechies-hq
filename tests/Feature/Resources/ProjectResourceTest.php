<?php

use App\Http\Resources\ProjectResource;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Permission as PermissionModel;
use App\Models\Project;
use App\Models\User;
use App\Models\UserProjectPermission;
use App\Support\BillingFrequency;
use App\Support\BillingType;
use App\Support\Permission;
use App\Support\Priority;
use App\Support\ProjectStatus;
use App\Support\ProjectType;
use App\Support\RoleName;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\Request;

/** The keys every requester gets, whatever their role. */
const PUBLIC_PROJECT_KEYS = [
    'id',
    'name',
    'domain',
    'project_type',
    'project_type_label',
    'status',
    'status_label',
    'priority',
    'priority_label',
    'start_date',
    'deadline',
    'archived_at',
    'is_archived',
    'employee_notes',
    'pm',
    'members',
    'permissions',
];

/** Keys that must never appear in a payload built for someone without the matching policy. */
const RESTRICTED_PROJECT_KEYS = [
    'client',
    'client_name',
    'contacts',
    'internal_notes',
    'price',
    'recurring_amount',
    'billing_type',
    'contract_value',
    'contract_terms',
    'profitability_snapshot',
    'finance',
];

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->admin = projectResourceUser(RoleName::ADMIN);
    $this->manager = projectResourceUser(RoleName::MANAGER);
    $this->otherManager = projectResourceUser(RoleName::MANAGER);
    $this->employee = projectResourceUser(RoleName::EMPLOYEE);
    $this->remote = projectResourceUser(RoleName::REMOTE_EMPLOYEE);

    $this->client = Client::factory()->create(['name' => 'Acme Ltd']);

    $this->project = Project::factory()->forClient($this->client)->create([
        'name' => 'Acme SEO',
        'domain' => 'acme.test',
        'project_type' => ProjectType::Seo,
        'billing_type' => BillingType::MonthlyRecurring,
        'status' => ProjectStatus::Active,
        'priority' => Priority::High,
        'pm_id' => $this->manager->employee->id,
        'internal_notes' => 'Pays late, chase on the 5th.',
        'employee_notes' => 'Weekly report due Sunday.',
    ]);

    $this->project->members()->attach($this->employee->employee->id, ['role_on_project' => 'developer']);
    $this->project->members()->attach($this->remote->employee->id, ['role_on_project' => 'seo']);

    $this->project->finance()->create([
        'price' => '1500.00',
        'recurring_amount' => '250.00',
        'billing_frequency' => BillingFrequency::Monthly->value,
        'contract_value' => '9000.00',
        'contract_terms' => 'Twelve months, notice of one.',
        'profitability_snapshot' => '42.50',
    ]);

    $this->project = Project::with(['client', 'finance', 'members.user', 'pm.user'])->findOrFail($this->project->id);
});

/**
 * A user with the given role and an employee record, built from factories because the seeded
 * team has no Manager.
 */
function projectResourceUser(RoleName $role): User
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
function projectPayload(Project $project, ?User $user): array
{
    $request = Request::create('/');
    $request->setUserResolver(fn () => $user);

    return (new ProjectResource($project))->toArray($request);
}

it('gives an admin the public, commercial and finance keys', function () {
    $payload = projectPayload($this->project, $this->admin);

    expect(array_keys($payload))->toEqualCanonicalizing(array_merge(PUBLIC_PROJECT_KEYS, [
        'client',
        'internal_notes',
        'billing_type',
        'billing_type_label',
        'finance',
    ]));

    expect($payload['client'])->toBe(['id' => $this->client->id, 'name' => 'Acme Ltd'])
        ->and($payload['internal_notes'])->toBe('Pays late, chase on the 5th.')
        ->and($payload['billing_type'])->toBe('monthly_recurring')
        ->and($payload['billing_type_label'])->toBe('Monthly Recurring')
        ->and($payload['finance']['price'])->toBe('1500.00')
        ->and($payload['finance']['recurring_amount'])->toBe('250.00')
        ->and($payload['finance']['billing_frequency_label'])->toBe('Monthly')
        ->and($payload['finance']['contract_value'])->toBe('9000.00')
        ->and($payload['finance']['contract_terms'])->toBe('Twelve months, notice of one.')
        ->and($payload['finance']['profitability_snapshot'])->toBe('42.50');
})->group('phase1');

it('gives an assigned manager the commercial keys but no money', function () {
    $payload = projectPayload($this->project, $this->manager);

    expect(array_keys($payload))->toEqualCanonicalizing(array_merge(PUBLIC_PROJECT_KEYS, [
        'client',
        'internal_notes',
    ]));

    foreach (['billing_type', 'finance', 'price', 'contract_value'] as $key) {
        expect($payload)->not->toHaveKey($key);
    }
})->group('phase1');

it('gives an unassigned manager the public keys only', function () {
    $payload = projectPayload($this->project, $this->otherManager);

    expect(array_keys($payload))->toEqualCanonicalizing(PUBLIC_PROJECT_KEYS);

    foreach (RESTRICTED_PROJECT_KEYS as $key) {
        $this->assertArrayNotHasKey($key, $payload);
    }
})->group('phase1');

it('never leaks a restricted key to an assigned employee or remote employee', function () {
    foreach ([$this->employee, $this->remote] as $user) {
        $payload = projectPayload($this->project, $user);

        expect(array_keys($payload))->toEqualCanonicalizing(PUBLIC_PROJECT_KEYS);

        foreach (RESTRICTED_PROJECT_KEYS as $key) {
            $this->assertArrayNotHasKey($key, $payload);
        }

        expect($payload['employee_notes'])->toBe('Weekly report due Sunday.');
    }
})->group('phase1');

it('serialises the public fields the same way for everyone', function () {
    $payload = projectPayload($this->project, $this->employee);

    expect($payload['id'])->toBe($this->project->id)
        ->and($payload['name'])->toBe('Acme SEO')
        ->and($payload['domain'])->toBe('acme.test')
        ->and($payload['project_type'])->toBe('seo')
        ->and($payload['project_type_label'])->toBe('SEO')
        ->and($payload['status'])->toBe('active')
        ->and($payload['status_label'])->toBe('Active')
        ->and($payload['priority'])->toBe('high')
        ->and($payload['priority_label'])->toBe('High')
        ->and($payload['start_date'])->toBe($this->project->start_date->toDateString())
        ->and($payload['deadline'])->toBe($this->project->deadline->toDateString())
        ->and($payload['archived_at'])->toBeNull()
        ->and($payload['is_archived'])->toBeFalse()
        ->and($payload['pm'])->toBe(['id' => $this->manager->employee->id, 'name' => $this->manager->name]);

    expect($payload['members'])->toHaveCount(2)
        ->and(collect($payload['members'])->firstWhere('id', $this->employee->employee->id))
        ->toBe([
            'id' => $this->employee->employee->id,
            'name' => $this->employee->name,
            'role_on_project' => 'developer',
        ]);
})->group('phase1');

it('leaves members empty rather than lazy-loading the relation', function () {
    $lean = Project::findOrFail($this->project->id);

    expect(projectPayload($lean, $this->admin)['members'])->toBe([]);
})->group('phase1');

it('shows a null client for an internal project to an admin', function () {
    $internal = Project::factory()->internal()->create();

    $payload = projectPayload($internal, $this->admin);

    expect($payload)->toHaveKey('client')
        ->and($payload['client'])->toBeNull()
        ->and($payload['pm'])->toBeNull();
})->group('phase1');

it('opens the finance keys for the project a per-project grant names, and no other', function () {
    $other = Project::factory()->withFinance()->create();

    UserProjectPermission::create([
        'user_id' => $this->employee->id,
        'project_id' => $this->project->id,
        'permission_id' => PermissionModel::where('key', Permission::ProjectsViewFinance->value)->firstOrFail()->id,
    ]);

    $granted = projectPayload($this->project, $this->employee);
    $ungranted = projectPayload($other, $this->employee);

    expect($granted)->toHaveKey('finance')
        ->and($granted['finance']['price'])->toBe('1500.00')
        ->and($granted)->toHaveKey('billing_type')
        ->and($granted)->not->toHaveKey('client')
        ->and($granted)->not->toHaveKey('internal_notes');

    $this->assertArrayNotHasKey('finance', $ungranted);
    $this->assertArrayNotHasKey('billing_type', $ungranted);
})->group('phase1');

it('reports null finance for a project with no finance row', function () {
    $bare = Project::factory()->create();

    expect(projectPayload($bare, $this->admin)['finance'])->toBeNull();
})->group('phase1');

it('mirrors the policy in the permissions block', function () {
    expect(projectPayload($this->project, $this->admin)['permissions'])->toBe([
        'can_update' => true,
        'can_view_finance' => true,
        'can_manage_members' => true,
        'can_archive' => true,
    ]);

    expect(projectPayload($this->project, $this->manager)['permissions'])->toBe([
        'can_update' => true,
        'can_view_finance' => false,
        'can_manage_members' => true,
        'can_archive' => false,
    ]);

    expect(projectPayload($this->project, $this->employee)['permissions'])->toBe([
        'can_update' => false,
        'can_view_finance' => false,
        'can_manage_members' => false,
        'can_archive' => false,
    ]);

    $archived = Project::factory()->archived()->create();

    expect(projectPayload($archived, $this->admin)['permissions'])->toBe([
        'can_update' => false,
        'can_view_finance' => true,
        'can_manage_members' => false,
        'can_archive' => true,
    ]);
})->group('phase1');

it('restricts everything when there is no authenticated user', function () {
    $payload = projectPayload($this->project, null);

    expect(array_keys($payload))->toEqualCanonicalizing(PUBLIC_PROJECT_KEYS);

    foreach (RESTRICTED_PROJECT_KEYS as $key) {
        $this->assertArrayNotHasKey($key, $payload);
    }
})->group('phase1');

it('serialises a list through the collection', function () {
    $request = Request::create('/');
    $request->setUserResolver(fn () => $this->employee);

    $list = ProjectResource::collection(Project::query()->visibleTo($this->employee)->get())
        ->toArray($request);

    expect($list)->toHaveCount(1)
        ->and(array_keys($list[0]))->toEqualCanonicalizing(PUBLIC_PROJECT_KEYS);
})->group('phase1');
