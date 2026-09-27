<?php

use App\Models\ActivityLog;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Project;
use App\Models\User;
use App\Support\AuditEvent;
use App\Support\Permission;
use App\Support\RoleName;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| Project-level permission grants (Phase 12)
|--------------------------------------------------------------------------
|
| Part C §1: "Project-level overrides (`user_project_permissions`) layer on top
| — e.g. a MANAGER may be granted `projects.view_finance` for one project."
|
| The table has existed since Phase 0 and `ProjectPolicy::viewFinance()` has
| READ it since Phase 1. **Nothing wrote it**, so that sentence described a rule
| the application enforced and nobody could exercise. These two endpoints are
| the writer, and the test that matters most is the last one in the first block:
| after the grant, the POLICY answers differently. A grant that stored a row
| without changing an answer would be a feature that looked finished.
|
| Also asserted: the self-grant is refused (the privilege escalation Part C §1's
| "(not own account)" exists to stop), a key no policy reads is refused at
| validation rather than written and ignored, and a grant belonging to somebody
| else is 404 rather than a refusal that would confirm it exists.
|
| Prefixed WORKFORCE_GRANT_ / workforceGrant*, because Pest declares constants
| and functions globally across the suite (AGENTS.md).
|
*/

beforeEach(function (): void {
    $this->seed();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();

    $this->manager = Employee::factory()->forRole(RoleName::MANAGER)->create();
    $this->managerUser = $this->manager->user;

    $this->project = Project::where('name', 'Buffalo Modular — SEO')->firstOrFail();
});

function workforceGrant(User $actor, Employee $employee, int $projectId, string $permission)
{
    return test()->actingAs($actor)->post('/admin/employees/'.$employee->id.'/permissions', [
        'project_id' => $projectId,
        'permission' => $permission,
    ]);
}

/*
|--------------------------------------------------------------------------
| Granting
|--------------------------------------------------------------------------
*/

it('grants one permission on one project, audited, and the policy then answers yes', function (): void {
    // Before: a Manager holds no `projects.view_finance` at all (Part C §1 gives it to ADMIN).
    expect(Gate::forUser($this->managerUser)->allows('viewFinance', $this->project))->toBeFalse();

    workforceGrant($this->admin, $this->manager, $this->project->id, Permission::ProjectsViewFinance->value)
        ->assertRedirect()
        ->assertSessionHas('success', fn (string $flash): bool => str_contains($flash, 'projects.view_finance')
            && str_contains($flash, 'Buffalo Modular — SEO'));

    expect(DB::table('user_project_permissions')->where('user_id', $this->managerUser->id)->count())->toBe(1);

    // After: the same question, the same project, a different answer. This is the assertion the
    // whole feature is for.
    expect(Gate::forUser(User::find($this->managerUser->id))->allows('viewFinance', $this->project))->toBeTrue()
        // …and only on that project. A grant is per project or it is not a project-level grant.
        ->and(Gate::forUser(User::find($this->managerUser->id))
            ->allows('viewFinance', Project::where('name', '!=', 'Buffalo Modular — SEO')->firstOrFail()))
        ->toBeFalse();

    $audit = AuditLog::where('event', AuditEvent::PermissionChanged->value)->sole();

    expect($audit->actor_id)->toBe($this->admin->id)
        ->and($audit->target_type)->toBe($this->manager->getMorphClass())
        ->and($audit->target_id)->toBe($this->manager->id)
        // `toEqualCanonicalizing`, because `audit_logs.old_value` is jsonb and PostgreSQL does not
        // promise to hand the keys back in the order they went in.
        ->and($audit->old_value)->toEqualCanonicalizing([
            'user_id' => $this->managerUser->id,
            'project_id' => $this->project->id,
            'project' => 'Buffalo Modular — SEO',
            'permission' => 'projects.view_finance',
            'granted' => false,
        ])
        ->and($audit->new_value['granted'])->toBeTrue();

    expect(ActivityLog::where('object_type', $this->manager->getMorphClass())
        ->where('object_id', $this->manager->id)
        ->value('description'))->toBe('Granted projects.view_finance on Buffalo Modular — SEO');
});

it('is idempotent: granting what somebody already holds writes no second row and no second log', function (): void {
    workforceGrant($this->admin, $this->manager, $this->project->id, Permission::ProjectsViewFinance->value);
    workforceGrant($this->admin, $this->manager, $this->project->id, Permission::ProjectsViewFinance->value)
        ->assertRedirect();

    expect(DB::table('user_project_permissions')->count())->toBe(1)
        ->and(AuditLog::where('event', AuditEvent::PermissionChanged->value)->count())->toBe(1);
});

it('shows the grant on the employee detail, with the project and the key', function (): void {
    workforceGrant($this->admin, $this->manager, $this->project->id, Permission::ProjectsViewFinance->value);

    $this->actingAs($this->admin)
        ->get('/admin/employees/'.$this->manager->id)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('employee.project_permissions_count', 1)
            ->has('employee.project_permissions', 1)
            ->where('employee.project_permissions.0.project.id', $this->project->id)
            ->where('employee.project_permissions.0.project.name', 'Buffalo Modular — SEO')
            ->where('employee.project_permissions.0.permission.key', 'projects.view_finance')
            ->where('employee.project_permissions.0.permission.label', 'Projects View Finance')
            ->where('employee.project_permissions.0.can_revoke', true)
            ->whereNot('employee.project_permissions.0.granted_at', null));
});

