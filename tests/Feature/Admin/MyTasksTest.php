<?php

use App\Models\Employee;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\RoleName;
use App\Support\TaskStatus;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Admin — My Tasks, and the Company dashboard's task cards
|--------------------------------------------------------------------------
|
| Two screens with one rule between them. `/admin/my-tasks` is the Admin's OWN
| plate; `/admin/dashboard` counts the AGENCY's work. Both go through
| TaskService, both are scoped by Task::visibleTo(), and the difference is one
| `mine` filter — which for an Admin is a real narrowing, because visibleTo()
| has already handed them everything.
|
| The property every card assertion is really about: a number leads to the
| tasks it counted. A card saying "Overdue 4" whose link opens five rows is a
| card nobody can trust, so the counts and the destinations are checked
| against each other rather than each against a literal.
|
*/

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->adminEmployee = $this->admin->employee;
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();

    $this->project = Project::factory()->create();

    // Two late tasks of the Admin's own, and one of somebody else's. The seeded demo data has
    // late work too, which is the point of the third: "my plate" must not be the agency's.
    $this->mineLate = Task::factory()
        ->count(2)
        ->for($this->project)
        ->overdue()
        ->assignedTo($this->adminEmployee)
        ->create();

    $this->theirsLate = Task::factory()
        ->for($this->project)
        ->overdue()
        ->assignedTo($this->tapu->employee)
        ->create();
});

it('sends the old My Tasks page to the Tasks List, scoped to the admin', function () {
    // An Admin's own plate is `?scope=mine` on the agency's Tasks List now.
    $this->actingAs($this->admin)->get('/admin/my-tasks')->assertRedirect('/admin/tasks?scope=mine&bucket=open');
    $this->actingAs($this->admin)->get('/admin/my-tasks?bucket=overdue')->assertRedirect('/admin/tasks?scope=overdue');
})->group('phase2');

it('shows an admin their own plate and not the agency\'s', function () {
    $list = $this->actingAs($this->admin)->get('/admin/tasks?scope=overdue')->assertOk()
        ->inertiaPage()['props']['tasks'];
    $ids = collect($list['groups'])->flatMap(fn (array $group) => array_column($group['tasks'], 'id'))->all();

    expect($ids)->toContain(...$this->mineLate->pluck('id')->all())
        // Somebody else's late task is in the unscoped list and is not on this plate.
        ->and($ids)->not->toContain($this->theirsLate->id);

    // Every row really is one of the Admin's own — checked against the join, not the service.
    $assignedToAdmin = Task::query()
        ->forEmployee($this->adminEmployee)
        ->whereIn('id', $ids)
        ->count();

    expect($assignedToAdmin)->toBe(count($ids));

    // …and the same predicate, unscoped, is a bigger set on the same List.
    $agency = $this->actingAs($this->admin)
        ->get('/admin/tasks?bucket=overdue')
        ->assertOk()
        ->inertiaPage()['props']['tasks']['total'];

    expect($agency)->toBeGreaterThan($list['total']);
})->group('phase2');

it('falls back to the whole plate when the link asks for a bucket that does not exist', function () {
    // A stale link asks a question that no longer exists; the useful answer is the plate.
    $this->actingAs($this->admin)
        ->get('/admin/my-tasks?bucket=not-a-bucket')
        ->assertRedirect('/admin/tasks?scope=mine&bucket=open');
})->group('phase2');

it('keeps completed work reachable through its own bucket', function () {
    $done = Task::factory()
        ->for($this->project)
        ->status(TaskStatus::Completed)
        ->assignedTo($this->adminEmployee)
        ->create(['completed_at' => Carbon::now()]);

    $this->actingAs($this->admin)->get('/admin/my-tasks?bucket=completed')
        ->assertRedirect('/admin/tasks?scope=mine&bucket=completed');

    $list = $this->actingAs($this->admin)->get('/admin/tasks?scope=mine&bucket=completed')
        ->inertiaPage()['props']['tasks'];

    expect(collect($list['groups'])->flatMap(fn (array $group) => array_column($group['tasks'], 'id'))->all())->toContain($done->id);
})->group('phase2');

it('refuses the page to a role that holds no tasks permission', function () {
    // The Accountant has no tasks.* key, so `viewAny` refuses them before the surface guard
    // would have. Their own surface is checked in the permission matrix.
    $this->actingAs($this->accountant)->get('/admin/my-tasks')->assertForbidden();
})->group('phase2');

/*
|--------------------------------------------------------------------------
| The Company dashboard
|--------------------------------------------------------------------------
*/

it('puts the plan\'s five task cards on the company dashboard, each with a real count', function () {
    $stats = $this->actingAs($this->admin)
        ->get('/admin/dashboard')
        ->assertOk()
        ->inertiaPage()['props']['workStats'];

    expect(array_column($stats, 'key'))
        ->toBe(['due_today', 'overdue', 'in_review', 'completed_today', 'active_projects'])
        ->and(array_column($stats, 'label'))
        ->toBe(['Tasks due today', 'Overdue', 'Awaiting review', 'Completed today', 'Active projects']);

    foreach ($stats as $stat) {
        expect($stat['count'])->toBeInt()
            ->and($stat['href'])->toStartWith('/admin/');
    }
})->group('phase2');

it('counts the agency on the company dashboard, not the admin\'s own plate', function () {
    $stats = $this->actingAs($this->admin)->get('/admin/dashboard')->inertiaPage()['props']['workStats'];
    $overdue = collect($stats)->firstWhere('key', 'overdue')['count'];

    // Three late tasks were made above; one of them is somebody else's, and the Company
    // dashboard counts it. `?scope=overdue` does not — that is the whole distinction.
    $mine = $this->actingAs($this->admin)
        ->get('/admin/tasks?scope=overdue')
        ->inertiaPage()['props']['tasks']['total'];

    expect($overdue)->toBeGreaterThan($mine);
})->group('phase2');

it('sends every dashboard card to exactly the tasks it counted', function () {
    $stats = $this->actingAs($this->admin)->get('/admin/dashboard')->inertiaPage()['props']['workStats'];

    foreach ($stats as $stat) {
        if ($stat['key'] === 'active_projects') {
            $total = $this->actingAs($this->admin)
                ->get($stat['href'])
                ->assertOk()
                ->inertiaPage()['props']['projects']['meta']['total'];
        } else {
            $total = $this->actingAs($this->admin)
                ->get($stat['href'])
                ->assertOk()
                ->inertiaPage()['props']['tasks']['total'];
        }

        // The card's number and the list behind it are the same query, asked twice.
        expect($total)->toBe($stat['count'], "card {$stat['key']} leads somewhere else");
    }
})->group('phase2');

it('leaves every card whose phase has not been built marked as unbuilt', function () {
    // "Present today" is Phase 4. A card that quietly went blank would read as nobody being
    // in; a card that showed a stand-in number would be worse.
    $props = $this->actingAs($this->admin)->get('/admin/dashboard')->inertiaPage()['props'];

    expect($props)->toHaveKey('stats.activeEmployees')
        ->and($props['workStats'])->toHaveCount(5);
})->group('phase2');

it('gives a manager no admin surface at all', function () {
    $manager = Employee::factory()->forRole(RoleName::MANAGER)->create()->user;

    $this->actingAs($manager)->get('/admin/my-tasks')->assertForbidden();
})->group('phase2');
