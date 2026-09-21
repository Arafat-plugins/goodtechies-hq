<?php

use App\Models\ActivityLog;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectService;
use App\Support\AuditEvent;
use App\Support\BillingFrequency;
use App\Support\BillingType;
use App\Support\Priority;
use App\Support\ProjectStatus;
use App\Support\ProjectType;
use App\Support\RoleName;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| Admin project endpoints
|--------------------------------------------------------------------------
|
| The Vue pages exist, so component names are asserted with `component('X/Y')`.
|
*/

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->tapu = Employee::where('employee_number', 'GT-003')->firstOrFail();
    $this->yaseen = Employee::where('employee_number', 'GT-004')->firstOrFail();
    $this->shahadat = Employee::where('employee_number', 'GT-001')->firstOrFail();
});

function seededProject(string $name): Project
{
    return Project::where('name', $name)->firstOrFail();
}

it('paginates the project list at fifteen a page', function () {
    Project::factory()->count(12)->forClient(Client::where('name', 'ABC Ltd')->firstOrFail())->create();

    $this->actingAs($this->admin)
        ->get(route('admin.projects.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Projects/Index')
            ->has('projects.data', 15)
            ->where('projects.meta.total', 19)
            ->where('projects.meta.last_page', 2)
            ->has('clients', 4)
            ->has('projectManagers')
            ->has('projectTypes', count(ProjectType::cases()))
            ->has('statuses', count(ProjectStatus::cases()))
            ->has('priorities', count(Priority::cases()))
            ->has('billingTypes', count(BillingType::cases()))
            ->has('billingFrequencies', count(BillingFrequency::cases())),
        );
})->group('phase1');

it('filters the project list by search, client, type, status and pm', function () {
    $client = Client::where('name', 'Heat Gap Heating & Plumbing')->firstOrFail();

    $filtered = fn (array $query): array => $this->actingAs($this->admin)
        ->get(route('admin.projects.index', $query))
        ->assertOk()
        ->inertiaPage()['props']['projects']['data'];

    expect($filtered(['search' => 'seo']))->toHaveCount(2)
        ->and($filtered(['client_id' => $client->id]))->toHaveCount(1)
        ->and($filtered(['project_type' => ProjectType::WebsiteMaintenance->value]))->toHaveCount(3)
        ->and($filtered(['status' => ProjectStatus::OnHold->value]))->toHaveCount(1)
        ->and($filtered(['pm_id' => $this->shahadat->id]))->toHaveCount(4);
})->group('phase1');