it('refuses a permission key no policy reads, rather than storing a row that grants nothing', function (): void {
    foreach ([Permission::FinanceView->value, Permission::RolesManage->value, 'projects.view'] as $key) {
        workforceGrant($this->admin, $this->manager, $this->project->id, $key)
            ->assertSessionHasErrors('permission');
    }

    expect(DB::table('user_project_permissions')->count())->toBe(0)
        ->and(AuditLog::count())->toBe(0);
});

it('404s a project the requester may not grant on, including an archived one', function (): void {
    $archived = Project::query()->whereNotNull('archived_at')->first()
        ?? tap(Project::where('name', '!=', 'Buffalo Modular — SEO')->firstOrFail(),
            fn (Project $project) => $project->forceFill(['archived_at' => now()])->save());

    workforceGrant($this->admin, $this->manager, $archived->id, Permission::ProjectsViewFinance->value)
        ->assertNotFound();

    workforceGrant($this->admin, $this->manager, 999999, Permission::ProjectsViewFinance->value)
        ->assertSessionHasErrors('project_id');

    expect(DB::table('user_project_permissions')->count())->toBe(0);
});

it('404s an employee outside the requester scope and an id that is not there', function (): void {
    test()->actingAs($this->admin)->post('/admin/employees/999999/permissions', [
        'project_id' => $this->project->id,
        'permission' => Permission::ProjectsViewFinance->value,
    ])->assertNotFound();
});

it('refuses a self grant — the escalation Part C §1 exists to stop', function (): void {
    workforceGrant($this->admin, $this->admin->employee, $this->project->id, Permission::ProjectsViewFinance->value)
        ->assertForbidden();

    expect(DB::table('user_project_permissions')->count())->toBe(0)
        ->and(AuditLog::count())->toBe(0);
});

it('refuses both endpoints to a role without roles.manage', function (): void {
    // A real grant to aim the DELETE at. `SubstituteBindings` runs in the `web` group, BEFORE the
    // route's `can:roles.manage`, so an id that is not in the table is a 404 from the router and
    // would hide the refusal this test is about.
    workforceGrant($this->admin, $this->manager, $this->project->id, Permission::ProjectsViewFinance->value)
        ->assertRedirect();

    $grantId = (int) DB::table('user_project_permissions')->where('user_id', $this->managerUser->id)->value('id');

    foreach ([$this->yaseen, $this->accountant, $this->managerUser] as $user) {
        workforceGrant($user, $this->manager, $this->project->id, Permission::ProjectsViewFinance->value)
            ->assertForbidden();

        test()->actingAs($user)
            ->delete('/admin/employees/'.$this->manager->id.'/permissions/'.$grantId)
            ->assertForbidden();
    }

    // The grant the Admin made is untouched: nobody without the key added or removed anything.
    expect(DB::table('user_project_permissions')->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Revoking
|--------------------------------------------------------------------------
*/

it('revokes the grant, audited, and the policy answers no again', function (): void {
    workforceGrant($this->admin, $this->manager, $this->project->id, Permission::ProjectsViewFinance->value);

    $grantId = (int) DB::table('user_project_permissions')->where('user_id', $this->managerUser->id)->value('id');

    $this->actingAs($this->admin)
        ->delete('/admin/employees/'.$this->manager->id.'/permissions/'.$grantId)
        ->assertRedirect()
        ->assertSessionHas('success', fn (string $flash): bool => str_contains($flash, 'no longer has'));

    expect(DB::table('user_project_permissions')->count())->toBe(0)
        ->and(Gate::forUser(User::find($this->managerUser->id))->allows('viewFinance', $this->project))->toBeFalse();

    $audit = AuditLog::where('event', AuditEvent::PermissionChanged->value)
        ->orderByDesc('id')
        ->first();

    expect($audit->old_value['granted'])->toBeTrue()
        ->and($audit->new_value['granted'])->toBeFalse()
        // The row is gone, so the audit entry is the only record of what it was.
        ->and($audit->new_value['permission'])->toBe('projects.view_finance')
        ->and($audit->new_value['project'])->toBe('Buffalo Modular — SEO');
});

it('404s a grant that belongs to somebody else', function (): void {
    workforceGrant($this->admin, $this->manager, $this->project->id, Permission::ProjectsViewFinance->value);

    $grantId = (int) DB::table('user_project_permissions')->where('user_id', $this->managerUser->id)->value('id');

    // The same grant id, asked for under a different employee. Saying "that is not yours" would
    // confirm it exists, so it is the same 404 as a grant that was never there.
    $this->actingAs($this->admin)
        ->delete('/admin/employees/'.$this->yaseen->employee->id.'/permissions/'.$grantId)
        ->assertNotFound();

    $this->actingAs($this->admin)
        ->delete('/admin/employees/'.$this->manager->id.'/permissions/999999')
        ->assertNotFound();

    expect(DB::table('user_project_permissions')->count())->toBe(1);
});

it('refuses a self revoke, for the same reason it refuses a self grant', function (): void {
    // Granted to the Admin by another Admin, so there is a row for them to try to take.
    $faruk = User::where('email', 'faruk@goodtechies.test')->firstOrFail();

    workforceGrant($faruk, $this->admin->employee, $this->project->id, Permission::ProjectsViewFinance->value)
        ->assertRedirect();

    $grantId = (int) DB::table('user_project_permissions')->where('user_id', $this->admin->id)->value('id');

    $this->actingAs($this->admin)
        ->delete('/admin/employees/'.$this->admin->employee->id.'/permissions/'.$grantId)
        ->assertForbidden();

    expect(DB::table('user_project_permissions')->count())->toBe(1);
});
