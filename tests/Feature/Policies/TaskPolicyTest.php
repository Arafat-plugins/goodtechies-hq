<?php

use App\Models\Employee;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\RoleName;
use App\Support\TaskStatus;
use App\Support\UserStatus;
use Illuminate\Support\Facades\Gate;

/*
|--------------------------------------------------------------------------
| TaskPolicy — deny by default
|--------------------------------------------------------------------------
|
| Employee = assigned only. Accountant = nothing. Everything else is stated
| once here so the controllers and the serializer can stop re-deciding it.
|
*/

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();

    $this->tapusTask = Task::query()->forEmployee($this->tapu->employee)->firstOrFail();
    $this->yaseensTask = Task::query()->forEmployee($this->yaseen->employee)->firstOrFail();
});

it('lets an employee view only the tasks assigned to them', function () {
    expect(Gate::forUser($this->tapu)->allows('view', $this->tapusTask))->toBeTrue()
        ->and(Gate::forUser($this->tapu)->allows('view', $this->yaseensTask))->toBeFalse()
        ->and(Gate::forUser($this->yaseen)->allows('view', $this->yaseensTask))->toBeTrue()
        ->and(Gate::forUser($this->yaseen)->allows('view', $this->tapusTask))->toBeFalse();
})->group('phase2');

it('does not let project membership stand in for assignment', function () {
    $project = Project::where('name', 'Buffalo Modular — Website Maintenance')->firstOrFail();
    $faruk = Employee::where('employee_number', 'GT-002')->firstOrFail();

    $notHis = Task::factory()->for($project)->assignedTo($faruk)->create(['title' => 'Not Yaseen\'s work']);

    expect($project->members()->where('employees.id', $this->yaseen->employee->id)->exists())->toBeTrue()
        ->and(Gate::forUser($this->yaseen)->allows('view', $notHis))->toBeFalse();
})->group('phase2');

it('lets an admin view every task', function () {
    foreach (Task::all() as $task) {
        expect(Gate::forUser($this->admin)->allows('view', $task))->toBeTrue();
    }
})->group('phase2');

it('refuses the accountant every ability on every task', function (string $ability) {
    foreach ([$this->tapusTask, $this->yaseensTask] as $task) {
        expect(Gate::forUser($this->accountant)->allows($ability, $task))->toBeFalse();
    }
})->with([['view'], ['update'], ['delete'], ['archive'], ['unarchive'], ['review']])->group('phase2');

it('refuses the accountant viewAny and create', function () {
    expect(Gate::forUser($this->accountant)->allows('viewAny', Task::class))->toBeFalse()
        ->and(Gate::forUser($this->accountant)->allows('create', Task::class))->toBeFalse();
})->group('phase2');

it('lets both assignees of a two-person task edit it', function () {
    $shared = Task::query()->has('assignees', '>=', 2)->firstOrFail();

    foreach ($shared->assignees as $assignee) {
        expect(Gate::forUser($assignee->user)->allows('update', $shared))
            ->toBeTrue("{$assignee->user->name} is assigned and should be able to edit");
    }
})->group('phase2');

it('makes an archived task read-only for everyone, admin included', function () {
    $this->tapusTask->forceFill(['archived_at' => now()])->save();

    expect(Gate::forUser($this->tapu)->allows('update', $this->tapusTask))->toBeFalse()
        ->and(Gate::forUser($this->admin)->allows('update', $this->tapusTask))->toBeFalse()
        // ...but still readable: it is history somebody worked on.
        ->and(Gate::forUser($this->admin)->allows('view', $this->tapusTask))->toBeTrue();
})->group('phase2');

it('gives delete to admins and managers only', function () {
    $manager = Employee::factory()->forRole(RoleName::MANAGER)->create()->user;

    expect(Gate::forUser($this->admin)->allows('delete', $this->tapusTask))->toBeTrue()
        ->and(Gate::forUser($manager)->allows('delete', $this->tapusTask))->toBeTrue()
        // The assignee cannot delete their own task.
        ->and(Gate::forUser($this->tapu)->allows('delete', $this->tapusTask))->toBeFalse()
        ->and(Gate::forUser($this->accountant)->allows('delete', $this->tapusTask))->toBeFalse();
})->group('phase2');

it('gives unarchive to an admin only', function () {
    $manager = Employee::factory()->forRole(RoleName::MANAGER)->create()->user;

    expect(Gate::forUser($this->admin)->allows('unarchive', $this->tapusTask))->toBeTrue()
        ->and(Gate::forUser($manager)->allows('unarchive', $this->tapusTask))->toBeFalse()
        ->and(Gate::forUser($this->tapu)->allows('unarchive', $this->tapusTask))->toBeFalse();
})->group('phase2');

it('locks everything out for an inactive user', function (string $ability) {
    $this->tapu->forceFill(['status' => UserStatus::Inactive])->save();

    expect(Gate::forUser($this->tapu->fresh())->allows($ability, $this->tapusTask))->toBeFalse();
})->with([['view'], ['update'], ['review']])->group('phase2');

/*
|--------------------------------------------------------------------------
| The reviewer rule: the project's PM, or every Admin when there is none
|--------------------------------------------------------------------------
*/

