<?php

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\AuditEvent;
use App\Support\Permission as PermissionName;
use App\Support\RoleName;
use App\Support\TagColour;

/*
|--------------------------------------------------------------------------
| Tag management: who may create, rename and remove a label
|--------------------------------------------------------------------------
|
| The plan's sentence is "Tags: Admin/Manager create (global or per project),
| colour; employees only assign existing tags; filter by tag." This file is
| the first two thirds of it. Assigning — `tag_ids` on the task update — is
| slice 2's and is tested in the task write endpoint files; the last third,
| filtering, is in TaskServiceTest.
|
| The endpoints exist on BOTH surfaces, because an Admin is on one shell and a
| Manager is on the other. That is the same shape task delete and archive
| already have, and it means an employee's refusal is a 403 from TagPolicy
| rather than a route that happens not to be there.
|
*/

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();
    $this->manager = Employee::factory()->forRole(RoleName::MANAGER)->create()->user;

    $this->project = Project::where('name', 'Buffalo Modular — SEO')->firstOrFail();
});

/*
|--------------------------------------------------------------------------
| Creating
|--------------------------------------------------------------------------
*/

it('lets an Admin create a global tag', function () {
    $this->actingAs($this->admin)
        ->post('/admin/tags', ['name' => 'Copywriting', 'colour' => 'waiting'])
        ->assertRedirect();

    $tag = Tag::where('name', 'Copywriting')->firstOrFail();

    expect($tag->project_id)->toBeNull()
        ->and($tag->isGlobal())->toBeTrue()
        ->and($tag->colour)->toBe(TagColour::Waiting);
})->group('phase2');

it('lets an Admin scope a tag to one project', function () {
    $this->actingAs($this->admin)
        ->post('/admin/tags', [
            'name' => 'County pages',
            'colour' => 'progress',
            'project_id' => $this->project->id,
        ])
        ->assertRedirect();

    expect(Tag::where('name', 'County pages')->firstOrFail()->project_id)->toBe($this->project->id);
})->group('phase2');

it('lets a Manager create one from the surface they actually live on', function () {
    // The plan gives creation to Admin AND Manager, and a Manager never reaches the Admin
    // shell. Without this route that half of the sentence would be unreachable.
    $this->actingAs($this->manager)
        ->post('/employee/tags', ['name' => 'Discovery', 'colour' => 'review'])
        ->assertRedirect();

    expect(Tag::where('name', 'Discovery')->exists())->toBeTrue();
})->group('phase2');

it('refuses a colour that is not a status token', function (string $colour) {
    $this->actingAs($this->admin)
        ->post('/admin/tags', ['name' => 'Rejected', 'colour' => $colour])
        ->assertSessionHasErrors('colour');

    expect(Tag::where('name', 'Rejected')->exists())->toBeFalse();
})->with([
    'a hex' => ['#ff0000'],
    'a css word' => ['rebeccapurple'],
    'a status that is not a tone' => ['blocked'],
])->group('phase2');

it('refuses a second tag with the same name in the same scope', function () {
    // `SEO` is one of the four seeded global labels.
    $this->actingAs($this->admin)
        ->post('/admin/tags', ['name' => 'SEO', 'colour' => 'done'])
        ->assertSessionHasErrors('name');

    expect(Tag::whereNull('project_id')->where('name', 'SEO')->count())->toBe(1);
})->group('phase2');

it('refuses a project the creator cannot see, as if it did not exist', function () {
    // A 403 would confirm the project is real. Part C's rule is that a record they may not see
    // reads as absent, and the validation message says exactly what an unknown id says.
    $manager = $this->manager;
    $hidden = Project::factory()->create();

    // Force the case the rule is for: a user with no project visibility at all.
    $this->actingAs($this->yaseen)
        ->post('/employee/tags', [
            'name' => 'Leaky',
            'colour' => 'done',
            'project_id' => $hidden->id,
        ])
        ->assertSessionHasErrors('project_id');

    expect(Tag::where('name', 'Leaky')->exists())->toBeFalse()
        ->and($manager->exists)->toBeTrue();
})->group('phase2');

/*
|--------------------------------------------------------------------------
| Renaming and recolouring
|--------------------------------------------------------------------------
*/

it('renames and recolours a tag', function () {
    $tag = Tag::factory()->create(['name' => 'Ould', 'colour' => 'todo']);

    $this->actingAs($this->admin)
        ->put("/admin/tags/{$tag->id}", ['name' => 'Old', 'colour' => 'cancelled'])
        ->assertRedirect();

    $tag->refresh();

    expect($tag->name)->toBe('Old')
        ->and($tag->colour)->toBe(TagColour::Cancelled);
})->group('phase2');

