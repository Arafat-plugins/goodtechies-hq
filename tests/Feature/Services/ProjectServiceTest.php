<?php

use App\Exceptions\ProjectStateException;
use App\Models\ActivityLog;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectService;
use App\Support\BillingType;
use App\Support\Priority;
use App\Support\ProjectStatus;
use App\Support\ProjectType;
use App\Support\RoleName;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->service = app(ProjectService::class);

    $this->admin = projectServiceUser(RoleName::ADMIN);
    $this->manager = projectServiceUser(RoleName::MANAGER);
    $this->employee = projectServiceUser(RoleName::EMPLOYEE);
    $this->second = projectServiceUser(RoleName::EMPLOYEE);

    $this->client = Client::factory()->create();

    $this->project = Project::factory()->forClient($this->client)->create([
        'status' => ProjectStatus::Active,
        'priority' => Priority::Medium,
        'pm_id' => $this->manager->employee->id,
    ]);
});

/**
 * A user with the given role and an employee record, built from factories because the seeded
 * team has no Manager.
 */
function projectServiceUser(RoleName $role): User
{
    $user = User::factory()->create();
    Employee::factory()->for($user)->forRole($role)->create();

    return $user->fresh();
}

/**
 * @return array<string, mixed>
 */
function projectAttributes(Client $client): array
{
    return [
        'client_id' => $client->id,
        'name' => 'Acme SEO',
        'domain' => 'acme.test',
        'project_type' => ProjectType::Seo,
        'billing_type' => BillingType::MonthlyRecurring,
        'status' => ProjectStatus::Active,
        'priority' => Priority::High,
    ];
}

it('creates a project with one audit row and one activity row', function () {
    $project = $this->service->create($this->admin, projectAttributes($this->client));

    $audit = AuditLog::where('event', 'project.created')->sole();

    expect($project->name)->toBe('Acme SEO')
        ->and($audit->actor_id)->toBe($this->admin->id)
        ->and($audit->target_type)->toBe($project->getMorphClass())
        ->and($audit->target_id)->toBe($project->id)
        ->and($audit->old_value)->toBeNull()
        ->and($audit->new_value['name'])->toBe('Acme SEO')
        ->and($audit->new_value['project_type'])->toBe('seo')
        ->and($audit->new_value)->not->toHaveKey('price')
        ->and(ActivityLog::where('object_id', $project->id)->sole()->description)->toBe('Project created');
})->group('phase1');

it('attaches the members given at creation', function () {
    $project = $this->service->create(
        $this->admin,
        projectAttributes($this->client),
        [$this->employee->employee->id, $this->second->employee->id],
    );

    expect($project->members()->pluck('employees.id')->all())
        ->toEqualCanonicalizing([$this->employee->employee->id, $this->second->employee->id]);
})->group('phase1');

it('writes the finance row through the finance service at creation, with no price audit row', function () {
    $project = $this->service->create(
        $this->admin,
        projectAttributes($this->client),
        [],
        ['price' => '2500.00', 'contract_terms' => 'Six months'],
    );

    expect($project->finance->price)->toBe('2500.00')
        ->and(AuditLog::where('event', 'project.price_changed')->count())->toBe(0)
        ->and(AuditLog::where('event', 'project.created')->count())->toBe(1);
})->group('phase1');

it('rolls the whole creation back when the actor may not write the finance', function () {
    expect(fn () => $this->service->create(
        $this->manager,
        projectAttributes($this->client),
        [],
        ['price' => '2500.00'],
    ))->toThrow(AuthorizationException::class);

    expect(Project::where('name', 'Acme SEO')->exists())->toBeFalse()
        ->and(AuditLog::count())->toBe(0);
})->group('phase1');

it('refuses creation and update to an employee', function () {
    expect(fn () => $this->service->create($this->employee, projectAttributes($this->client)))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => $this->service->update($this->employee, $this->project, ['name' => 'Renamed']))
        ->toThrow(AuthorizationException::class);

    expect($this->project->fresh()->name)->not->toBe('Renamed')
        ->and(ActivityLog::count())->toBe(0);
})->group('phase1');

it('names the changed fields in the update activity line', function () {
    $this->service->update($this->admin, $this->project, [
        'name' => 'Acme SEO v2',
        'priority' => Priority::Urgent,
    ]);

    $activity = ActivityLog::where('object_id', $this->project->id)->sole();

    expect($this->project->fresh()->name)->toBe('Acme SEO v2')
        ->and($activity->description)->toContain('name')
        ->and($activity->description)->toContain('priority')
        ->and(AuditLog::count())->toBe(0);
})->group('phase1');

it('refuses to update an archived project', function () {
    $archived = Project::factory()->archived()->create();

    expect(fn () => $this->service->update($this->admin, $archived, ['name' => 'Nope']))
        ->toThrow(ProjectStateException::class)
        ->and(fn () => $this->service->syncMembers($this->admin, $archived, [$this->employee->employee->id]))
        ->toThrow(ProjectStateException::class)
        ->and(fn () => $this->service->changeStatus($this->admin, $archived, ProjectStatus::Active))
        ->toThrow(ProjectStateException::class);

    expect($archived->fresh()->name)->not->toBe('Nope');
})->group('phase1');

