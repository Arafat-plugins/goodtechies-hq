<?php

use App\Models\Client;
use App\Models\Employee;
use App\Models\File;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\RoleName;
use App\Support\UserStatus;
use Illuminate\Support\Facades\Gate;

/*
|--------------------------------------------------------------------------
| FilePolicy — deny by default
|--------------------------------------------------------------------------
|
| Two rules, and the whole file is them:
|
|   view   follows the record the file hangs off, and nothing else. An
|          employee sees the files of the tasks they are ASSIGNED to, the
|          same set of tasks they can see at all.
|   delete is the uploader or an Admin — among the people who can see it.
|
| Replacing is deliberately a third rule: it is a change to the owning record,
| so it takes the ability editing that record takes. Uploading a better
| version of somebody else's file is ordinary shared work; removing theirs is
| not.
|
*/

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();

    $this->tapusTask = Task::query()->forEmployee($this->tapu->employee)->notArchived()->firstOrFail();
    $this->onTapusTask = File::factory()->forTask($this->tapusTask)->uploadedBy($this->tapu)->create();
});

/*
|--------------------------------------------------------------------------
| Viewing follows the owner
|--------------------------------------------------------------------------
*/

it('lets the people who can see the task see its files', function () {
    expect(Gate::forUser($this->admin)->allows('view', $this->onTapusTask))->toBeTrue()
        ->and(Gate::forUser($this->tapu)->allows('view', $this->onTapusTask))->toBeTrue();
})->group('phase2');

it('hides a file on a task the employee is not assigned to', function () {
    // Yaseen is not on Tapu's task. The task is invisible to him, so its files are too — this
    // is the rule restated in exactly one place, which is why it cannot drift.
    expect(Gate::forUser($this->yaseen)->allows('view', $this->onTapusTask))->toBeFalse()
        ->and(Gate::forUser($this->yaseen)->allows('view', $this->tapusTask))->toBeFalse();
})->group('phase2');

it('shows the accountant nothing', function () {
    $project = Project::factory()->create();
    $client = Client::factory()->create();

    expect(Gate::forUser($this->accountant)->allows('view', $this->onTapusTask))->toBeFalse()
        ->and(Gate::forUser($this->accountant)->allows('view', File::factory()->forProject($project)->create()))->toBeFalse()
        ->and(Gate::forUser($this->accountant)->allows('view', File::factory()->forClient($client)->create()))->toBeFalse();
})->group('phase2');

it('follows the project and the client policies for their own files', function () {
    $project = Project::factory()->create();
    $client = Client::factory()->create();

    $onProject = File::factory()->forProject($project)->create();
    $onClient = File::factory()->forClient($client)->create();

    expect(Gate::forUser($this->admin)->allows('view', $onProject))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('view', $onClient))->toBeTrue()
        // Tapu is on neither, so both are invisible — the project through ProjectPolicy, the
        // client through ClientPolicy, neither of them restated here.
        ->and(Gate::forUser($this->tapu)->allows('view', $onProject))->toBeFalse()
        ->and(Gate::forUser($this->tapu)->allows('view', $onClient))->toBeFalse();
})->group('phase2');

it('shows a deactivated user nothing at all', function () {
    $this->tapu->forceFill(['status' => UserStatus::Inactive->value])->save();

    expect(Gate::forUser($this->tapu->fresh())->allows('view', $this->onTapusTask))->toBeFalse();
})->group('phase2');

it('makes a file whose owner has gone invisible', function () {
    // Unsaved, because `files_one_owner` refuses a persisted row with no owner — which is the
    // point. This is the defensive branch: if a file ever did lose its owner it would be
    // visible to nobody rather than outliving its access rule.
    $orphan = File::factory()->forTask($this->tapusTask)->make(['task_id' => null]);

    expect(Gate::forUser($this->admin)->allows('view', $orphan))->toBeFalse()
        ->and(Gate::forUser($this->admin)->allows('delete', $orphan))->toBeFalse();
})->group('phase2');

/*
|--------------------------------------------------------------------------
| Deleting is the uploader or an Admin
|--------------------------------------------------------------------------
*/

it('lets the uploader delete their own file', function () {
    expect(Gate::forUser($this->tapu)->allows('delete', $this->onTapusTask))->toBeTrue();
})->group('phase2');

it('lets an admin delete anybody else file', function () {
    expect(Gate::forUser($this->admin)->allows('delete', $this->onTapusTask))->toBeTrue();
})->group('phase2');

it('refuses the delete to everyone else who can see the file', function () {
    // A Manager sees every task and may reassign this one — and still may not remove somebody
    // else's upload from it. "May edit the record" is not the delete rule.
    $manager = Employee::factory()->forRole(RoleName::MANAGER)->create()->user;
    $adminsFile = File::factory()->forTask($this->tapusTask)->uploadedBy($this->admin)->create();

    expect(Gate::forUser($manager)->allows('view', $adminsFile))->toBeTrue()
        ->and(Gate::forUser($manager)->allows('delete', $adminsFile))->toBeFalse()
        // And the other assignee of a shared task is in the same position.
        ->and(Gate::forUser($this->tapu)->allows('delete', $adminsFile))->toBeFalse();
})->group('phase2');

it('does not let the uploader clause reach past the visibility rule', function () {
    // Yaseen uploaded it, then came off the task. He is still `uploaded_by`, and the file is
    // now on a task he cannot see — so he cannot delete it either. Uploading widens who may
    // delete a VISIBLE file; it never makes an invisible one reachable.
    $his = File::factory()->forTask($this->tapusTask)->uploadedBy($this->yaseen)->create();

    expect(Gate::forUser($this->yaseen)->allows('view', $his))->toBeFalse()
        ->and(Gate::forUser($this->yaseen)->allows('delete', $his))->toBeFalse();
})->group('phase2');

it('refuses the delete on a file with no uploader left', function () {
    $orphaned = File::factory()->forTask($this->tapusTask)->create(['uploaded_by' => null]);

    expect(Gate::forUser($this->tapu)->allows('delete', $orphaned))->toBeFalse()
        // An Admin is still the escape hatch.
        ->and(Gate::forUser($this->admin)->allows('delete', $orphaned))->toBeTrue();
})->group('phase2');

/*
|--------------------------------------------------------------------------
| Replacing takes the owner's update ability
|--------------------------------------------------------------------------
*/

it('lets anyone who may edit the task upload a new version', function () {
    $adminsFile = File::factory()->forTask($this->tapusTask)->uploadedBy($this->admin)->create();

    expect(Gate::forUser($this->tapu)->allows('replace', $adminsFile))->toBeTrue()
        ->and(Gate::forUser($this->tapu)->allows('delete', $adminsFile))->toBeFalse();
})->group('phase2');

it('refuses a new version on an archived task', function () {
    $archived = Task::factory()->archived()->assignedTo($this->tapu->employee)->create();
    $file = File::factory()->forTask($archived)->uploadedBy($this->tapu)->create();

    // Archived is read-only for everyone, so the file is still visible and still downloadable
    // and takes no new version.
    expect(Gate::forUser($this->tapu)->allows('view', $file))->toBeTrue()
        ->and(Gate::forUser($this->tapu)->allows('replace', $file))->toBeFalse()
        ->and(Gate::forUser($this->admin)->allows('replace', $file))->toBeFalse();
})->group('phase2');
