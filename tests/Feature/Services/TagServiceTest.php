<?php

use App\Exceptions\TaskStateException;
use App\Models\Employee;
use App\Models\Project;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use App\Services\TagService;
use App\Services\TaskService;
use App\Support\RoleName;
use App\Support\TagColour;
use Illuminate\Auth\Access\AuthorizationException;

/*
|--------------------------------------------------------------------------
| Tag management at the service
|--------------------------------------------------------------------------
|
| The endpoint file covers the HTTP shape. This one covers the two things
| that are decisions rather than plumbing: what the management list is scoped
| to, and what deleting a tag that tasks are wearing actually does.
|
| Assigning a tag is slice 2's and is not here — but the round trip is: a tag
| created for one project has to be immediately assignable on that project and
| refused on every other, or the two halves of "a tag belongs to one project"
| are not the same rule.
|
*/

beforeEach(function () {
    $this->seed();

    $this->tags = app(TagService::class);
    $this->taskService = app(TaskService::class);

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->manager = Employee::factory()->forRole(RoleName::MANAGER)->create()->user;

    $this->project = Project::where('name', 'Buffalo Modular — SEO')->firstOrFail();
});

/*
|--------------------------------------------------------------------------
| Scope
|--------------------------------------------------------------------------
*/

it('scopes the management list to the projects the manager can see', function () {
    // The same Tag::visibleTo() the pickers and the filter chip use. A management list that
    // asked a different question would be a fourth place the rule lives.
    $mine = Tag::factory()->forProject($this->project)->create(['name' => 'Canonicals']);

    $names = $this->tags->manageable($this->manager)->pluck('name');

    expect($names)->toContain('SEO')->toContain($mine->name)
        // An employee sees the global labels and their own projects' — and would be refused
        // the endpoint anyway, which is the second lock on the same door.
        ->and(Tag::query()->visibleTo($this->yaseen)->pluck('name'))->not->toContain($mine->name);
})->group('phase2');

it('reports how many tasks are wearing each one', function () {
    $seo = $this->tags->manageable($this->admin)->firstWhere('name', 'SEO');

    // The blast radius of a delete, which the panel has to be able to say before the click.
    expect((int) $seo->tasks_count)->toBe(Tag::where('name', 'SEO')->firstOrFail()->tasks()->count())
        ->and((int) $seo->tasks_count)->toBeGreaterThan(0);
})->group('phase2');

it('refuses to create one against a project the creator cannot see', function () {
    $hidden = Project::factory()->create();

    // Yaseen fails on the role check first, which is the point: `create` is Admin/Manager AND
    // in scope, and neither half is sufficient alone.
    expect(fn () => $this->tags->create($this->yaseen, 'Leaky', TagColour::Done, $hidden))
        ->toThrow(AuthorizationException::class);

    expect(Tag::where('name', 'Leaky')->exists())->toBeFalse();
})->group('phase2');

it('makes a new scoped tag assignable on its own project and nowhere else', function () {
    // The round trip: management creates it, assignment accepts it, and the second half of
    // the rule is TaskService::applyTags(), not a second copy of the scope check.
    $tag = $this->tags->create($this->admin, 'County pages', TagColour::Progress, $this->project);

    $mine = Task::query()->where('project_id', $this->project->id)->notArchived()->firstOrFail();
    $elsewhere = Task::query()->where('project_id', '!=', $this->project->id)->notArchived()->firstOrFail();

    $this->taskService->update($this->admin, $mine, ['tag_ids' => [$tag->id]]);

    expect($mine->fresh()->tags->pluck('id'))->toContain($tag->id);

    expect(fn () => $this->taskService->update($this->admin, $elsewhere, ['tag_ids' => [$tag->id]]))
        ->toThrow(TaskStateException::class);
})->group('phase2');

/*
|--------------------------------------------------------------------------
| Deleting one that is in use
|--------------------------------------------------------------------------
*/

it('deletes a tag in use rather than refusing, and says how many tasks it touched', function () {
    // The decision. Refusing while in use would make some tags undeletable by construction:
    // the only way to detach is the tag picker on each task, and a SCOPED tag on a task that
    // has since moved project is not even offered there.
    $tag = Tag::where('name', 'SEO')->firstOrFail();
    $count = $tag->tasks()->count();

    expect($count)->toBeGreaterThan(0)
        ->and($this->tags->delete($this->admin, $tag))->toBe($count)
        ->and(Tag::whereKey($tag->id)->exists())->toBeFalse();
})->group('phase2');

it('does not leave a soft-deleted tag occupying its own name', function () {
    // The other rejected option. A soft-deleted tag would still hold its name against the
    // unique index, so the obvious next move — recreate it spelled properly — would fail with
    // a constraint violation about a row nobody can see.
    $tag = $this->tags->create($this->admin, 'Typoo', TagColour::Review);

    $this->tags->delete($this->admin, $tag);

    $again = $this->tags->create($this->admin, 'Typoo', TagColour::Review);

    expect($again->exists)->toBeTrue()
        ->and(Tag::where('name', 'Typoo')->count())->toBe(1);
})->group('phase2');

it('refuses an employee every management verb', function () {
    $tag = Tag::where('name', 'Maintenance')->firstOrFail();

    // The same three refusals a controller would produce, at the service — so a job or a
    // console command is refused identically.
    expect(fn () => $this->tags->create($this->yaseen, 'Mine', TagColour::Done))
        ->toThrow(AuthorizationException::class);
    expect(fn () => $this->tags->update($this->yaseen, $tag, ['name' => 'Theirs']))
        ->toThrow(AuthorizationException::class);
    expect(fn () => $this->tags->delete($this->yaseen, $tag))
        ->toThrow(AuthorizationException::class);

    expect($tag->fresh()->name)->toBe('Maintenance');
})->group('phase2');
