<?php

use App\Models\Client;
use App\Models\Employee;
use App\Models\Permission as PermissionModel;
use App\Models\Project;
use App\Models\User;
use App\Models\UserProjectPermission;
use App\Support\Permission;
use App\Support\RoleName;
use App\Support\UserStatus;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->admin = projectPolicyUser(RoleName::ADMIN);
    $this->manager = projectPolicyUser(RoleName::MANAGER);
    $this->otherManager = projectPolicyUser(RoleName::MANAGER);
    $this->employee = projectPolicyUser(RoleName::EMPLOYEE);
    $this->remote = projectPolicyUser(RoleName::REMOTE_EMPLOYEE);
    $this->accountant = projectPolicyUser(RoleName::ACCOUNTANT);

    $this->client = Client::factory()->create();
    $this->project = Project::factory()->forClient($this->client)->create([
        'pm_id' => $this->manager->employee->id,
    ]);
    $this->project->members()->attach($this->employee->employee->id, ['role_on_project' => 'developer']);
    $this->project->members()->attach($this->remote->employee->id, ['role_on_project' => 'seo']);

    $this->other = Project::factory()->create();
});

/**
 * A user with the given role and an employee record, built from factories because the seeded
 * team has no Manager.
 */
function projectPolicyUser(RoleName $role): User
{
    $user = User::factory()->create();
    Employee::factory()->for($user)->forRole($role)->create();

    return $user->fresh();
}

function grantProjectPermission(User $user, Project $project, Permission $permission): void
{
    UserProjectPermission::create([
        'user_id' => $user->id,
        'project_id' => $project->id,
        'permission_id' => PermissionModel::where('key', $permission->value)->firstOrFail()->id,
    ]);
}

it('lets every role with projects.view list projects, and nobody else', function () {
    expect(Gate::forUser($this->admin)->allows('viewAny', Project::class))->toBeTrue()
        ->and(Gate::forUser($this->manager)->allows('viewAny', Project::class))->toBeTrue()
        ->and(Gate::forUser($this->employee)->allows('viewAny', Project::class))->toBeTrue()
        ->and(Gate::forUser($this->remote)->allows('viewAny', Project::class))->toBeTrue()
        ->and(Gate::forUser($this->accountant)->allows('viewAny', Project::class))->toBeFalse();
})->group('phase1');

it('shows every project to admins and managers, and only assigned ones to employees', function () {
    expect(Gate::forUser($this->admin)->allows('view', $this->other))->toBeTrue()
        ->and(Gate::forUser($this->otherManager)->allows('view', $this->other))->toBeTrue()
        ->and(Gate::forUser($this->employee)->allows('view', $this->project))->toBeTrue()
        ->and(Gate::forUser($this->employee)->allows('view', $this->other))->toBeFalse()
        ->and(Gate::forUser($this->remote)->allows('view', $this->project))->toBeTrue()
        ->and(Gate::forUser($this->remote)->allows('view', $this->other))->toBeFalse()
        ->and(Gate::forUser($this->accountant)->allows('view', $this->project))->toBeFalse();
})->group('phase1');

it('keeps an archived project viewable', function () {
    $archived = Project::factory()->archived()->create(['pm_id' => $this->employee->employee->id]);

    expect(Gate::forUser($this->admin)->allows('view', $archived))->toBeTrue()
        ->and(Gate::forUser($this->employee)->allows('view', $archived))->toBeTrue();
})->group('phase1');

it('lets admins and managers create projects but not employees or accountants', function () {
    expect(Gate::forUser($this->admin)->allows('create', Project::class))->toBeTrue()
        ->and(Gate::forUser($this->manager)->allows('create', Project::class))->toBeTrue()
        ->and(Gate::forUser($this->employee)->allows('create', Project::class))->toBeFalse()
        ->and(Gate::forUser($this->remote)->allows('create', Project::class))->toBeFalse()
        ->and(Gate::forUser($this->accountant)->allows('create', Project::class))->toBeFalse();
})->group('phase1');

it('scopes a manager\'s update and member management to their own projects', function () {
    foreach (['update', 'manageMembers'] as $ability) {
        expect(Gate::forUser($this->admin)->allows($ability, $this->project))->toBeTrue()
            ->and(Gate::forUser($this->admin)->allows($ability, $this->other))->toBeTrue()
            ->and(Gate::forUser($this->manager)->allows($ability, $this->project))->toBeTrue()
            ->and(Gate::forUser($this->manager)->allows($ability, $this->other))->toBeFalse()
            ->and(Gate::forUser($this->otherManager)->allows($ability, $this->project))->toBeFalse()
            ->and(Gate::forUser($this->employee)->allows($ability, $this->project))->toBeFalse()
            ->and(Gate::forUser($this->remote)->allows($ability, $this->project))->toBeFalse()
            ->and(Gate::forUser($this->accountant)->allows($ability, $this->project))->toBeFalse();
    }
})->group('phase1');