it('refuses to move a tag between scopes', function () {
    // Not an omission. A global tag dragged into a project would come off every task in every
    // other project the instant it saved; a scoped one dragged out would publish that client's
    // vocabulary. Neither is a rename, so the field is `prohibited` rather than ignored.
    $tag = Tag::factory()->create(['name' => 'Stays global']);

    $this->actingAs($this->admin)
        ->put("/admin/tags/{$tag->id}", ['project_id' => $this->project->id])
        ->assertSessionHasErrors('project_id');

    expect($tag->fresh()->project_id)->toBeNull();
})->group('phase2');

/*
|--------------------------------------------------------------------------
| Deleting — including a tag that tasks are wearing
|--------------------------------------------------------------------------
*/

it('deletes a tag that is in use and takes the label off every task', function () {
    $tag = Tag::where('name', 'SEO')->firstOrFail();
    $wearing = $tag->tasks()->pluck('tasks.id');

    expect($wearing)->not->toBeEmpty();

    $this->actingAs($this->admin)
        ->delete("/admin/tags/{$tag->id}")
        ->assertRedirect()
        // The flash names the blast radius, because the caller has just changed records that
        // are not on their screen.
        ->assertSessionHas('success', fn (string $message): bool => str_contains($message, 'came off'));

    expect(Tag::whereKey($tag->id)->exists())->toBeFalse();

    foreach ($wearing as $taskId) {
        expect(Task::findOrFail($taskId)->tags()->pluck('tags.id'))->not->toContain($tag->id);
    }
})->group('phase2');

it('records the tasks a deleted tag came off, where nobody can tidy it away', function () {
    $tag = Tag::where('name', 'Development')->firstOrFail();
    $wearing = $tag->tasks()->pluck('tasks.id')->map(fn ($id): int => (int) $id)->all();

    $this->actingAs($this->admin)->delete("/admin/tags/{$tag->id}")->assertRedirect();

    $entry = AuditLog::where('event', AuditEvent::TagDeleted->value)->latest('id')->firstOrFail();

    // `task_tags` cascaded, so this row is the ONLY surviving record of which tasks were
    // wearing the label. audit_logs is append-only and hq_app holds no UPDATE or DELETE on it.
    expect($entry->old_value['name'])->toBe('Development')
        ->and($entry->old_value['task_ids'])->toEqualCanonicalizing($wearing)
        ->and($entry->actor_id)->toBe($this->admin->id);
})->group('phase2');

it('tells each affected task on its own timeline', function () {
    $tag = Tag::where('name', 'Branding')->firstOrFail();
    $task = $tag->tasks()->firstOrFail();

    $this->actingAs($this->admin)->delete("/admin/tags/{$tag->id}")->assertRedirect();

    // The assignee finds out on the task, rather than wondering where their label went.
    expect(app(ActivityLogger::class)->for($task)->pluck('description'))
        ->toContain('Tag removed: Branding (the tag was deleted)');
})->group('phase2');

/*
|--------------------------------------------------------------------------
| The list
|--------------------------------------------------------------------------
*/

it('lists a tag with its scope, its usage count and the permissions on it', function () {
    $payload = $this->actingAs($this->admin)->getJson('/admin/tags')->assertOk()->json();

    $seo = collect($payload['tags'])->firstWhere('name', 'SEO');

    expect(array_keys($seo))->toEqualCanonicalizing([
        'id', 'name', 'colour', 'colour_label', 'is_global', 'project', 'task_count', 'permissions',
    ])
        ->and($seo['colour'])->not->toStartWith('#')
        ->and($seo['is_global'])->toBeTrue()
        ->and($seo['project'])->toBeNull()
        // The blast radius, so a panel can say it before the click rather than after.
        ->and($seo['task_count'])->toBeGreaterThan(0)
        ->and($seo['permissions'])->toBe(['can_update' => true, 'can_delete' => true])
        // The colour picker's options come from the server, so the list of tones and the CHECK
        // constraint behind them cannot drift apart in a hand-written array.
        ->and(collect($payload['colours'])->pluck('value')->all())->toBe(TagColour::values());
})->group('phase2');

it('does not name a project a manager cannot see in the tag list', function () {
    // A tag's NAME is a name: one scoped to somebody else's project names that client's work.
    $scoped = Tag::factory()->forProject($this->project)->create(['name' => 'Buffalo internals']);

    // An employee is refused the list outright, so the scoping is shown with a user who gets
    // the list but not the project: a Manager sees every project, so the assertion below is
    // that the query IS scoped, through the same Tag::visibleTo() the pickers use.
    $names = collect($this->actingAs($this->admin)->getJson('/admin/tags')->json('tags'))->pluck('name');

    expect($names)->toContain($scoped->name)
        ->and(Tag::query()->visibleTo($this->yaseen)->pluck('name'))->not->toContain($scoped->name);
})->group('phase2');

/*
|--------------------------------------------------------------------------
| Who may not
|--------------------------------------------------------------------------
*/

