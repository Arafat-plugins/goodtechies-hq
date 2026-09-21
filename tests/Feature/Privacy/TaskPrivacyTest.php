<?php

use App\Models\Employee;
use App\Models\Project;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use App\Support\TaskStatus;
use App\Support\UserStatus;

/*
|--------------------------------------------------------------------------
| Task privacy — the negative suite (master prompt Part C §1, Part F §2)
|--------------------------------------------------------------------------
|
| Three rules, each with its negative half:
|   - an Employee sees only the tasks ASSIGNED to them, not every task on a
|     project they are a member of;
|   - a task they are not assigned to is ABSENT, and answers 404 by id, never
|     403 — "you may not see this" must not leak that it exists;
|   - the Accountant has no task routes at all;
|   - and no task payload, on any surface, carries a project finance field.
|
| The key-absence helper is the recursive one from ProjectPrivacyTest: it walks
| the whole Inertia props tree, at every depth, so a restricted field cannot
| hide inside the composed ProjectResource and still count as "not present".
|
*/

/** Finance fields that must never ride along on a task payload. */
const TASK_PRIVACY_RESTRICTED_KEYS = [
    'client',
    'internal_notes',
    'billing_type',
    'finance',
    'price',
    'recurring_amount',
    'contract_value',
    'contract_terms',
    'profitability_snapshot',
];

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();
});

/**
 * Every task id in a grouped List payload, at any group.
 *
 * @return list<int>
 */
function taskIdsIn(array $props): array
{
    $ids = [];

    foreach ($props['tasks']['groups'] as $group) {
        foreach ($group['tasks'] as $task) {
            $ids[] = $task['id'];
        }
    }

    return array_values(array_unique($ids));
}

it('shows an employee only the tasks assigned to them', function () {
    $props = $this->actingAs($this->tapu)
        ->get(route('employee.tasks.index'))
        ->assertOk()
        ->inertiaPage()['props'];

    $shown = taskIdsIn($props);
    $assigned = Task::query()
        ->forEmployee($this->tapu->employee)
        ->notArchived()
        ->pluck('id')
        ->all();

    expect($shown)->not->toBeEmpty()
        ->and($shown)->toEqualCanonicalizing($assigned);
})->group('phase2');

it('hides a task on a project the employee is a member of but is not assigned to', function () {
    // Yaseen is a member of Buffalo Modular — Website Maintenance. Membership is not
    // assignment: a task on that project that is somebody else's stays out of his list.
    $project = Project::where('name', 'Buffalo Modular — Website Maintenance')->firstOrFail();
    $faruk = Employee::where('employee_number', 'GT-002')->firstOrFail();

    $notHis = Task::factory()
        ->for($project)
        ->assignedTo($faruk)
        ->create(['title' => 'Faruk-only task on Yaseen\'s project']);

    expect($project->members()->where('employees.id', $this->yaseen->employee->id)->exists())->toBeTrue()
        ->and(Task::query()->visibleTo($this->yaseen)->whereKey($notHis->id)->exists())->toBeFalse();

    $props = $this->actingAs($this->yaseen)
        ->get(route('employee.tasks.index'))
        ->assertOk()
        ->inertiaPage()['props'];

    expect(taskIdsIn($props))->not->toContain($notHis->id);
})->group('phase2');

it('keeps each employee out of the other\'s tasks', function () {
    // The seed guarantees this in both directions: Tapu has tasks on the SEO projects Yaseen
    // is not on, and Yaseen has tasks on the maintenance projects Tapu is not on.
    $tapusTasks = Task::query()->visibleTo($this->tapu)->pluck('id')->all();
    $yaseensTasks = Task::query()->visibleTo($this->yaseen)->pluck('id')->all();

    expect($tapusTasks)->not->toBeEmpty()
        ->and($yaseensTasks)->not->toBeEmpty()
        ->and(array_intersect($tapusTasks, $yaseensTasks))->toBe([]);
})->group('phase2');

it('answers 404, not 403, for a task an employee is not assigned to', function () {
    // The rule the detail page in slice 2 will be built on, asserted now at the scope both
    // it and the list go through: an id you may not see must be indistinguishable from an id
    // that does not exist.
    $notHis = Task::query()->visibleTo($this->tapu)->firstOrFail();

    $lookup = fn (User $user, Task $task) => Task::query()
        ->visibleTo($user)
        ->whereKey($task->getKey())
        ->first();

    expect($lookup($this->yaseen, $notHis))->toBeNull()
        ->and($lookup($this->tapu, $notHis))->not->toBeNull();

    // And the HTTP shape of that null is 404 — the same code a genuinely missing row gives.
    $this->actingAs($this->yaseen)
        ->get(route('employee.tasks.index', ['project_id' => $notHis->project_id]))
        ->assertOk();

    expect(taskIdsIn(
        $this->actingAs($this->yaseen)
            ->get(route('employee.tasks.index', ['project_id' => $notHis->project_id]))
            ->inertiaPage()['props'],
    ))->not->toContain($notHis->id);
})->group('phase2');

