<?php

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tag;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\TaskLink;
use App\Models\User;
use App\Support\AuditEvent;
use App\Support\Permission as PermissionKey;
use App\Support\RoleName;
use App\Support\TaskPriority;
use App\Support\TaskStatus;
use App\Support\UserStatus;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| Admin task write endpoints
|--------------------------------------------------------------------------
|
| The Vue pages do not exist yet, so nothing here asserts on markup: these are
| the endpoints, their gates and what they leave behind. The single-path claim
| is the one worth reading — `PUT /admin/tasks/{id}` cannot move a status, and
| there is no second route that can.
|
*/

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->manager = Employee::factory()->forRole(RoleName::MANAGER)->create()->user;

    $this->tapuEmployee = Employee::where('employee_number', 'GT-003')->firstOrFail();
    $this->yaseenEmployee = Employee::where('employee_number', 'GT-004')->firstOrFail();

    $this->project = Project::where('name', 'Buffalo Modular — SEO')->firstOrFail();
    $this->task = Task::where('title', 'Fix the duplicate canonical tags on model pages')->firstOrFail();
});

it('sends the assignee picker its options', function () {
    // The 2-8 follow-up: TaskService has taken an assignee_id filter since slice 1 and no
    // controller sent the list the chip bar needed to offer it.
    $this->actingAs($this->admin)
        ->get(route('admin.tasks.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Tasks/Index')
            ->has('employees')
            ->has('employees.0', fn (Assert $employee) => $employee->has('id')->has('name')),
        );
})->group('phase2');

it('counts the checklist on the list, which is what the Subtasks column reads', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.tasks.index', ['group_by' => 'status']))
        ->assertOk()
        ->assertInertia(function (Assert $page) {
            $tasks = collect($page->toArray()['props']['tasks']['groups'])
                ->flatMap(fn (array $group): array => $group['tasks'])
                ->keyBy('id');

            expect($tasks[$this->task->id]['subtask_count'])->toBe(4)
                ->and($tasks[$this->task->id]['subtasks_done_count'])->toBe(1);
        });
})->group('phase2');

it('renders the detail page with its panels and the moves this user may make', function () {
    // No component() assertion: the Vue page is a separate brief and Inertia's helper checks
    // the file exists. The page NAME is asserted from the payload instead.
    $page = $this->actingAs($this->admin)
        ->get(route('admin.tasks.show', $this->task))
        ->assertOk()
        ->inertiaPage();

    expect($page['component'])->toBe('Admin/Tasks/Show')
        ->and($page['props']['task']['id'])->toBe($this->task->id)
        ->and($page['props']['task']['checklist'])->toHaveCount(4)
        ->and($page['props']['task']['links'])->toHaveCount(2)
        ->and($page['props']['task']['available_transitions'])->toBeArray()
        ->and($page['props'])->toHaveKeys(['activity', 'employees', 'reviewers', 'siblings', 'projects', 'tags'])
        // `available_transitions` is the narrower and correct answer to "which moves may this
        // requester make", and it is the one the screen reads. The full status list was a
        // second, wider answer to the same question that nothing asked.
        ->and($page['props'])->not->toHaveKey('statuses');
})->group('phase2');

it('creates a task and lands on it', function () {
    $response = $this->actingAs($this->admin)->post(route('admin.tasks.store'), [
        'project_id' => $this->project->id,
        'title' => 'Write the county page briefs',
        'priority' => TaskPriority::Medium->value,
        'assignee_ids' => [$this->tapuEmployee->id],
        'due_date' => now()->addWeek()->toDateString(),
    ]);

    $task = Task::where('title', 'Write the county page briefs')->firstOrFail();

    $response->assertRedirect(route('admin.tasks.show', $task))
        ->assertSessionHas('success');

    expect($task->status)->toBe(TaskStatus::Backlog)
        ->and($task->created_by)->toBe($this->admin->id)
        ->and($task->primary()?->id)->toBe($this->tapuEmployee->id);
})->group('phase2');

/*
|--------------------------------------------------------------------------
| One path for a status, and PUT is not it
|--------------------------------------------------------------------------
*/