it('leaves archived projects out of the list unless they are asked for', function () {
    $archived = Project::factory()->archived()->create(['name' => 'Retired Retainer']);

    $this->actingAs($this->admin)
        ->get(route('admin.projects.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('projects.data', 7)
            ->where('filters.archived', false),
        );

    $this->actingAs($this->admin)
        ->get(route('admin.projects.index', ['archived' => 1]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('projects.data', 8)
            ->where('filters.archived', true),
        );

    expect($archived->fresh()->isArchived())->toBeTrue();
})->group('phase1');

it('renders the create form and the detail page with their pickers', function () {
    $project = seededProject('Buffalo Modular — SEO');

    $this->actingAs($this->admin)
        ->get(route('admin.projects.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Projects/Create')
            ->has('clients', 4)
            ->has('assignableEmployees', 5),
        );

    $this->actingAs($this->admin)
        ->get(route('admin.projects.show', $project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Projects/Show')
            ->where('project.data.name', 'Buffalo Modular — SEO')
            ->has('project.data.members', 1)
            ->has('activity')
            ->has('assignableEmployees', 5),
        );

    $this->actingAs($this->admin)
        ->get(route('admin.projects.edit', $project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Projects/Edit')
            ->where('project.data.id', $project->id)
            ->has('clients', 4),
        );
})->group('phase1');

it('creates a project with its members and its finance in one request', function () {
    $client = Client::where('name', 'ABC Ltd')->firstOrFail();

    $response = $this->actingAs($this->admin)->post(route('admin.projects.store'), [
        'client_id' => $client->id,
        'name' => 'ABC — Shop Rebuild',
        'domain' => 'https://Shop.ABC.com/',
        'project_type' => ProjectType::WooCommerce->value,
        'billing_type' => BillingType::OneTime->value,
        'priority' => Priority::High->value,
        'start_date' => '2026-10-01',
        'deadline' => '2026-12-15',
        'pm_id' => $this->shahadat->id,
        'internal_notes' => 'Deposit invoiced on signature.',
        'employee_notes' => 'Reuse the existing product photography.',
        'members' => [$this->tapu->id, $this->yaseen->id],
        'finance' => [
            'price' => 6200.50,
            'billing_frequency' => BillingFrequency::Custom->value,
            'contract_terms' => '50% up front.',
        ],
    ]);

    $project = Project::where('name', 'ABC — Shop Rebuild')->firstOrFail();

    $response->assertRedirect(route('admin.projects.show', $project))
        ->assertSessionHas('success');

    expect($project->status)->toBe(ProjectStatus::Active)
        // prepareForValidation strips the scheme, the trailing slash and the capitals.
        ->and($project->domain)->toBe('shop.abc.com')
        ->and($project->members()->pluck('employees.id')->sort()->values()->all())
        ->toBe(collect([$this->tapu->id, $this->yaseen->id])->sort()->values()->all())
        ->and((float) $project->finance->price)->toBe(6200.50)
        ->and(AuditLog::where('event', AuditEvent::ProjectCreated->value)
            ->where('target_id', $project->id)
            ->count())->toBe(1);
})->group('phase1');

it('refuses a project whose deadline falls before its start date', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.projects.store'), [
            'name' => 'Backwards',
            'project_type' => ProjectType::Other->value,
            'billing_type' => BillingType::OneTime->value,
            'priority' => Priority::Low->value,
            'start_date' => '2026-10-01',
            'deadline' => '2026-09-01',
        ])
        ->assertSessionHasErrors('deadline');

    expect(Project::count())->toBe(7);
})->group('phase1');

it('updates a project', function () {
    $project = seededProject('abc.com — Monthly Maintenance');

    $this->actingAs($this->admin)
        ->put(route('admin.projects.update', $project), [
            'client_id' => $project->client_id,
            'name' => 'abc.com — Care Plan',
            'domain' => 'abc.com',
            'project_type' => ProjectType::WebsiteMaintenance->value,
            'billing_type' => BillingType::MonthlyRecurring->value,
            'priority' => Priority::Urgent->value,
            'status' => ProjectStatus::Active->value,
            'pm_id' => $project->pm_id,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $project->refresh();

    expect($project->name)->toBe('abc.com — Care Plan')
        ->and($project->priority)->toBe(Priority::Urgent);
})->group('phase1');

it('ignores a status sent to the update route', function () {
    $project = seededProject('abc.com — Monthly Maintenance');

    $this->actingAs($this->admin)
        ->put(route('admin.projects.update', $project), [
            'client_id' => $project->client_id,
            'name' => 'abc.com — Renamed, Not Cancelled',
            'project_type' => ProjectType::WebsiteMaintenance->value,
            'billing_type' => BillingType::MonthlyRecurring->value,
            'priority' => Priority::Low->value,
            'status' => ProjectStatus::Cancelled->value,
            'pm_id' => $project->pm_id,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success');

    $project->refresh();

    // The other fields land; the status is simply not an input of this endpoint.
    expect($project->name)->toBe('abc.com — Renamed, Not Cancelled')
        ->and($project->priority)->toBe(Priority::Low)
        ->and($project->status)->toBe(ProjectStatus::Active);
})->group('phase1');

it('will not let an assigned manager move a status through the update route', function () {
    $manager = Employee::factory()->forRole(RoleName::MANAGER)->create();
    $project = seededProject('Heat Gap — SEO Retainer');
    $project->forceFill(['pm_id' => $manager->id])->save();

    // MANAGER lives on the Employee surface, so the Admin route is closed to it outright.
    $this->actingAs($manager->user->fresh())
        ->put(route('admin.projects.update', $project), [
            'name' => $project->name,
            'project_type' => $project->project_type->value,
            'billing_type' => $project->billing_type->value,
            'priority' => $project->priority->value,
            'status' => ProjectStatus::Cancelled->value,
        ])
        ->assertForbidden();

    expect($project->fresh()->status)->toBe(ProjectStatus::Active);

    // And the service itself drops the key, so no other caller can get it through either.
    app(ProjectService::class)->update($manager->user->fresh(), $project, [
        'name' => 'Manager Renamed It',
        'status' => ProjectStatus::Cancelled,
    ]);

    expect($project->fresh()->name)->toBe('Manager Renamed It')
        ->and($project->fresh()->status)->toBe(ProjectStatus::Active);
})->group('phase1');

it('leaves the status alone and logs no transition when update is handed one', function () {
    $project = seededProject('Buffalo Modular — SEO');

    $timeline = fn (): array => ActivityLog::where('object_type', $project->getMorphClass())
        ->where('object_id', $project->id)
        ->pluck('description')
        ->all();

    $before = $timeline();
    $auditBefore = AuditLog::where('target_id', $project->id)->count();

    app(ProjectService::class)->update($this->admin, $project, [
        'status' => ProjectStatus::Cancelled,
    ]);

    expect($project->fresh()->status)->toBe(ProjectStatus::Active)
        // Nothing changed, so no activity row and no audit row implying a transition.
        ->and($timeline())->toBe($before)
        ->and(implode(' ', $timeline()))->not->toContain('Status changed')
        ->and(AuditLog::where('target_id', $project->id)->count())->toBe($auditBefore);
})->group('phase1');

it('walks a project through every allowed transition', function (string $from, string $to) {
    $project = Project::factory()->create(['status' => ProjectStatus::from($from)]);

    $payload = ['status' => $to];

    if ($to === ProjectStatus::Cancelled->value) {
        $payload['reason'] = 'Client pulled the budget.';
    }

    $this->actingAs($this->admin)
        ->post(route('admin.projects.status', $project), $payload)
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($project->fresh()->status)->toBe(ProjectStatus::from($to));
})->with([
    ['active', 'on_hold'],
    ['active', 'completed'],
    ['active', 'cancelled'],
    ['on_hold', 'active'],
    ['on_hold', 'completed'],
    ['on_hold', 'cancelled'],
    ['completed', 'active'],
    ['completed', 'cancelled'],
    ['cancelled', 'active'],
])->group('phase1');

it('refuses an illegal transition with a flash error and no change', function () {
    $project = Project::factory()->create(['status' => ProjectStatus::Completed]);

    $this->actingAs($this->admin)
        ->post(route('admin.projects.status', $project), ['status' => ProjectStatus::OnHold->value])
        ->assertRedirect()
        ->assertSessionHas('error')
        ->assertSessionMissing('success');

    expect($project->fresh()->status)->toBe(ProjectStatus::Completed);
})->group('phase1');

it('refuses to archive through the status route', function () {
    $project = seededProject('GoodTechies HQ — Internal');

    $this->actingAs($this->admin)
        ->post(route('admin.projects.status', $project), ['status' => ProjectStatus::Archived->value])
        ->assertSessionHasErrors('status');

    expect($project->fresh()->isArchived())->toBeFalse();
})->group('phase1');

it('will not cancel a project without a reason', function () {
    $project = seededProject('Heat Gap — SEO Retainer');

    $this->actingAs($this->admin)
        ->post(route('admin.projects.status', $project), ['status' => ProjectStatus::Cancelled->value])
        ->assertSessionHasErrors('reason');

    expect($project->fresh()->status)->toBe(ProjectStatus::Active);
})->group('phase1');

it('archives and unarchives a project', function () {
    $project = seededProject('Heat Gap — SEO Retainer');

    $this->actingAs($this->admin)
        ->post(route('admin.projects.archive', $project))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($project->fresh()->isArchived())->toBeTrue()
        ->and($project->fresh()->status)->toBe(ProjectStatus::Archived);

    $this->actingAs($this->admin)
        ->post(route('admin.projects.unarchive', $project))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($project->fresh()->isArchived())->toBeFalse()
        ->and($project->fresh()->status)->toBe(ProjectStatus::Active);
})->group('phase1');

it('adds and removes project members in one write', function () {
    $project = seededProject('Buffalo Modular — SEO');

    expect($project->members()->pluck('employees.id')->all())->toBe([$this->tapu->id]);

    $this->actingAs($this->admin)
        ->put(route('admin.projects.members.update', $project), [
            'members' => [$this->yaseen->id],
            'roles' => [$this->yaseen->id => 'maintenance'],
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $members = $project->fresh()->members;

    expect($members->pluck('id')->all())->toBe([$this->yaseen->id])
        ->and($members->first()->pivot->role_on_project)->toBe('maintenance');
})->group('phase1');

it('clears the member list when an empty one is sent', function () {
    $project = seededProject('Buffalo Modular — SEO');

    $this->actingAs($this->admin)
        ->put(route('admin.projects.members.update', $project), ['members' => []])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($project->fresh()->members()->count())->toBe(0);
})->group('phase1');

it('refuses every write to an archived project with a flash error', function () {
    $project = seededProject('APH — Website Maintenance');
    $this->actingAs($this->admin)->post(route('admin.projects.archive', $project))->assertRedirect();

    $before = $project->fresh()->name;

    $this->actingAs($this->admin)
        ->put(route('admin.projects.update', $project), [
            'name' => 'Should Not Land',
            'project_type' => ProjectType::WebsiteMaintenance->value,
            'billing_type' => BillingType::MonthlyRecurring->value,
            'priority' => Priority::Low->value,
            'status' => ProjectStatus::Active->value,
        ])
        ->assertRedirect()
        ->assertSessionHas('error')
        ->assertSessionMissing('success');

    $this->actingAs($this->admin)
        ->put(route('admin.projects.members.update', $project), ['members' => []])
        ->assertSessionHas('error');

    $this->actingAs($this->admin)
        ->post(route('admin.projects.status', $project), ['status' => ProjectStatus::Completed->value])
        ->assertSessionHas('error');

    expect($project->fresh()->name)->toBe($before)
        ->and($project->fresh()->members()->count())->toBe(1)
        ->and($project->fresh()->status)->toBe(ProjectStatus::Archived);
})->group('phase1');
