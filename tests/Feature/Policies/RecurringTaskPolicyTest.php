<?php

use App\Models\Employee;
use App\Models\Permission;
use App\Models\Project;
use App\Models\RecurringTask;
use App\Models\User;
use App\Support\Permission as PermissionName;
use App\Support\RoleName;
use Illuminate\Support\Facades\Gate;

/*
|--------------------------------------------------------------------------
| RecurringTaskPolicy — who owns a retainer
|--------------------------------------------------------------------------
|
| Deny by default, an Admin holding tasks.create, scoped through the project
| the template belongs to. The endpoints ask this and nothing else; the
| screens render its answers and never derive one from a role.
|
| The Manager row is the interesting one. A Manager may create, edit, archive
| and delete the tasks this template will produce — and still may not set the
| template up, because a standing instruction that bills a client every month
| is a decision about the engagement rather than about this week's work.
|
*/

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();
    $this->manager = Employee::factory()->forRole(RoleName::MANAGER)->create()->user;

    $this->project = Project::where('name', 'Buffalo Modular — SEO')->firstOrFail();
    $this->template = RecurringTask::where('project_id', $this->project->id)->firstOrFail();
});

it('lets an Admin manage a project’s templates', function () {
    $gate = Gate::forUser($this->admin);

    expect($gate->allows('viewAny', [RecurringTask::class, $this->project]))->toBeTrue()
        ->and($gate->allows('create', [RecurringTask::class, $this->project]))->toBeTrue()
        ->and($gate->allows('view', $this->template))->toBeTrue()
        ->and($gate->allows('update', $this->template))->toBeTrue()
        ->and($gate->allows('generate', $this->template))->toBeTrue();
})->group('phase3');

it('refuses every other role, including a Manager who owns the tasks it makes', function (string $user) {
    $gate = Gate::forUser($this->{$user});

    expect($gate->allows('viewAny', [RecurringTask::class, $this->project]))->toBeFalse()
        ->and($gate->allows('create', [RecurringTask::class, $this->project]))->toBeFalse()
        ->and($gate->allows('view', $this->template))->toBeFalse()
        ->and($gate->allows('update', $this->template))->toBeFalse()
        ->and($gate->allows('generate', $this->template))->toBeFalse();
})->with(['manager', 'tapu', 'yaseen', 'accountant'])->group('phase3');

it('denies by default when no project is named', function () {
    // There is no such thing as a global retainer template: a template with no project is a
    // relation that failed to load, and a policy that read that as "everybody's" would be the
    // one place deny-by-default leaked.
    expect(Gate::forUser($this->admin)->allows('viewAny', RecurringTask::class))->toBeFalse()
        ->and(Gate::forUser($this->admin)->allows('create', RecurringTask::class))->toBeFalse();
})->group('phase3');

it('falls at the project scope when the Admin cannot see the project', function () {
    $this->admin->employee->role->permissions()->detach(
        Permission::where('key', PermissionName::ProjectsView->value)->firstOrFail()
    );

    // The key is still held — `tasks.create` is untouched — so what refuses here is the scope
    // check and nothing else, which is the half AGENTS.md says belongs in a policy rather than
    // in a new permission key.
    expect(Gate::forUser($this->admin->fresh())->allows('view', $this->template))->toBeFalse();
})->group('phase3');

it('falls at the permission when tasks.create is taken away', function () {
    $this->admin->employee->role->permissions()->detach(
        Permission::where('key', PermissionName::TasksCreate->value)->firstOrFail()
    );

    expect(Gate::forUser($this->admin->fresh())->allows('create', [RecurringTask::class, $this->project]))->toBeFalse();
})->group('phase3');

it('refuses a deactivated Admin', function () {
    $this->admin->employee->forceFill(['status' => 'inactive'])->save();
    $this->admin->forceFill(['status' => 'inactive'])->save();

    expect(Gate::forUser($this->admin->fresh())->allows('update', $this->template))->toBeFalse();
})->group('phase3');

it('scopes the list the same way the project list is scoped', function () {
    expect(RecurringTask::query()->visibleTo($this->admin)->count())
        ->toBe(RecurringTask::count());

    // An employee sees the projects they are on, so they would see those projects' templates
    // if the scope were the only guard. It is not — RecurringTaskPolicy refuses them above —
    // but the scope is what makes the refusal an ABSENCE for anybody outside the project
    // rather than a 403 that confirms the row exists.
    $yaseenVisible = RecurringTask::query()->visibleTo($this->yaseen)->pluck('project_id')->unique();

    expect($yaseenVisible->diff(Project::query()->visibleTo($this->yaseen)->pluck('id')))->toBeEmpty();
})->group('phase3');