it('counts a member as assigned for a manager, not only the pm', function () {
    $this->other->members()->attach($this->otherManager->employee->id, ['role_on_project' => 'lead']);

    expect(Gate::forUser($this->otherManager)->allows('update', $this->other->fresh()))->toBeTrue();
})->group('phase1');

it('refuses to update an archived project, whoever asks', function () {
    $archived = Project::factory()->archived()->create(['pm_id' => $this->manager->employee->id]);

    expect(Gate::forUser($this->admin)->allows('update', $archived))->toBeFalse()
        ->and(Gate::forUser($this->manager)->allows('update', $archived))->toBeFalse()
        ->and(Gate::forUser($this->admin)->allows('manageMembers', $archived))->toBeFalse()
        ->and(Gate::forUser($this->admin)->allows('updateFinance', $archived))->toBeFalse()
        ->and(Gate::forUser($this->admin)->allows('unarchive', $archived))->toBeTrue();
})->group('phase1');

it('keeps archive, unarchive and cancel to admins', function () {
    foreach (['archive', 'unarchive', 'cancel'] as $ability) {
        expect(Gate::forUser($this->admin)->allows($ability, $this->project))->toBeTrue()
            ->and(Gate::forUser($this->manager)->allows($ability, $this->project))->toBeFalse()
            ->and(Gate::forUser($this->employee)->allows($ability, $this->project))->toBeFalse()
            ->and(Gate::forUser($this->remote)->allows($ability, $this->project))->toBeFalse()
            ->and(Gate::forUser($this->accountant)->allows($ability, $this->project))->toBeFalse();
    }
})->group('phase1');

it('scopes the commercial view to clients.view_full, and for a manager to their projects', function () {
    expect(Gate::forUser($this->admin)->allows('viewCommercial', $this->other))->toBeTrue()
        ->and(Gate::forUser($this->manager)->allows('viewCommercial', $this->project))->toBeTrue()
        ->and(Gate::forUser($this->manager)->allows('viewCommercial', $this->other))->toBeFalse()
        ->and(Gate::forUser($this->employee)->allows('viewCommercial', $this->project))->toBeFalse()
        ->and(Gate::forUser($this->remote)->allows('viewCommercial', $this->project))->toBeFalse()
        ->and(Gate::forUser($this->accountant)->allows('viewCommercial', $this->project))->toBeFalse();
})->group('phase1');

it('shows money to admins only, and to a per-project grant for that project alone', function () {
    expect(Gate::forUser($this->admin)->allows('viewFinance', $this->project))->toBeTrue()
        ->and(Gate::forUser($this->manager)->allows('viewFinance', $this->project))->toBeFalse()
        ->and(Gate::forUser($this->employee)->allows('viewFinance', $this->project))->toBeFalse()
        ->and(Gate::forUser($this->accountant)->allows('viewFinance', $this->project))->toBeFalse();

    grantProjectPermission($this->employee, $this->project, Permission::ProjectsViewFinance);

    expect(Gate::forUser($this->employee)->allows('viewFinance', $this->project))->toBeTrue()
        ->and(Gate::forUser($this->employee)->allows('viewFinance', $this->other))->toBeFalse();
})->group('phase1');

it('treats a per-project grant as a view grant, not a write one', function () {
    grantProjectPermission($this->employee, $this->project, Permission::ProjectsViewFinance);

    expect(Gate::forUser($this->employee)->allows('viewFinance', $this->project))->toBeTrue()
        ->and(Gate::forUser($this->employee)->allows('updateFinance', $this->project))->toBeFalse()
        ->and(Gate::forUser($this->admin)->allows('updateFinance', $this->project))->toBeTrue()
        ->and(Gate::forUser($this->manager)->allows('updateFinance', $this->project))->toBeFalse();
})->group('phase1');

it('denies an inactive admin everything', function () {
    $this->admin->update(['status' => UserStatus::Inactive]);
    $admin = $this->admin->fresh();

    grantProjectPermission($admin, $this->project, Permission::ProjectsViewFinance);

    expect(Gate::forUser($admin)->allows('view', $this->project))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('update', $this->project))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('viewCommercial', $this->project))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('viewFinance', $this->project))->toBeFalse();
})->group('phase1');

it('scopes a project list query to what the user may see', function () {
    $visible = fn (User $user) => Project::query()->visibleTo($user)->pluck('id')->sort()->values()->all();

    expect($visible($this->admin))->toEqualCanonicalizing([$this->project->id, $this->other->id])
        ->and($visible($this->otherManager))->toEqualCanonicalizing([$this->project->id, $this->other->id])
        ->and($visible($this->employee))->toBe([$this->project->id])
        ->and($visible($this->remote))->toBe([$this->project->id])
        ->and($visible($this->manager))->toEqualCanonicalizing([$this->project->id, $this->other->id])
        ->and($visible($this->accountant))->toBe([]);
})->group('phase1');