it('does not let the general update endpoint move a status', function () {
    // Phase 1's fix, applied before the hole opens: `PUT /admin/projects/{id}` used to accept
    // `status`, and an assigned manager could cancel a project through the edit form.
    $this->actingAs($this->admin)
        ->put(route('admin.tasks.update', $this->task), [
            'title' => 'Renamed through the edit form',
            'status' => TaskStatus::Completed->value,
        ])
        ->assertRedirect();

    expect($this->task->fresh()->title)->toBe('Renamed through the edit form')
        ->and($this->task->fresh()->status)->toBe(TaskStatus::InProgress);
})->group('phase2');

it('has exactly one route per surface that can move a task', function () {
    $moving = collect(app('router')->getRoutes()->getRoutes())
        ->map(fn ($route): string => $route->getName() ?? '')
        ->filter(fn (string $name): bool => str_starts_with($name, 'admin.tasks.') || str_starts_with($name, 'employee.tasks.'))
        ->filter(fn (string $name): bool => str_ends_with($name, '.status'))
        ->values();

    expect($moving->all())->toEqualCanonicalizing(['admin.tasks.status', 'employee.tasks.status']);
})->group('phase2');

it('moves a task through the status endpoint, form-shaped and drag-shaped alike', function () {
    $column = Task::factory()->count(2)->sequence(
        ['position' => 1000],
        ['position' => 2000],
    )->status(TaskStatus::InReview)->create(['project_id' => $this->project->id]);

    // Drag-shaped: a status and the card it was dropped under.
    $this->actingAs($this->admin)
        ->post(route('admin.tasks.status', $this->task), [
            'status' => TaskStatus::InReview->value,
            'work_summary' => 'All eight canonicals repointed.',
            'after_id' => $column[0]->id,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    // The claim is "it landed under the card it was dropped under", not a particular integer.
    // A lane is every project's cards in that status, so the seed shares this one and the next
    // card along is not necessarily the other one this test made — which is exactly why the
    // number was 1500 back when a lane was scoped to one project.
    $anchor = $column[0]->fresh();
    $next = Task::query()
        ->where('status', TaskStatus::InReview->value)
        ->where('position', '>', $anchor->position)
        ->whereKeyNot($this->task->getKey())
        ->orderBy('position')
        ->first();

    expect($this->task->fresh()->status)->toBe(TaskStatus::InReview)
        ->and((int) $this->task->fresh()->position)->toBeGreaterThan((int) $anchor->position);

    if ($next !== null) {
        expect((int) $this->task->fresh()->position)->toBeLessThan((int) $next->position);
    }

    // Form-shaped: a status and a work summary, no position.
    $this->actingAs($this->admin)
        ->post(route('admin.tasks.status', $this->task), [
            'status' => TaskStatus::InProgress->value,
            'work_summary' => 'Pulled it back to fix two more.',
        ])
        ->assertRedirect();

    expect($this->task->fresh()->status)->toBe(TaskStatus::InProgress);
})->group('phase2');

it('refuses an illegal move with a flash error rather than a status code', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.tasks.status', $this->task), ['status' => TaskStatus::Completed->value])
        ->assertRedirect()
        ->assertSessionHas('error');

    expect($this->task->fresh()->status)->toBe(TaskStatus::InProgress);
})->group('phase2');

it('writes the status change to the audit trail with its old and new values', function () {
    $this->actingAs($this->admin)->post(route('admin.tasks.status', $this->task), [
        'status' => TaskStatus::InReview->value,
        'work_summary' => 'Eight canonicals done.',
    ]);

    $audit = AuditLog::where('event', AuditEvent::TaskStatusChanged->value)->latest('id')->firstOrFail();

    expect($audit->target_id)->toBe($this->task->id)
        ->and($audit->old_value['status'])->toBe('in_progress')
        ->and($audit->new_value['status'])->toBe('in_review')
        ->and($audit->actor_id)->toBe($this->admin->id);
})->group('phase2');

/*
|--------------------------------------------------------------------------
| The rest of the write surface
|--------------------------------------------------------------------------
*/

it('changes the assignees through their own endpoint, and audits it', function () {
    $this->actingAs($this->admin)
        ->put(route('admin.tasks.assignees', $this->task), [
            'assignee_ids' => [$this->tapuEmployee->id, $this->yaseenEmployee->id],
            'primary_assignee_id' => $this->yaseenEmployee->id,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($this->task->fresh()->primary()?->id)->toBe($this->yaseenEmployee->id)
        ->and(AuditLog::where('event', AuditEvent::TaskReassigned->value)->where('target_id', $this->task->id)->exists())
        ->toBeTrue();
})->group('phase2');

it('hands a task over through its own endpoint', function () {
    $this->task->assignees()->syncWithoutDetaching([$this->yaseenEmployee->id => ['is_primary' => false]]);

    $this->actingAs($this->admin)
        ->post(route('admin.tasks.handoff', $this->task), [
            'employee_id' => $this->yaseenEmployee->id,
            'reason' => 'Tapu is on leave.',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($this->task->fresh()->primary()?->id)->toBe($this->yaseenEmployee->id);
})->group('phase2');

it('requires a reason for a hand-off', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.tasks.handoff', $this->task), ['employee_id' => $this->yaseenEmployee->id])
        ->assertSessionHasErrors('reason');
})->group('phase2');

it('keeps a checklist through its endpoints', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.tasks.checklist.store', $this->task), ['title' => 'Re-run the crawl'])
        ->assertRedirect();

    $item = TaskChecklistItem::where('task_id', $this->task->id)->latest('id')->firstOrFail();

    $this->actingAs($this->admin)
        ->put(route('admin.tasks.checklist.update', [$this->task, $item]), ['is_done' => true])
        ->assertRedirect();

    expect($item->fresh()->is_done)->toBeTrue()
        ->and($item->fresh()->completed_by)->toBe($this->admin->id);

    $this->actingAs($this->admin)
        ->delete(route('admin.tasks.checklist.destroy', [$this->task, $item]))
        ->assertRedirect();

    expect(TaskChecklistItem::find($item->id))->toBeNull();
})->group('phase2');

it('refuses a checklist item that belongs to another task', function () {
    $other = Task::factory()->create(['project_id' => $this->project->id]);
    $item = TaskChecklistItem::create(['task_id' => $other->id, 'title' => 'Elsewhere', 'position' => 1000]);

    $this->actingAs($this->admin)
        ->put(route('admin.tasks.checklist.update', [$this->task, $item]), ['is_done' => true])
        ->assertNotFound();
})->group('phase2');

it('keeps links, and refuses one that is not http', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.tasks.links.store', $this->task), ['url' => 'javascript:alert(1)'])
        ->assertSessionHasErrors('url');

    $this->actingAs($this->admin)
        ->post(route('admin.tasks.links.store', $this->task), [
            'url' => 'https://example.com/brief',
            'label' => 'The brief',
        ])
        ->assertRedirect();

    $link = TaskLink::where('task_id', $this->task->id)->latest('id')->firstOrFail();

    expect($link->label)->toBe('The brief')
        ->and($link->created_by)->toBe($this->admin->id);

    $this->actingAs($this->admin)
        ->delete(route('admin.tasks.links.destroy', [$this->task, $link]))
        ->assertRedirect();

    expect(TaskLink::find($link->id))->toBeNull();
})->group('phase2');

it('records and drops a dependency', function () {
    $other = Task::where('title', 'Rewrite the Home Model page titles')->firstOrFail();

    $this->actingAs($this->admin)
        ->post(route('admin.tasks.dependencies.store', $this->task), ['depends_on_task_id' => $other->id])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($this->task->fresh()->dependencies()->pluck('tasks.id')->all())->toBe([$other->id]);

    $this->actingAs($this->admin)
        ->delete(route('admin.tasks.dependencies.destroy', [$this->task, $other]))
        ->assertRedirect();

    expect($this->task->fresh()->dependencies()->count())->toBe(0);
})->group('phase2');

it('archives and unarchives', function () {
    $this->actingAs($this->admin)->post(route('admin.tasks.archive', $this->task))->assertRedirect();

    expect($this->task->fresh()->isArchived())->toBeTrue();

    // Archived is a state, not a permission: an edit comes back as a flash error, not a 403.
    $this->actingAs($this->admin)
        ->put(route('admin.tasks.update', $this->task), ['title' => 'Nope'])
        ->assertRedirect()
        ->assertSessionHas('error');

    $this->actingAs($this->admin)->post(route('admin.tasks.unarchive', $this->task))->assertRedirect();

    expect($this->task->fresh()->isArchived())->toBeFalse();
})->group('phase2');

it('soft-deletes and audits', function () {
    $this->actingAs($this->admin)
        ->delete(route('admin.tasks.destroy', $this->task))
        ->assertRedirect(route('admin.tasks.index'));

    expect(Task::find($this->task->id))->toBeNull()
        ->and(Task::withTrashed()->find($this->task->id))->not->toBeNull()
        ->and(AuditLog::where('event', AuditEvent::TaskDeleted->value)->where('target_id', $this->task->id)->exists())
        ->toBeTrue();
})->group('phase2');

/*
|--------------------------------------------------------------------------
| Who may reach any of this
|--------------------------------------------------------------------------
*/

it('keeps every non-admin off the admin write routes', function () {
    // The matrix covers the surface guard on every task route whose allowed cell does not
    // destroy the record it points at. A DELETE row does destroy it — and route-model binding
    // runs before the surface middleware, so the cells after it read 404. These three are
    // therefore asserted here, against records that are still present.
    $item = TaskChecklistItem::where('task_id', $this->task->id)->firstOrFail();
    $link = TaskLink::where('task_id', $this->task->id)->firstOrFail();

    $routes = [
        route('admin.tasks.destroy', $this->task),
        route('admin.tasks.checklist.destroy', [$this->task, $item]),
        route('admin.tasks.links.destroy', [$this->task, $link]),
    ];

    foreach ([$this->manager, $this->tapu] as $user) {
        foreach ($routes as $url) {
            $this->actingAs($user)->delete($url)->assertForbidden();
        }
    }

    // …and nothing was removed on the way past.
    expect(Task::find($this->task->id))->not->toBeNull()
        ->and(TaskChecklistItem::find($item->id))->not->toBeNull()
        ->and(TaskLink::find($link->id))->not->toBeNull();
})->group('phase2');

it('answers 404 for a task that is already deleted', function () {
    $this->task->delete();

    $this->actingAs($this->admin)
        ->get(route('admin.tasks.show', $this->task->id))
        ->assertNotFound();
})->group('phase2');

/*
|--------------------------------------------------------------------------
| Tags
|--------------------------------------------------------------------------
|
| Assigning a tag is an edit, so it rides on the update endpoint and needs
| exactly what an edit needs. Creating one is slice 4's and is not reachable
| from anywhere here.
|
*/

it('sets a task\'s tags and refuses one scoped to another project', function () {
    $global = Tag::where('name', 'SEO')->firstOrFail();
    $mine = Tag::factory()->forProject($this->project)->create(['name' => 'Canonicals']);

    $elsewhere = Project::where('name', 'Heat Gap — SEO Retainer')->firstOrFail();
    $foreign = Tag::factory()->forProject($elsewhere)->create(['name' => 'Heat Gap only']);

    $this->actingAs($this->admin)
        ->put(route('admin.tasks.update', $this->task), ['tag_ids' => [$global->id, $mine->id]])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($this->task->fresh()->tags->pluck('id')->sort()->values()->all())
        ->toBe(collect([$global->id, $mine->id])->sort()->values()->all());

    // A tag belonging to another project is refused against the field, not quietly dropped:
    // the requester asked for something the task cannot have and is told so.
    $this->actingAs($this->admin)
        ->put(route('admin.tasks.update', $this->task), ['tag_ids' => [$global->id, $foreign->id]])
        ->assertSessionHasErrors('tag_ids.1');

    expect($this->task->fresh()->tags)->toHaveCount(2);

    // An empty list is "no tags", and clears them.
    $this->actingAs($this->admin)
        ->put(route('admin.tasks.update', $this->task), ['tag_ids' => []])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($this->task->fresh()->tags)->toHaveCount(0);
})->group('phase2');

it('leaves the old project\'s tags behind when a task moves', function () {
    $global = Tag::where('name', 'SEO')->firstOrFail();
    $mine = Tag::factory()->forProject($this->project)->create(['name' => 'Canonicals']);
    $elsewhere = Project::where('name', 'Heat Gap — SEO Retainer')->firstOrFail();

    $this->task->tags()->sync([$global->id, $mine->id]);

    // The move says nothing about tags, which is exactly the case a silent leftover hides in.
    $this->actingAs($this->admin)
        ->put(route('admin.tasks.update', $this->task), ['project_id' => $elsewhere->id])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($this->task->fresh()->project_id)->toBe($elsewhere->id)
        // The global tag travels; the one that belonged to the old project does not.
        ->and($this->task->fresh()->tags->pluck('id')->all())->toBe([$global->id]);
})->group('phase2');

it('checks a tag against the project the task is moving TO, not the one it is leaving', function () {
    $elsewhere = Project::where('name', 'Heat Gap — SEO Retainer')->firstOrFail();
    $theirs = Tag::factory()->forProject($elsewhere)->create(['name' => 'Heat Gap only']);
    $mine = Tag::factory()->forProject($this->project)->create(['name' => 'Canonicals']);

    // One request that moves the task and tags it with a tag of its NEW project.
    $this->actingAs($this->admin)
        ->put(route('admin.tasks.update', $this->task), [
            'project_id' => $elsewhere->id,
            'tag_ids' => [$theirs->id],
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($this->task->fresh()->tags->pluck('id')->all())->toBe([$theirs->id]);

    // …and the mirror image: a tag of the project it just left is now the foreign one.
    $this->actingAs($this->admin)
        ->put(route('admin.tasks.update', $this->task->fresh()), ['tag_ids' => [$mine->id]])
        ->assertSessionHasErrors('tag_ids.0');
})->group('phase2');

it('sends the detail page the tags this task\'s project can actually use', function () {
    $mine = Tag::factory()->forProject($this->project)->create(['name' => 'Canonicals']);
    $elsewhere = Project::where('name', 'Heat Gap — SEO Retainer')->firstOrFail();
    $foreign = Tag::factory()->forProject($elsewhere)->create(['name' => 'Heat Gap only']);

    $ids = collect($this->actingAs($this->admin)
        ->get(route('admin.tasks.show', $this->task))
        ->assertOk()
        ->inertiaPage()['props']['tags'])->pluck('id');

    expect($ids)->toContain($mine->id)
        // Every global tag is usable anywhere, so all four seeded ones are offered…
        ->toContain(Tag::where('name', 'SEO')->firstOrFail()->id)
        // …and another project's is not, which is also the only answer `tag_ids` would take.
        ->not->toContain($foreign->id);
})->group('phase2');

it('sends the detail page a project list, because moving a task between projects is a write it takes', function () {
    $props = $this->actingAs($this->admin)
        ->get(route('admin.tasks.show', $this->task))
        ->assertOk()
        ->inertiaPage()['props'];

    expect($props['projects'])->not->toBeEmpty()
        ->and(collect($props['projects'])->pluck('id'))->toContain($this->project->id)
        ->and(array_keys($props['projects'][0]))->toBe(['id', 'name']);
})->group('phase2');

/*
|--------------------------------------------------------------------------
| Who may hold a task
|--------------------------------------------------------------------------
*/

it('offers as assignees only the people whose role may work a task', function () {
    $accountant = Employee::where('employee_number', 'GT-005')->firstOrFail();

    $ids = fn (): array => collect($this->actingAs($this->admin)
        ->get(route('admin.tasks.index'))
        ->assertOk()
        ->inertiaPage()['props']['employees'])->pluck('id')->all();

    // The Accountant holds no tasks.* permission: a task assigned to them is a task they
    // cannot even open, so they are not on the list.
    expect($ids())->toContain($this->tapuEmployee->id)
        ->and($ids())->toContain($this->yaseenEmployee->id)
        ->and($ids())->not->toContain($accountant->id);

    // And the rule is the permission, not the role name. Give the Accountant's role tasks.view
    // and they appear; take it off the Remote Employee's and Tapu disappears — without a line
    // of this changing. That is what makes it still true for a role invented next year.
    $tasksView = Permission::where('key', PermissionKey::TasksView->value)->firstOrFail();

    Role::where('name', RoleName::ACCOUNTANT->value)->firstOrFail()->permissions()->attach($tasksView->id);
    Role::where('name', RoleName::REMOTE_EMPLOYEE->value)->firstOrFail()->permissions()->detach($tasksView->id);

    expect($ids())->toContain($accountant->id)
        ->and($ids())->not->toContain($this->tapuEmployee->id);
})->group('phase2');

it('keeps an inactive employee off the assignee list', function () {
    $this->yaseenEmployee->forceFill(['status' => UserStatus::Inactive->value])->save();

    $ids = collect($this->actingAs($this->admin)
        ->get(route('admin.tasks.index'))
        ->assertOk()
        ->inertiaPage()['props']['employees'])->pluck('id');

    expect($ids)->not->toContain($this->yaseenEmployee->id);
})->group('phase2');
