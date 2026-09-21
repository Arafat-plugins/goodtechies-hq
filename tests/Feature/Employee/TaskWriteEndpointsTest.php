<?php

use App\Models\Employee;
use App\Models\Project;
use App\Models\Tag;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\User;
use App\Support\RoleName;
use App\Support\TaskStatus;

/*
|--------------------------------------------------------------------------
| Employee surface task writes
|--------------------------------------------------------------------------
|
| An employee edits the WORK — the description, the work summary, the estimate,
| the checklist, the links — and moves their own card as far as In review. They
| do not edit the PLAN: the dates, the priority, the title, the project. The
| Manager lives on this surface too, so the ADMIN/MANAGER moves are routed here
| and refused to an employee by TaskPolicy rather than by being absent.
|
| A task an employee is not assigned to is absent: 404, never 403.
|
*/

beforeEach(function () {
    $this->seed();

    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->manager = Employee::factory()->forRole(RoleName::MANAGER)->create()->user;

    $this->project = Project::where('name', 'Buffalo Modular — SEO')->firstOrFail();
    // In progress, assigned to Tapu, on a project Yaseen is not a member of.
    $this->task = Task::where('title', 'Fix the duplicate canonical tags on model pages')->firstOrFail();
});

it('shows an assignee their task and hides everybody else\'s', function () {
    $page = $this->actingAs($this->tapu)
        ->get(route('employee.tasks.show', $this->task))
        ->assertOk()
        ->inertiaPage();

    expect($page['component'])->toBe('Employee/Tasks/Show')
        ->and($page['props']['task']['id'])->toBe($this->task->id)
        ->and($page['props']['task']['checklist'])->toHaveCount(4)
        ->and($page['props'])->toHaveKeys(['activity', 'reviewers', 'tags'])
        // The picker of everybody's names is not sent to this surface at all, and neither is a
        // list of projects: `project_id` is `prohibited` for an employee.
        ->and($page['props'])->not->toHaveKey('employees')
        ->and($page['props'])->not->toHaveKey('projects')
        // `task.available_transitions` is the answer the screen uses; the full status list was
        // a wider answer to the same question that nothing read.
        ->and($page['props'])->not->toHaveKey('statuses');

    // Absent, not refused.
    $this->actingAs($this->yaseen)
        ->get(route('employee.tasks.show', $this->task))
        ->assertNotFound();
})->group('phase2');