it('adds and removes members, logging each one', function () {
    $this->project->members()->attach($this->employee->employee->id, ['role_on_project' => 'developer']);

    $this->service->syncMembers(
        $this->admin,
        $this->project,
        [$this->second->employee->id],
        [$this->second->employee->id => 'seo'],
    );

    $descriptions = ActivityLog::where('object_id', $this->project->id)->pluck('description')->all();

    expect($this->project->members()->pluck('employees.id')->all())->toBe([$this->second->employee->id])
        ->and($this->project->members()->first()->pivot->role_on_project)->toBe('seo')
        ->and($descriptions)->toContain('Member added: '.$this->second->name)
        ->and($descriptions)->toContain('Member removed: '.$this->employee->name);
})->group('phase1');

it('refuses member management to an unassigned manager', function () {
    $other = Project::factory()->create();

    expect(fn () => $this->service->syncMembers($this->manager, $other, [$this->employee->employee->id]))
        ->toThrow(AuthorizationException::class);
})->group('phase1');

it('allows the lifecycle transitions the spec lists', function (string $from, string $to, string $actor) {
    $project = Project::factory()->create(['status' => ProjectStatus::from($from)]);

    $result = $this->service->changeStatus($this->{$actor}, $project, ProjectStatus::from($to));

    expect($result->status)->toBe(ProjectStatus::from($to));
})->with([
    ['active', 'on_hold', 'admin'],
    ['on_hold', 'active', 'admin'],
    ['active', 'completed', 'admin'],
    ['on_hold', 'completed', 'admin'],
    ['active', 'cancelled', 'admin'],
    ['on_hold', 'cancelled', 'admin'],
    ['completed', 'cancelled', 'admin'],
    ['completed', 'active', 'admin'],
    ['cancelled', 'active', 'admin'],
])->group('phase1');

it('refuses the transitions the spec does not list', function (string $from, string $to) {
    $project = Project::factory()->create(['status' => ProjectStatus::from($from)]);

    expect(fn () => $this->service->changeStatus($this->admin, $project, ProjectStatus::from($to)))
        ->toThrow(ProjectStateException::class);

    expect($project->fresh()->status)->toBe(ProjectStatus::from($from));
})->with([
    ['active', 'active'],
    ['active', 'archived'],
    ['completed', 'on_hold'],
    ['cancelled', 'on_hold'],
    ['cancelled', 'completed'],
    ['completed', 'completed'],
])->group('phase1');

it('keeps cancelling and reopening to admins', function () {
    expect(fn () => $this->service->changeStatus($this->manager, $this->project, ProjectStatus::Cancelled))
        ->toThrow(AuthorizationException::class);

    $completed = Project::factory()->create([
        'status' => ProjectStatus::Completed,
        'pm_id' => $this->manager->employee->id,
    ]);

    expect(fn () => $this->service->changeStatus($this->manager, $completed, ProjectStatus::Active))
        ->toThrow(AuthorizationException::class);

    // The manager may still move their own project between the open statuses.
    expect($this->service->changeStatus($this->manager, $this->project, ProjectStatus::OnHold)->status)
        ->toBe(ProjectStatus::OnHold);
})->group('phase1');

it('leaves the Phase 2 line on the timeline when a project is cancelled', function () {
    $this->service->changeStatus($this->admin, $this->project, ProjectStatus::Cancelled, 'Client stopped paying');

    $descriptions = ActivityLog::where('object_id', $this->project->id)->pluck('description')->all();

    expect($descriptions)->toContain('Project cancelled — open tasks must be closed or reassigned (Phase 2)')
        ->and($descriptions)->toContain('Status changed from Active to Cancelled — Client stopped paying');
})->group('phase1');

it('archives and unarchives a project, remembering the status it had', function () {
    $archived = $this->service->archive($this->admin, $this->project);

    expect($archived->status)->toBe(ProjectStatus::Archived)
        ->and($archived->archived_at)->not->toBeNull()
        ->and($archived->isArchived())->toBeTrue();

    $restored = $this->service->unarchive($this->admin, $archived);

    expect($restored->status)->toBe(ProjectStatus::Active)
        ->and($restored->archived_at)->toBeNull();

    $descriptions = ActivityLog::where('object_id', $this->project->id)->pluck('description')->all();

    expect($descriptions)->toContain('Project archived (was Active)')
        ->and($descriptions)->toContain('Project unarchived');
})->group('phase1');

it('keeps archiving to admins and refuses a double archive', function () {
    expect(fn () => $this->service->archive($this->manager, $this->project))
        ->toThrow(AuthorizationException::class);

    $archived = $this->service->archive($this->admin, $this->project);

    expect(fn () => $this->service->archive($this->admin, $archived))
        ->toThrow(ProjectStateException::class)
        ->and(fn () => $this->service->unarchive($this->manager, $archived))
        ->toThrow(AuthorizationException::class);

    $this->service->unarchive($this->admin, $archived);

    expect(fn () => $this->service->unarchive($this->admin, $archived->fresh()))
        ->toThrow(ProjectStateException::class);
})->group('phase1');
