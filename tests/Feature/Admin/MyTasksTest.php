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

it('renders the seven buckets with a count and a destination each', function () {
    $this->actingAs($this->admin)
        ->get('/admin/my-tasks')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/MyTasks', false)
            ->has('buckets', 7)
            // The plan's order, and its name for the umbrella bucket.
            ->where('buckets.0.key', 'open')
            ->where('buckets.0.label', 'My tasks')
            ->where('buckets.0.href', '/admin/my-tasks')
            ->where('buckets.2.key', 'overdue')
            ->where('buckets.2.label', 'Overdue')
            // Every count leads somewhere, and the umbrella card is the way back out.
            ->where('buckets.2.href', '/admin/my-tasks?bucket=overdue')
            ->where('bucket', 'open')
            ->has('tasks')
            ->has('limit')
            ->has('today'),
        );
})->group('phase2');

it('shows an admin their own plate and not the agency\'s', function () {
    $props = $this->actingAs($this->admin)
        ->get('/admin/my-tasks?bucket=overdue')
        ->assertOk()
        ->inertiaPage()['props'];

    $ids = array_column($props['tasks'], 'id');
    $overdue = collect($props['buckets'])->firstWhere('key', 'overdue');

    // Checked by hand: the card's number IS the number of rows the same page lists, and the
    // rows are exactly the Admin's own late work.
    expect($props['bucket'])->toBe('overdue')
        ->and($overdue['count'])->toBe(count($ids))
        ->and($ids)->toContain(...$this->mineLate->pluck('id')->all())
        // Somebody else's late task is in /admin/tasks and is not on this plate.
        ->and($ids)->not->toContain($this->theirsLate->id);

    // Every row really is one of the Admin's own — checked against the join, not the service.
    $assignedToAdmin = Task::query()
        ->forEmployee($this->adminEmployee)
        ->whereIn('id', $ids)
        ->count();

    expect($assignedToAdmin)->toBe(count($ids));

    // …and the same predicate, unnarrowed, is a bigger set on the Tasks List.
    $agency = $this->actingAs($this->admin)
        ->get('/admin/tasks?bucket=overdue')
        ->assertOk()
        ->inertiaPage()['props']['tasks']['total'];

    expect($agency)->toBeGreaterThan($overdue['count']);
})->group('phase2');

it('falls back to the umbrella bucket when the link asks for one that does not exist', function () {
    // A stale link asks a question that no longer exists; the useful answer is the plate.
    $this->actingAs($this->admin)
        ->get('/admin/my-tasks?bucket=not-a-bucket')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('bucket', 'open'));
})->group('phase2');

it('keeps completed work out of the umbrella bucket and in its own', function () {
    $done = Task::factory()
        ->for($this->project)
        ->status(TaskStatus::Completed)
        ->assignedTo($this->adminEmployee)
        ->create(['completed_at' => Carbon::now()]);

    $props = $this->actingAs($this->admin)->get('/admin/my-tasks')->inertiaPage()['props'];

    expect(array_column($props['tasks'], 'id'))->not->toContain($done->id);

    $props = $this->actingAs($this->admin)
        ->get('/admin/my-tasks?bucket=completed')
        ->inertiaPage()['props'];

    expect(array_column($props['tasks'], 'id'))->toContain($done->id);
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
    // dashboard counts it. `/admin/my-tasks` does not — that is the whole distinction.
    $mine = $this->actingAs($this->admin)
        ->get('/admin/my-tasks?bucket=overdue')
        ->inertiaPage()['props']['buckets'];

    expect($overdue)->toBeGreaterThan(collect($mine)->firstWhere('key', 'overdue')['count']);
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