it('makes the project PM the reviewer', function () {
    // Buffalo Modular — SEO has Shahadat as PM, and Tapu assigned.
    $task = Task::query()
        ->whereHas('project', fn ($q) => $q->where('name', 'Buffalo Modular — SEO'))
        ->firstOrFail();

    expect(Gate::forUser($this->admin)->allows('review', $task))->toBeTrue()
        // The assignee is not their own reviewer.
        ->and(Gate::forUser($this->tapu)->allows('review', $task))->toBeFalse();
})->group('phase2');

it('does not make a manager from another project the reviewer', function () {
    $task = Task::query()
        ->whereHas('project', fn ($q) => $q->where('name', 'Buffalo Modular — SEO'))
        ->firstOrFail();

    // A Manager somewhere else is not this project's PM, which a role check alone could
    // never express — the whole reason review() exists next to mayRoleTransition().
    $outsider = Employee::factory()->forRole(RoleName::MANAGER)->create()->user;

    expect(Gate::forUser($outsider)->allows('review', $task))->toBeFalse();
})->group('phase2');

it('falls back to every admin when the project has no PM', function () {
    $task = Task::query()->firstOrFail();
    $task->project->forceFill(['pm_id' => null])->save();
    $task->load('project');

    $manager = Employee::factory()->forRole(RoleName::MANAGER)->create()->user;

    expect(Gate::forUser($this->admin)->allows('review', $task->fresh('project')))->toBeTrue()
        ->and(Gate::forUser($manager)->allows('review', $task->fresh('project')))->toBeFalse();
})->group('phase2');

/*
|--------------------------------------------------------------------------
| transition(): both halves must pass
|--------------------------------------------------------------------------
*/

it('needs the role half and the task half to agree', function () {
    $task = Task::query()
        ->whereHas('project', fn ($q) => $q->where('name', 'Buffalo Modular — SEO'))
        ->firstOrFail();

    Task::withoutStatusGuard(fn () => $task->forceFill(['status' => TaskStatus::InProgress])->save());
    $task = $task->fresh(['project', 'assignees']);

    // Tapu is assigned and the move is legal for their role: allowed.
    expect(Gate::forUser($this->tapu)->allows('transition', [$task, TaskStatus::InReview]))->toBeTrue()
        // Yaseen is not assigned, so the role half passing is not enough.
        ->and(Gate::forUser($this->yaseen)->allows('transition', [$task, TaskStatus::InReview]))->toBeFalse()
        // Tapu may not cancel, however assigned they are: the role half fails.
        ->and(Gate::forUser($this->tapu)->allows('transition', [$task, TaskStatus::Cancelled]))->toBeFalse()
        // Nobody may make an illegal move, admin included.
        ->and(Gate::forUser($this->admin)->allows('transition', [$task, TaskStatus::Completed]))->toBeFalse();
})->group('phase2');

it('lets only this project\'s reviewer pass a review', function () {
    $task = Task::query()
        ->whereHas('project', fn ($q) => $q->where('name', 'Buffalo Modular — SEO'))
        ->firstOrFail();

    Task::withoutStatusGuard(fn () => $task->forceFill(['status' => TaskStatus::InReview])->save());
    $task = $task->fresh(['project', 'assignees']);

    $outsider = Employee::factory()->forRole(RoleName::MANAGER)->create()->user;

    expect(Gate::forUser($this->admin)->allows('transition', [$task, TaskStatus::Completed]))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('transition', [$task, TaskStatus::ChangesRequested]))->toBeTrue()
        // A Manager whose role permits the verdict but who is not the PM here: refused.
        ->and(Gate::forUser($outsider)->allows('transition', [$task, TaskStatus::Completed]))->toBeFalse()
        // The assignee may withdraw their own submission, but not pass it.
        ->and(Gate::forUser($this->tapu)->allows('transition', [$task, TaskStatus::InProgress]))->toBeTrue()
        ->and(Gate::forUser($this->tapu)->allows('transition', [$task, TaskStatus::Completed]))->toBeFalse();
})->group('phase2');

it('refuses every transition on an archived task', function () {
    $task = Task::query()->forEmployee($this->tapu->employee)->firstOrFail();
    Task::withoutStatusGuard(fn () => $task->forceFill(['status' => TaskStatus::InProgress, 'archived_at' => now()])->save());
    $task = $task->fresh(['project', 'assignees']);

    expect(Gate::forUser($this->admin)->allows('transition', [$task, TaskStatus::InReview]))->toBeFalse()
        ->and(Gate::forUser($this->tapu)->allows('transition', [$task, TaskStatus::InReview]))->toBeFalse();
})->group('phase2');

it('refuses every transition to the accountant', function () {
    $task = Task::query()->firstOrFail();
    Task::withoutStatusGuard(fn () => $task->forceFill(['status' => TaskStatus::InProgress])->save());
    $task = $task->fresh(['project', 'assignees']);

    foreach (TaskStatus::cases() as $to) {
        expect(Gate::forUser($this->accountant)->allows('transition', [$task, $to]))->toBeFalse();
    }
})->group('phase2');