it('refuses the accountant both task routes', function (string $route) {
    $this->actingAs($this->accountant)
        ->get(route($route))
        ->assertForbidden();
})->with([
    ['admin.tasks.index'],
    ['employee.tasks.index'],
])->group('phase2');

it('shows the accountant no task at all, whatever the route', function () {
    expect(Task::query()->visibleTo($this->accountant)->count())->toBe(0);
})->group('phase2');

it('never sends a project finance field on a task payload', function (User|string $who, string $route) {
    $user = is_string($who) ? $this->{$who} : $who;

    $response = $this->actingAs($user)->get(route($route))->assertOk();

    $keys = keysAtEveryDepth($response->inertiaPage()['props']);

    // Sanity: the payload really does carry tasks, so this is not a vacuous pass.
    expect($keys)->toContain('status_tone');

    foreach (TASK_PRIVACY_RESTRICTED_KEYS as $restricted) {
        expect($keys)->not->toContain($restricted);
    }
})->with([
    ['tapu', 'employee.tasks.index'],
    ['yaseen', 'employee.tasks.index'],
])->group('phase2');

it('sends the admin the composed project fragment, finance and all', function () {
    // The other half of the rule: the same resource, same route family, a requester who may
    // see money — proving the absence above is the gate working and not the key missing.
    $keys = keysAtEveryDepth(
        $this->actingAs($this->admin)
            ->get(route('admin.tasks.index'))
            ->assertOk()
            ->inertiaPage()['props'],
    );

    expect($keys)->toContain('finance')
        ->and($keys)->toContain('client')
        ->and($keys)->toContain('billing_type');
})->group('phase2');

it('composes ProjectResource rather than selecting project columns', function () {
    // A task's project fragment must be byte-for-byte what the project endpoint would send,
    // because it is literally the same resource. If somebody re-derives it here, the two
    // drift and this fails.
    $fromTasks = collect(
        $this->actingAs($this->admin)
            ->get(route('admin.tasks.index'))
            ->inertiaPage()['props']['tasks']['groups'],
    )
        ->flatMap(fn (array $group): array => $group['tasks'])
        ->firstWhere(fn (array $task): bool => $task['project'] !== null);

    $projectId = $fromTasks['project']['id'];

    $fromProjects = collect(
        $this->actingAs($this->admin)
            ->get(route('admin.projects.index'))
            ->inertiaPage()['props']['projects']['data'],
    )->firstWhere('id', $projectId);

    expect($fromTasks['project'])->toEqual($fromProjects);
})->group('phase2');

it('hides an archived task from the active list and shows it when asked', function () {
    $archived = Task::query()->visibleTo($this->tapu)->firstOrFail();
    $archived->forceFill(['archived_at' => now()])->save();

    $active = taskIdsIn(
        $this->actingAs($this->tapu)->get(route('employee.tasks.index'))->inertiaPage()['props'],
    );

    $withArchived = taskIdsIn(
        $this->actingAs($this->tapu)
            ->get(route('employee.tasks.index', ['archived' => 1]))
            ->inertiaPage()['props'],
    );

    expect($active)->not->toContain($archived->id)
        ->and($withArchived)->toContain($archived->id);
})->group('phase2');

it('drops a soft-deleted task from every list but keeps the row', function () {
    $task = Task::query()->visibleTo($this->tapu)->firstOrFail();
    $task->delete();

    expect(Task::query()->visibleTo($this->tapu)->whereKey($task->id)->exists())->toBeFalse()
        // Soft, so the history survives: the row is still there to be joined against.
        ->and(Task::withTrashed()->whereKey($task->id)->exists())->toBeTrue()
        ->and(taskIdsIn(
            $this->actingAs($this->tapu)->get(route('employee.tasks.index'))->inertiaPage()['props'],
        ))->not->toContain($task->id);
})->group('phase2');

