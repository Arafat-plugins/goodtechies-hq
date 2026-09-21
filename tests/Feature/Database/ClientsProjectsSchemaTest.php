<?php

use App\Models\Client;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Project;
use App\Models\ProjectFinance;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\UserProjectPermission;
use App\Support\BillingType;
use App\Support\ClientStatus;
use App\Support\Permission as PermissionKey;
use App\Support\Priority;
use App\Support\ProjectStatus;
use App\Support\ProjectType;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('round-trips an encrypted contact list and stores ciphertext, not plaintext', function () {
    $client = Client::factory()->create([
        'contact_info' => [[
            'name' => 'Jane Doe',
            'role' => 'Owner',
            'email' => 'jane@example.test',
            'phone' => '+44 7700 900000',
        ]],
    ]);

    expect($client->refresh()->contact_info)->toBe([[
        'name' => 'Jane Doe',
        'role' => 'Owner',
        'email' => 'jane@example.test',
        'phone' => '+44 7700 900000',
    ]]);

    $raw = DB::table('clients')->where('id', $client->id)->value('contact_info');

    expect($raw)->not->toBeNull()
        ->and($raw)->not->toContain('jane@example.test')
        ->and($raw)->not->toContain('Jane Doe');
})->group('phase1');

it('casts client and project enums to enum instances', function () {
    $client = Client::factory()->create(['status' => ClientStatus::Inactive]);
    $project = Project::factory()->for($client)->create([
        'project_type' => ProjectType::Seo,
        'billing_type' => BillingType::MonthlyRecurring,
        'status' => ProjectStatus::OnHold,
        'priority' => Priority::High,
    ]);

    expect($client->status)->toBe(ClientStatus::Inactive)
        ->and($project->project_type)->toBe(ProjectType::Seo)
        ->and($project->billing_type)->toBe(BillingType::MonthlyRecurring)
        ->and($project->status)->toBe(ProjectStatus::OnHold)
        ->and($project->priority)->toBe(Priority::High);
})->group('phase1');

it('cascades a project delete to its finance row, its members and any project permission grants', function () {
    $project = Project::factory()->withFinance()->create();
    $employee = Employee::factory()->create();
    ProjectMember::create(['project_id' => $project->id, 'employee_id' => $employee->id]);

    $permission = Permission::firstOrCreate(['key' => PermissionKey::ProjectsView->value]);
    $user = User::factory()->create();
    UserProjectPermission::create([
        'user_id' => $user->id,
        'project_id' => $project->id,
        'permission_id' => $permission->id,
    ]);

    $projectId = $project->id;
    $project->delete();

    expect(ProjectFinance::where('project_id', $projectId)->exists())->toBeFalse()
        ->and(ProjectMember::where('project_id', $projectId)->exists())->toBeFalse()
        ->and(UserProjectPermission::where('project_id', $projectId)->exists())->toBeFalse();
})->group('phase1');

it('nulls a project client_id when the client is deleted', function () {
    $client = Client::factory()->create();
    $project = Project::factory()->for($client)->create();

    $client->delete();

    expect($project->refresh()->client_id)->toBeNull();
})->group('phase1');

it('enforces the unique project_id/employee_id pair on project members', function () {
    $project = Project::factory()->create();
    $employee = Employee::factory()->create();

    ProjectMember::create(['project_id' => $project->id, 'employee_id' => $employee->id]);

    expect(fn () => ProjectMember::create(['project_id' => $project->id, 'employee_id' => $employee->id]))
        ->toThrow(QueryException::class);
})->group('phase1');

it('scopes forEmployee to projects the employee is a member of or the PM for', function () {
    $employee = Employee::factory()->create();
    $memberProject = Project::factory()->create();
    ProjectMember::create(['project_id' => $memberProject->id, 'employee_id' => $employee->id]);

    $pmProject = Project::factory()->create(['pm_id' => $employee->id]);
    $unrelatedProject = Project::factory()->create();

    $ids = Project::forEmployee($employee)->pluck('id')->all();

    expect($ids)->toEqualCanonicalizing([$memberProject->id, $pmProject->id])
        ->and($ids)->not->toContain($unrelatedProject->id);
})->group('phase1');

it('refuses mass assignment of an unlisted attribute on ProjectFinance', function () {
    $project = Project::factory()->create();

    $finance = ProjectFinance::create([
        'project_id' => $project->id,
        'price' => 100,
        'not_a_real_column' => 'nope',
    ]);

    expect($finance->isFillable('not_a_real_column'))->toBeFalse()
        ->and($finance->not_a_real_column)->toBeNull();
})->group('phase1');
