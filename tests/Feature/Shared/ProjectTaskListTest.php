<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Client request 2026-10-06: a project page lists its tasks — the employee's own, or (for an
| Admin) all of them. The rule is Task::visibleTo(), so an employee never sees a colleague's task.
*/

beforeEach(function () {
    $this->seed();
    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
});

it('lists only the tasks assigned to the employee on their project page', function () {
    $employee = $this->tapu->employee;
    $project = Project::query()->visibleTo($this->tapu)->firstOrFail();

    $mine = Task::query()->where('project_id', $project->id)->whereNull('archived_at')->whereNull('parent_id')->forEmployee($employee)->pluck('id')->sort()->values()->all();
    $all = Task::query()->where('project_id', $project->id)->whereNull('archived_at')->whereNull('parent_id')->count();

    $this->actingAs($this->tapu)
        ->get("/employee/projects/{$project->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('projectTasks.total', count($mine))
            ->where('projectTasks.rows', fn ($rows) => collect($rows)->pluck('id')->sort()->values()->all() === $mine
                && collect($rows)->every(fn ($row) => str_starts_with($row['href'], '/employee/tasks/'))));

    expect($all)->toBeGreaterThanOrEqual(count($mine));
});

it('lists every task on the project for an Admin', function () {
    $project = Project::query()->whereHas('tasks')->firstOrFail();
    $all = Task::query()->where('project_id', $project->id)->whereNull('archived_at')->whereNull('parent_id')->count();

    $this->actingAs($this->admin)
        ->get("/admin/projects/{$project->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('projectTasks.total', $all)
            ->where('projectTasks.rows', fn ($rows) => count($rows) === min($all, 50)));
});
