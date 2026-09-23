<?php

use App\Models\Employee;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\RoleName;
use App\Support\TaskStatus;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

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

it('renders the seven buckets for an employee', function () {
    $this->actingAs($this->yaseen)
        ->get('/employee/my-tasks')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Employee/MyTasks', false)
            ->has('buckets', 7)
            ->where('buckets.0.label', 'My tasks')
            ->where('buckets.0.href', '/employee/my-tasks')
            ->where('buckets.1.key', 'due_today')
            ->where('buckets.1.href', '/employee/my-tasks?bucket=due_today')
            ->where('bucket', 'open')
            ->has('tasks'),
        );
})->group('phase2');

it('lists exactly the tasks its overdue card counted', function () {
    $props = $this->actingAs($this->yaseen)
        ->get('/employee/my-tasks?bucket=overdue')
        ->assertOk()
        ->inertiaPage()['props'];

    $ids = array_column($props['tasks'], 'id');
    $card = collect($props['buckets'])->firstWhere('key', 'overdue');

    expect($card['count'])->toBe(count($ids))
        ->and($ids)->toContain(...$this->mineLate->pluck('id')->all())
        // Tapu's late task is absent, not refused — the rule this whole surface is built on.
        ->and($ids)->not->toContain($this->theirsLate->id);

    // Every row is late by the server's own definition, computed at query time.
    foreach ($props['tasks'] as $task) {
        expect($task['is_overdue'])->toBeTrue();
    }

    // And the bucket agrees with the Tasks List under the same filter. On this surface
    // Task::visibleTo() has already narrowed to this person, so the two are the same set —
    // which is the check that the bucket did not invent a second definition of late.
    $list = $this->actingAs($this->yaseen)
        ->get('/employee/tasks?overdue=1')
        ->assertOk()
        ->inertiaPage()['props']['tasks'];

    $listIds = collect($list['groups'])->flatMap(fn (array $group) => array_column($group['tasks'], 'id'));

    expect($list['total'])->toBe($card['count'])
        ->and($listIds->all())->toEqualCanonicalizing($ids);
})->group('phase2');

it('answers zero rather than nothing when a bucket is empty', function () {
    $props = $this->actingAs($this->yaseen)
        ->get('/employee/my-tasks?bucket=in_review')
        ->assertOk()
        ->inertiaPage()['props'];

    $card = collect($props['buckets'])->firstWhere('key', 'in_review');

    // The card is present and reads 0; it is not withheld and not an empty state. Only the
    // LIST below is empty, which is where an EmptyState belongs.
    expect($card)->not->toBeNull()
        ->and($card['count'])->toBe(count($props['tasks']));
})->group('phase2');

it('gives a manager their own plate, not the whole board they can otherwise see', function () {
    $manager = Employee::factory()->forRole(RoleName::MANAGER)->create();

    $mine = Task::factory()
        ->for($this->project)
        ->overdue()
        ->assignedTo($manager)
        ->create();

    $props = $this->actingAs($manager->user)
        ->get('/employee/my-tasks?bucket=overdue')
        ->assertOk()
        ->inertiaPage()['props'];

    $ids = array_column($props['tasks'], 'id');

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
        $buckets = $this->actingAs($this->yaseen)
            ->get($stat['href'])
            ->assertOk()
            ->inertiaPage()['props']['buckets'];

        expect(collect($buckets)->firstWhere('key', $stat['key'])['count'])
            ->toBe($stat['count'], "card {$stat['key']} leads somewhere else");
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
