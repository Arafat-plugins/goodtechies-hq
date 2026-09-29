<?php

use App\Models\Employee;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskService;
use App\Support\RoleName;
use App\Support\TaskBucket;
use App\Support\TaskStatus;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Employee — My Tasks, and the dashboard's five cards
|--------------------------------------------------------------------------
|
| The screen an employee opens first thing. On this surface Task::visibleTo()
| has already narrowed to the tasks they are assigned to, so `mine` is a no-op
| for an Employee — and a real narrowing for the Manager who shares the
| surface, which is the case worth a test of its own: a Manager sees every task
| on /employee/tasks and only their own here.
|
| The dashboard's five cards are the same buckets by the same service, each one
| linking to the bucket it counted.
|
*/

beforeEach(function () {
    $this->seed();

    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();

    $this->project = Project::factory()->create();

    $this->mineLate = Task::factory()
        ->count(2)
        ->for($this->project)
        ->overdue()
        ->assignedTo($this->yaseen->employee)
        ->create();

    $this->theirsLate = Task::factory()
        ->for($this->project)
        ->overdue()
        ->assignedTo($this->tapu->employee)
        ->create();
});

it('sends the old My Tasks page to the Tasks List, scoped to this person', function () {
    // The page is the `?scope=` dropdown on the Tasks toolbar now; the URL keeps working.
    $this->actingAs($this->yaseen)->get('/employee/my-tasks')->assertRedirect('/employee/tasks?scope=mine&bucket=open');
    $this->actingAs($this->yaseen)->get('/employee/my-tasks?bucket=due_today')
        ->assertRedirect('/employee/tasks?scope=due-today');
})->group('phase2');

it('lands the overdue link on exactly this person\'s late tasks', function () {
    $this->actingAs($this->yaseen)->get('/employee/my-tasks?bucket=overdue')
        ->assertRedirect('/employee/tasks?scope=overdue');

    $list = $this->actingAs($this->yaseen)->get('/employee/tasks?scope=overdue')->assertOk()
        ->inertiaPage()['props']['tasks'];
    $tasks = collect($list['groups'])->flatMap(fn (array $group) => $group['tasks']);
    $ids = $tasks->pluck('id')->all();

    expect($ids)->toContain(...$this->mineLate->pluck('id')->all())
        // Tapu's late task is absent, not refused — the rule this whole surface is built on.
        ->and($ids)->not->toContain($this->theirsLate->id);

    // Every row is late by the server's own definition, computed at query time.
    foreach ($tasks as $task) {
        expect($task['is_overdue'])->toBeTrue();
    }

    // And the scope agrees with the Overdue-only checkbox. On this surface Task::visibleTo()
    // has already narrowed to this person, so the two are the same set — which is the check
    // that the bucket did not invent a second definition of late.
    $overdueOnly = $this->actingAs($this->yaseen)->get('/employee/tasks?overdue=1')->assertOk()
        ->inertiaPage()['props']['tasks'];

    expect($overdueOnly['total'])->toBe($list['total'])
        ->and(collect($overdueOnly['groups'])->flatMap(fn (array $group) => array_column($group['tasks'], 'id'))->all())
        ->toEqualCanonicalizing($ids);
})->group('phase2');

it('carries any other bucket through beside the personal scope', function () {
    $this->actingAs($this->yaseen)->get('/employee/my-tasks?bucket=in_review')
        ->assertRedirect('/employee/tasks?scope=mine&bucket=in_review');

    $total = $this->actingAs($this->yaseen)->get('/employee/tasks?scope=mine&bucket=in_review')->assertOk()
        ->inertiaPage()['props']['tasks']['total'];
    $counted = app(TaskService::class)->bucketCounts($this->yaseen, [TaskBucket::InReview], ['mine' => true]);

    // The dashboard card's number and the list its link opens are the same query.
    expect($total)->toBe($counted['in_review']);
})->group('phase2');

it('gives a manager their own plate, not the whole board they can otherwise see', function () {
    $manager = Employee::factory()->forRole(RoleName::MANAGER)->create();

    $mine = Task::factory()
        ->for($this->project)
        ->overdue()
        ->assignedTo($manager)
        ->create();

    $list = $this->actingAs($manager->user)->get('/employee/tasks?scope=overdue')->assertOk()
        ->inertiaPage()['props']['tasks'];
    $ids = collect($list['groups'])->flatMap(fn (array $group) => array_column($group['tasks'], 'id'))->all();

    expect($ids)->toBe([$mine->id])
        // A Manager's Task::visibleTo() is every task, so this is the `mine` filter working.
        ->and($ids)->not->toContain($this->theirsLate->id);

    $everything = $this->actingAs($manager->user)
        ->get('/employee/tasks?bucket=overdue')
        ->assertOk()
        ->inertiaPage()['props']['tasks']['total'];

    expect($everything)->toBeGreaterThan(1);
})->group('phase2');

/*
|--------------------------------------------------------------------------
| The dashboard
|--------------------------------------------------------------------------
*/

it('puts the plan\'s five cards on the employee dashboard, each with a real count', function () {
    $stats = $this->actingAs($this->yaseen)
        ->get('/employee/dashboard')
        ->assertOk()
        ->inertiaPage()['props']['taskStats'];

    expect(array_column($stats, 'key'))
        ->toBe(['open', 'due_today', 'overdue', 'in_progress', 'completed'])
        ->and($stats[0]['label'])->toBe('My tasks')
        ->and($stats[0]['href'])->toBe('/employee/my-tasks')
        ->and($stats[2]['href'])->toBe('/employee/my-tasks?bucket=overdue')
        ->and($stats[2]['count'])->toBeGreaterThanOrEqual(2);
})->group('phase2');

it('sends every dashboard card to exactly the tasks it counted', function () {
    $stats = $this->actingAs($this->yaseen)
        ->get('/employee/dashboard')
        ->inertiaPage()['props']['taskStats'];

    foreach ($stats as $stat) {
        // The card still links to `/employee/my-tasks…`, which 302s to the scoped Tasks List;
        // the list it lands on must hold exactly the number the card printed.
        $location = $this->actingAs($this->yaseen)->get($stat['href'])->assertRedirect()->headers->get('Location');

        $total = $this->actingAs($this->yaseen)
            ->get($location)
            ->assertOk()
            ->inertiaPage()['props']['tasks']['total'];

        expect($total)->toBe($stat['count'], "card {$stat['key']} leads somewhere else");
    }
})->group('phase2');

it('counts one person\'s work per person', function () {
    $done = Task::factory()
        ->for($this->project)
        ->status(TaskStatus::Completed)
        ->assignedTo($this->yaseen->employee)
        ->create(['completed_at' => Carbon::now()]);

    $yaseen = collect($this->actingAs($this->yaseen)->get('/employee/dashboard')->inertiaPage()['props']['taskStats'])
        ->firstWhere('key', 'completed')['count'];

    $tapu = collect($this->actingAs($this->tapu)->get('/employee/dashboard')->inertiaPage()['props']['taskStats'])
        ->firstWhere('key', 'completed')['count'];

    expect($yaseen)->toBeGreaterThanOrEqual(1)
        ->and(Task::query()->forEmployee($this->tapu->employee)->whereKey($done->id)->exists())->toBeFalse()
        ->and($yaseen)->not->toBe($tapu);
})->group('phase2');
