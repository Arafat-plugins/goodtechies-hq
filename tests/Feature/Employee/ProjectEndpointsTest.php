<?php

use App\Models\Project;
use App\Models\User;
use App\Support\ProjectStatus;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| Employee project endpoints
|--------------------------------------------------------------------------
|
| Read-only, scoped to the projects the employee is actually on. A project they
| are not on is missing, not forbidden: 404, never 403.
|
*/

beforeEach(function () {
    $this->seed();

    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
});

it('shows a remote employee exactly the projects he is on', function () {
    $this->actingAs($this->tapu)
        ->get(route('employee.projects.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Employee/Projects/Index')
            ->has('projects.data', 2)
            // Sorted by deadline with nulls last: Heat Gap has one, the Buffalo retainer does not.
            ->where('projects.data.0.name', 'Heat Gap — SEO Retainer')
            ->where('projects.data.1.name', 'Buffalo Modular — SEO')
            ->where('filters.search', null)
            ->where('filters.status', null),
        );
})->group('phase1');

it('filters the employee project list', function () {
    $rows = fn (User $user, array $query): array => $this->actingAs($user)
        ->get(route('employee.projects.index', $query))
        ->assertOk()
        ->inertiaPage()['props']['projects']['data'];

    expect($rows($this->tapu, ['search' => 'heat gap']))->toHaveCount(1)
        ->and($rows($this->yaseen, ['status' => ProjectStatus::OnHold->value]))->toHaveCount(1)
        ->and($rows($this->yaseen, []))->toHaveCount(4);
})->group('phase1');

it('opens a project the employee is on', function () {
    $project = Project::where('name', 'Buffalo Modular — SEO')->firstOrFail();

    $this->actingAs($this->tapu)
        ->get(route('employee.projects.show', $project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Employee/Projects/Show')
            ->where('project.data.id', $project->id)
            ->where('project.data.employee_notes', 'Target the Home Model pages first; client prefers UK English.'),
        );
})->group('phase1');

it('answers 404, not 403, for an existing project the employee is not on', function () {
    $notMine = Project::where('name', 'APH — Website Maintenance')->firstOrFail();

    $this->actingAs($this->tapu)
        ->get(route('employee.projects.show', $notMine))
        ->assertNotFound();

    // And the same for an id that does not exist at all, so the two are indistinguishable.
    $this->actingAs($this->tapu)
        ->get('/employee/projects/'.(Project::max('id') + 1))
        ->assertNotFound();
})->group('phase1');

it('keeps an archived project readable for the employee who worked on it', function () {
    $project = Project::where('name', 'Heat Gap — SEO Retainer')->firstOrFail();
    $project->forceFill(['status' => ProjectStatus::Archived, 'archived_at' => now()])->save();

    $this->actingAs($this->tapu)
        ->get(route('employee.projects.show', $project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('project.data.is_archived', true)
            ->where('project.data.permissions.can_update', false),
        );

    $this->actingAs($this->tapu)
        ->get(route('employee.projects.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('projects.data', 2));
})->group('phase1');

it('keeps an employee off the admin surface', function () {
    $project = Project::where('name', 'Buffalo Modular — SEO')->firstOrFail();

    $this->actingAs($this->tapu)->get(route('admin.projects.index'))->assertForbidden();
    $this->actingAs($this->tapu)->get(route('admin.projects.show', $project))->assertForbidden();
    $this->actingAs($this->tapu)->get(route('admin.clients.index'))->assertForbidden();
})->group('phase1');