it('refuses an employee the whole of tag management', function () {
    $tag = Tag::where('name', 'Maintenance')->firstOrFail();

    // Yaseen can SEE this label — it is on his own tasks and in his picker — and still may not
    // create, rename or remove one. That is the plan's split: employees assign, they do not
    // manage. The refusal is a 403 about their role, not a 404 about the record.
    $this->actingAs($this->yaseen)->getJson('/employee/tags')->assertForbidden();

    $this->actingAs($this->yaseen)
        ->post('/employee/tags', ['name' => 'Mine', 'colour' => 'done'])
        ->assertForbidden();

    $this->actingAs($this->yaseen)
        ->put("/employee/tags/{$tag->id}", ['name' => 'Renamed by an employee'])
        ->assertForbidden();

    $this->actingAs($this->yaseen)->delete("/employee/tags/{$tag->id}")->assertForbidden();

    expect(Tag::whereKey($tag->id)->firstOrFail()->name)->toBe('Maintenance')
        ->and(Tag::where('name', 'Mine')->exists())->toBeFalse();
})->group('phase2');

it('refuses a remote employee the same way', function () {
    $this->actingAs($this->tapu)->getJson('/employee/tags')->assertForbidden();
})->group('phase2');

it('gives the Accountant none of it', function () {
    // Not by naming them — they hold no tasks.* permission, and they are on neither surface.
    $tag = Tag::where('name', 'SEO')->firstOrFail();

    $this->actingAs($this->accountant)->getJson('/admin/tags')->assertForbidden();
    $this->actingAs($this->accountant)->getJson('/employee/tags')->assertForbidden();
    $this->actingAs($this->accountant)->post('/admin/tags', ['name' => 'X', 'colour' => 'done'])->assertForbidden();
    $this->actingAs($this->accountant)->delete("/employee/tags/{$tag->id}")->assertForbidden();

    expect(Tag::whereKey($tag->id)->exists())->toBeTrue();
})->group('phase2');

it('keeps each surface to its own role', function () {
    // An Admin is not on the Employee shell and a Manager is not on the Admin one. Both are
    // 403s about the surface, from EnsureSurface, before any policy runs.
    $this->actingAs($this->admin)->getJson('/employee/tags')->assertForbidden();
    $this->actingAs($this->manager)->getJson('/admin/tags')->assertForbidden();
})->group('phase2');

it('answers 404, not 422, when the tag being renamed is one this manager cannot see', function () {
    // `UpdateTagRequest::rules()` builds its uniqueness scope from the routed tag's project —
    // and a Form Request validates BEFORE the controller body, so before `visibleTag()` has
    // had a chance to 404. Without a visibility check ahead of the rules, a PUT naming an
    // invisible tag with a colliding name answers "A tag with that name already exists in
    // this scope", which confirms both the id and the name of a label inside another client's
    // work. Part C: a record they may not see is ABSENT at the FIRST thing that answers.
    //
    // Reaching that state takes a manager who may still manage tags (`tasks.create` and the
    // role) but sees no projects, so `projects.view` comes off the MANAGER role here. No
    // seeded role is in that shape today — this pins the ordering so that none ever can be.
    $role = Role::where('name', RoleName::MANAGER)->firstOrFail();
    $role->permissions()->detach(
        Permission::where('key', PermissionName::ProjectsView->value)->firstOrFail()->id,
    );

    $scoped = Tag::factory()->forProject($this->project)->create(['name' => 'Buffalo internals']);

    expect(Tag::query()->visibleTo($this->manager->fresh())->whereKey($scoped->id)->exists())->toBeFalse();

    $this->actingAs($this->manager)
        // The same name it already has: a rename that would trip `unique` if the rules ran.
        ->put("/employee/tags/{$scoped->id}", ['name' => 'Buffalo internals'])
        ->assertNotFound();

    expect(Tag::whereKey($scoped->id)->firstOrFail()->name)->toBe('Buffalo internals');
})->group('phase2');

it('tells every Tasks screen whether to offer the tag manager', function () {
    // The alternative is a Vue file reading `auth.user.role` — a second copy of
    // TagPolicy::manages() living where nobody will remember to change it.
    foreach (['admin.tasks.index', 'admin.tasks.board', 'admin.tasks.calendar'] as $route) {
        expect($this->actingAs($this->admin)->get(route($route))->inertiaPage()['props']['canManageTags'])
            ->toBeTrue();
    }

    foreach (['employee.tasks.index', 'employee.tasks.board', 'employee.tasks.calendar'] as $route) {
        expect($this->actingAs($this->manager)->get(route($route))->inertiaPage()['props']['canManageTags'])
            ->toBeTrue();
        // An employee assigns labels and does not manage them, so the door is not drawn — and
        // the four endpoints behind it still answer 403 either way.
        expect($this->actingAs($this->yaseen)->get(route($route))->inertiaPage()['props']['canManageTags'])
            ->toBeFalse();
    }
})->group('phase2');