it('lets an assignee edit the work and refuses them the plan', function () {
    $this->actingAs($this->tapu)
        ->put(route('employee.tasks.update', $this->task), [
            'description' => 'Rewritten by the person doing it.',
            'estimated_minutes' => 240,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($this->task->fresh()->description)->toBe('Rewritten by the person doing it.')
        ->and($this->task->fresh()->estimated_minutes)->toBe(240);

    // Dates are Admin/Manager only — the same rule as "date changes by drag are Admin/Manager
    // only", seen from the form. It is a validation refusal with a message, not a silent drop.
    $this->actingAs($this->tapu)
        ->put(route('employee.tasks.update', $this->task), ['due_date' => now()->addMonth()->toDateString()])
        ->assertSessionHasErrors('due_date');

    $this->actingAs($this->tapu)
        ->put(route('employee.tasks.update', $this->task), ['title' => 'Renamed'])
        ->assertSessionHasErrors('title');

    expect($this->task->fresh()->title)->toBe('Fix the duplicate canonical tags on model pages');
})->group('phase2');

it('lets a manager change the dates an employee may not', function () {
    $this->actingAs($this->manager)
        ->put(route('employee.tasks.update', $this->task), [
            'due_date' => now()->addMonth()->toDateString(),
            'title' => 'Fix the canonicals',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($this->task->fresh()->title)->toBe('Fix the canonicals');
})->group('phase2');

it('will not let the employee update endpoint move a status either', function () {
    $this->actingAs($this->tapu)
        ->put(route('employee.tasks.update', $this->task), [
            'description' => 'Still going.',
            'status' => TaskStatus::Completed->value,
        ])
        ->assertRedirect();

    expect($this->task->fresh()->status)->toBe(TaskStatus::InProgress);
})->group('phase2');

it('takes an employee as far as in review and no further', function () {
    // Submitting needs a work summary, which the status endpoint carries.
    $this->actingAs($this->tapu)
        ->post(route('employee.tasks.status', $this->task), ['status' => TaskStatus::InReview->value])
        ->assertRedirect()
        ->assertSessionHas('error');

    expect($this->task->fresh()->status)->toBe(TaskStatus::InProgress);

    $this->actingAs($this->tapu)
        ->post(route('employee.tasks.status', $this->task), [
            'status' => TaskStatus::InReview->value,
            'work_summary' => 'All eight repointed and re-crawled.',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($this->task->fresh()->status)->toBe(TaskStatus::InReview)
        ->and($this->task->fresh()->work_summary_by)->toBe($this->tapu->id);

    // Completed is a review verdict, and an employee is never a reviewer.
    $this->actingAs($this->tapu)
        ->post(route('employee.tasks.status', $this->task), ['status' => TaskStatus::Completed->value])
        ->assertForbidden();

    expect($this->task->fresh()->status)->toBe(TaskStatus::InReview);
})->group('phase2');

it('gives a drag exactly the same answer as the form', function () {
    // The drag's extra payload is a position, not a permission: the same endpoint, the same
    // request, the same service call, so there is no softer path for it to take.
    $landing = Task::factory()->status(TaskStatus::Completed)->create(['project_id' => $this->project->id]);

    // Submitted for review by its assignee, which is as far as they go.
    $this->actingAs($this->tapu)->post(route('employee.tasks.status', $this->task), [
        'status' => TaskStatus::InReview->value,
        'work_summary' => 'Ready for review.',
    ])->assertRedirect();

    // Dragging the card into the Completed column is the same call with a landing position,
    // and gets the same 403 the form gets.
    $this->actingAs($this->tapu)
        ->post(route('employee.tasks.status', $this->task), [
            'status' => TaskStatus::Completed->value,
            'after_id' => $landing->id,
        ])
        ->assertForbidden();

    expect($this->task->fresh()->status)->toBe(TaskStatus::InReview);
})->group('phase2');

it('reorders a card inside its own column', function () {
    $a = Task::factory()->status(TaskStatus::InProgress)->create(['project_id' => $this->project->id, 'position' => 1000]);
    Task::factory()->status(TaskStatus::InProgress)->create(['project_id' => $this->project->id, 'position' => 2000]);

    $this->actingAs($this->tapu)
        ->post(route('employee.tasks.reorder', $this->task), ['after_id' => $a->id])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($this->task->fresh()->position)->toBe(1500);
})->group('phase2');

it('lets an assignee keep the checklist and the links', function () {
    $this->actingAs($this->tapu)
        ->post(route('employee.tasks.checklist.store', $this->task), ['title' => 'Re-crawl once more'])
        ->assertRedirect();

    $item = TaskChecklistItem::where('task_id', $this->task->id)->latest('id')->firstOrFail();

    $this->actingAs($this->tapu)
        ->put(route('employee.tasks.checklist.update', [$this->task, $item]), ['is_done' => true])
        ->assertRedirect();

    expect($item->fresh()->completed_by)->toBe($this->tapu->id);

    $this->actingAs($this->tapu)
        ->post(route('employee.tasks.links.store', $this->task), ['url' => 'https://example.com/crawl'])
        ->assertRedirect();

    expect($this->task->fresh()->links()->count())->toBe(3);
})->group('phase2');

it('refuses an employee the manager-only moves it routes here', function () {
    // Archive and delete are ADMIN/MANAGER in the plan and a Manager reaches only this surface,
    // so the routes exist here — and an employee who can see the task is still refused.
    $this->actingAs($this->tapu)->post(route('employee.tasks.archive', $this->task))->assertForbidden();
    $this->actingAs($this->tapu)->delete(route('employee.tasks.destroy', $this->task))->assertForbidden();

    expect(Task::find($this->task->id)?->isArchived())->toBeFalse();

    $this->actingAs($this->manager)->post(route('employee.tasks.archive', $this->task))->assertRedirect();

    expect($this->task->fresh()->isArchived())->toBeTrue();

    // Unarchiving is Admin-only, and an Admin is on the Admin surface: there is no route here.
    expect(app('router')->getRoutes()->hasNamedRoute('employee.tasks.unarchive'))->toBeFalse();
})->group('phase2');

it('lets a manager delete on the surface they actually reach', function () {
    $this->actingAs($this->manager)
        ->delete(route('employee.tasks.destroy', $this->task))
        ->assertRedirect(route('employee.tasks.index'));

    expect(Task::find($this->task->id))->toBeNull()
        ->and(Task::withTrashed()->find($this->task->id))->not->toBeNull();
})->group('phase2');

it('keeps an admin off the employee surface entirely', function () {
    $admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();

    $this->actingAs($admin)->get(route('employee.tasks.show', $this->task))->assertForbidden();
    $this->actingAs($admin)->put(route('employee.tasks.update', $this->task))->assertForbidden();
})->group('phase2');

it('answers 404, not 403, for a task the employee is not on', function () {
    // The rule that matters: "you may not see this" must not confirm that it exists.
    $this->actingAs($this->yaseen)
        ->put(route('employee.tasks.update', $this->task), ['description' => 'mine now'])
        ->assertNotFound();

    $this->actingAs($this->yaseen)
        ->post(route('employee.tasks.reorder', $this->task))
        ->assertNotFound();

    expect($this->task->fresh()->description)->not->toBe('mine now');
})->group('phase2');

/*
|--------------------------------------------------------------------------
| Tags
|--------------------------------------------------------------------------
|
| "Admin/Manager create them … employees only assign existing tags." The
| assigning half is the work, so it is here; the creating half has no endpoint
| on any surface yet.
|
*/

it('lets an assignee tag their own task with a tag that already exists', function () {
    $tag = Tag::where('name', 'Maintenance')->firstOrFail();
    $mine = Tag::factory()->forProject($this->project)->create(['name' => 'Canonicals']);

    $this->actingAs($this->tapu)
        ->put(route('employee.tasks.update', $this->task), ['tag_ids' => [$tag->id, $mine->id]])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($this->task->fresh()->tags->pluck('id')->sort()->values()->all())
        ->toBe(collect([$tag->id, $mine->id])->sort()->values()->all());

    // Assigning is not creating: a name is not a tag, and an id that is not a tag is refused.
    $this->actingAs($this->tapu)
        ->put(route('employee.tasks.update', $this->task), ['tag_ids' => [$tag->id + 100000]])
        ->assertSessionHasErrors('tag_ids.0');

    // A tag belonging to somebody else's project is refused here for the same reason it is on
    // the Admin surface — the rule is the task's project, not the requester's role.
    $elsewhere = Project::where('name', 'Heat Gap — SEO Retainer')->firstOrFail();
    $foreign = Tag::factory()->forProject($elsewhere)->create(['name' => 'Heat Gap only']);

    $this->actingAs($this->tapu)
        ->put(route('employee.tasks.update', $this->task), ['tag_ids' => [$foreign->id]])
        ->assertSessionHasErrors('tag_ids.0');

    expect($this->task->fresh()->tags)->toHaveCount(2);
})->group('phase2');

it('refuses to let an employee move the task between projects while tagging it', function () {
    // `project_id` is the plan, and prohibited for an employee — so the tag scope cannot be
    // redirected at another project by sending one.
    $elsewhere = Project::where('name', 'Heat Gap — SEO Retainer')->firstOrFail();
    $theirs = Tag::factory()->forProject($elsewhere)->create(['name' => 'Heat Gap only']);

    $before = $this->task->tags()->pluck('tags.id')->sort()->values()->all();

    $this->actingAs($this->tapu)
        ->put(route('employee.tasks.update', $this->task), [
            'project_id' => $elsewhere->id,
            'tag_ids' => [$theirs->id],
        ])
        ->assertSessionHasErrors(['project_id', 'tag_ids.0']);

    expect($this->task->fresh()->project_id)->toBe($this->project->id)
        ->and($this->task->fresh()->tags()->pluck('tags.id')->sort()->values()->all())->toBe($before);
})->group('phase2');

it('sends the employee detail page the tags this task\'s project can use, and no others', function () {
    $mine = Tag::factory()->forProject($this->project)->create(['name' => 'Canonicals']);
    $elsewhere = Project::where('name', 'Heat Gap — SEO Retainer')->firstOrFail();
    $foreign = Tag::factory()->forProject($elsewhere)->create(['name' => 'Heat Gap only']);

    $tags = collect($this->actingAs($this->tapu)
        ->get(route('employee.tasks.show', $this->task))
        ->assertOk()
        ->inertiaPage()['props']['tags']);

    expect($tags->pluck('id'))->toContain($mine->id)
        ->toContain(Tag::where('name', 'SEO')->firstOrFail()->id)
        // A project this employee is not on does not get to name its labels at them.
        ->not->toContain($foreign->id)
        ->and(array_keys($tags->first()))->toBe(['id', 'name', 'colour', 'is_global']);
})->group('phase2');
