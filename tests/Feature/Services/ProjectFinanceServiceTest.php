<?php

use App\Exceptions\ProjectStateException;
use App\Models\ActivityLog;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Permission as PermissionModel;
use App\Models\Project;
use App\Models\ProjectFinance;
use App\Models\User;
use App\Models\UserProjectPermission;
use App\Services\ProjectFinanceService;
use App\Services\ProjectService;
use App\Support\BillingFrequency;
use App\Support\BillingType;
use App\Support\Permission;
use App\Support\Priority;
use App\Support\ProjectStatus;
use App\Support\ProjectType;
use App\Support\RoleName;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->service = app(ProjectFinanceService::class);
    $this->projects = app(ProjectService::class);

    $this->admin = financeServiceUser(RoleName::ADMIN);
    $this->manager = financeServiceUser(RoleName::MANAGER);
    $this->employee = financeServiceUser(RoleName::EMPLOYEE);

    // Reloaded, so it behaves like a project a controller resolved: one that already existed
    // before this write, rather than one created moments ago.
    $this->project = Project::factory()->create([
        'status' => ProjectStatus::Active,
        'pm_id' => $this->manager->employee->id,
    ])->fresh();
});

/**
 * A user with the given role and an employee record, built from factories because the seeded
 * team has no Manager.
 */
function financeServiceUser(RoleName $role): User
{
    $user = User::factory()->create();
    Employee::factory()->for($user)->forRole($role)->create();

    return $user->fresh();
}

it('creates the first row on an existing project and audits it as a price change', function () {
    $finance = $this->service->upsert($this->admin, $this->project, [
        'price' => '1200.00',
        'billing_frequency' => BillingFrequency::Monthly->value,
    ]);

    $audit = AuditLog::where('event', 'project.price_changed')->sole();

    expect($finance->price)->toBe('1200.00')
        ->and($audit->target_type)->toBe($this->project->getMorphClass())
        ->and($audit->target_id)->toBe($this->project->id)
        ->and($audit->actor_id)->toBe($this->admin->id)
        ->and($audit->old_value)->toBeNull()
        ->and($audit->new_value)->toBe(['price' => '1200.00', 'recurring_amount' => null])
        ->and(ActivityLog::where('object_id', $this->project->id)->sole()->description)
        ->toBe('Project finance updated');
})->group('phase1');

it('writes no price audit row for the first row on a project being created', function () {
    $project = $this->projects->create(
        $this->admin,
        [
            'name' => 'Fresh',
            'project_type' => ProjectType::Seo,
            'billing_type' => BillingType::OneTime,
            'status' => ProjectStatus::Active,
            'priority' => Priority::Medium,
        ],
        [],
        ['price' => '900.00'],
    );

    expect(AuditLog::where('event', 'project.price_changed')->count())->toBe(0);

    // The next change to that same row is audited, normally.
    $this->service->upsert($this->admin, $project, ['price' => '1000.00']);

    $audit = AuditLog::where('event', 'project.price_changed')->sole();

    expect($audit->old_value)->toBe(['price' => '900.00'])
        ->and($audit->new_value)->toBe(['price' => '1000.00']);
})->group('phase1');

it('audits exactly the money fields that moved', function () {
    ProjectFinance::factory()->for($this->project)->create([
        'price' => '500.00',
        'recurring_amount' => '100.00',
        'contract_terms' => 'Old terms',
    ]);

    $this->service->upsert($this->admin, $this->project, [
        'price' => '750.00',
        'recurring_amount' => '100.00',
        'contract_terms' => 'New terms',
    ]);

    $audit = AuditLog::where('event', 'project.price_changed')->sole();

    expect($audit->old_value)->toBe(['price' => '500.00'])
        ->and($audit->new_value)->toBe(['price' => '750.00'])
        ->and($this->project->fresh()->finance->contract_terms)->toBe('New terms');
})->group('phase1');

it('writes no price audit row when only non-money fields change', function () {
    ProjectFinance::factory()->for($this->project)->create(['price' => '500.00', 'recurring_amount' => null]);

    $this->service->upsert($this->admin, $this->project, ['contract_terms' => 'Renewed']);

    expect(AuditLog::where('event', 'project.price_changed')->count())->toBe(0)
        ->and($this->project->fresh()->finance->contract_terms)->toBe('Renewed');
})->group('phase1');

it('audits a removed finance row as a price change to null', function () {
    ProjectFinance::factory()->for($this->project)->create(['price' => '500.00']);

    $this->service->forget($this->admin, $this->project);

    $audit = AuditLog::where('event', 'project.price_changed')->sole();

    expect(ProjectFinance::where('project_id', $this->project->id)->exists())->toBeFalse()
        ->and($audit->old_value)->toBe(['price' => '500.00', 'recurring_amount' => null])
        ->and($audit->new_value)->toBeNull();
})->group('phase1');

it('refuses a manager with no finance permission, even on their own project', function () {
    expect(fn () => $this->service->upsert($this->manager, $this->project, ['price' => '1.00']))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => $this->service->forget($this->manager, $this->project))
        ->toThrow(AuthorizationException::class);

    expect(ProjectFinance::count())->toBe(0)
        ->and(AuditLog::count())->toBe(0);
})->group('phase1');

it('treats a per-project grant as a view grant, never a write one', function () {
    $this->project->members()->attach($this->employee->employee->id, ['role_on_project' => 'developer']);

    UserProjectPermission::create([
        'user_id' => $this->employee->id,
        'project_id' => $this->project->id,
        'permission_id' => PermissionModel::where('key', Permission::ProjectsViewFinance->value)->firstOrFail()->id,
    ]);

    expect(fn () => $this->service->upsert($this->employee, $this->project, ['price' => '1.00']))
        ->toThrow(AuthorizationException::class);

    expect(ProjectFinance::count())->toBe(0);
})->group('phase1');

it('refuses every finance write on an archived project', function () {
    $archived = Project::factory()->archived()->withFinance()->create();

    expect(fn () => $this->service->upsert($this->admin, $archived, ['price' => '1.00']))
        ->toThrow(ProjectStateException::class)
        ->and(fn () => $this->service->forget($this->admin, $archived))
        ->toThrow(ProjectStateException::class);

    expect(AuditLog::count())->toBe(0);
})->group('phase1');