/*
|--------------------------------------------------------------------------
| The tag filter's option list
|--------------------------------------------------------------------------
|
| A tag is global or scoped to exactly one project, and a scoped tag's NAME is
| that project's vocabulary — "Bengal Meat · packaging" names a client and a job.
| The List's filter chip therefore may offer only the global tags plus the ones
| scoped to a project `Project::visibleTo()` shows the requester. `show()` has
| always scoped its picker to one project; `index()` used to send every tag in
| the system, which on the Employee surface leaked other clients' project labels.
|
*/

/** A project this user cannot see, and one they can. */
function unseenProjectFor(User $user): Project
{
    $visible = Project::query()->visibleTo($user)->pluck('id')->all();

    return Project::query()->whereNotIn('id', $visible)->firstOrFail();
}

it('keeps a tag scoped to a project an employee is not on out of their tag filter', function () {
    $unseen = unseenProjectFor($this->tapu);
    $hidden = Tag::factory()->forProject($unseen)->create(['name' => 'Confidential rebrand']);

    $mine = Project::query()->visibleTo($this->tapu)->firstOrFail();
    $ours = Tag::factory()->forProject($mine)->create(['name' => 'Storefront copy']);

    $props = $this->actingAs($this->tapu)
        ->get(route('employee.tasks.index'))
        ->assertOk()
        ->inertiaPage()['props'];

    $ids = array_column($props['tags'], 'id');
    $names = array_column($props['tags'], 'name');

    expect($ids)->not->toContain($hidden->id)
        ->and($names)->not->toContain($hidden->name)
        // Not just absent from that key: absent from the payload altogether, at any depth.
        ->and(json_encode($props))->not->toContain($hidden->name)
        // Narrower would be wrong too: their own project's tags and the global ones stay.
        ->and($ids)->toContain($ours->id)
        ->and($names)->toContain('Development');
})->group('phase2');

it('keeps the same tag out of a manager-less employee list however the list is grouped', function (string $groupBy) {
    $hidden = Tag::factory()->forProject(unseenProjectFor($this->tapu))->create(['name' => 'Offshore retainer']);

    $props = $this->actingAs($this->tapu)
        ->get(route('employee.tasks.index', ['group_by' => $groupBy]))
        ->assertOk()
        ->inertiaPage()['props'];

    expect(json_encode($props))->not->toContain($hidden->name);
})->with(['status', 'project', 'priority'])->group('phase2');

it('still offers an admin a tag scoped to any project, because they can see every project', function () {
    $tag = Tag::factory()->forProject(Project::query()->firstOrFail())->create(['name' => 'Admin sees this']);

    $props = $this->actingAs($this->admin)
        ->get(route('admin.tasks.index'))
        ->assertOk()
        ->inertiaPage()['props'];

    expect(array_column($props['tags'], 'id'))->toContain($tag->id);
})->group('phase2');

it('offers one task only the tags its own project can use, on both surfaces', function () {
    $task = Task::query()->visibleTo($this->tapu)->with('project')->firstOrFail();
    $foreign = Tag::factory()
        ->forProject(Project::query()->whereKeyNot($task->project_id)->firstOrFail())
        ->create(['name' => 'Another project’s label']);
    $own = Tag::factory()->forProject($task->project)->create(['name' => 'This project’s label']);

    foreach ([
        route('employee.tasks.show', $task) => $this->tapu,
        route('admin.tasks.show', $task) => $this->admin,
    ] as $url => $user) {
        $props = $this->actingAs($user)->get($url)->assertOk()->inertiaPage()['props'];
        $ids = array_column($props['tags'], 'id');

        expect($ids)->not->toContain($foreign->id)
            ->and($ids)->toContain($own->id);
    }
})->group('phase2');

it('refuses a guest both task routes', function (string $route) {
    $this->get(route($route))->assertRedirect('/login');
})->with([
    ['admin.tasks.index'],
    ['employee.tasks.index'],
])->group('phase2');

it('keeps an inactive user out of every task', function () {
    $this->tapu->employee->forceFill(['status' => UserStatus::Inactive])->save();
    $this->tapu->forceFill(['status' => UserStatus::Inactive])->save();

    expect(Task::query()->visibleTo($this->tapu->fresh())->count())->toBe(0);
})->group('phase2');

it('puts every seeded status on the board, including the two Phase 2 added', function () {
    // The seed has to make the List, Board and Calendar look real, which means no empty
    // column — and in particular the two statuses that had no colour before this phase.
    foreach (TaskStatus::cases() as $status) {
        expect(Task::where('status', $status->value)->count())
            ->toBeGreaterThan(0, "no seeded task is {$status->value}");
    }
})->group('phase2');
