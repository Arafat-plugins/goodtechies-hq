<?php

use App\Models\Employee;
use App\Models\Project;
use App\Models\RecurringTask;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskService;
use App\Support\TaskStatus;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| TaskResource — the payload contract
|--------------------------------------------------------------------------
|
| This file IS the contract the List view is written against. A key that
| disappears from a task payload breaks a screen that does not exist yet, so
| the keys are asserted by name rather than by spot check.
|
*/

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
});

/** One task payload, as the Admin List view sends it. */
function firstTaskPayload(object $test, ?User $as = null, array $query = []): array
{
    $props = $test->actingAs($as ?? $test->admin)
        ->get(route('admin.tasks.index', $query))
        ->assertOk()
        ->inertiaPage()['props'];

    foreach ($props['tasks']['groups'] as $group) {
        foreach ($group['tasks'] as $task) {
            return $task;
        }
    }

    throw new RuntimeException('no task in the payload');
}

it('sends exactly the documented task keys', function () {
    expect(array_keys(firstTaskPayload($this)))->toEqualCanonicalizing([
        'id', 'title', 'description',
        'status', 'status_label', 'status_tone',
        'priority', 'priority_label',
        'start_date', 'due_date', 'is_overdue',
        'estimated_minutes', 'tracked_seconds',
        'position', 'archived_at', 'is_archived',
        'work_summary', 'work_summary_by', 'work_summary_at',
        'completed_at', 'completed_by', 'first_completion',
        'created_at', 'created_by',
        'project', 'assignees', 'primary_assignee', 'tags',
        'subtask_count', 'subtasks_done_count', 'attachment_count', 'permissions',
        // checklist, links, dependencies, dependents, attachments and available_transitions
        // are the detail page's; on a list they are absent, not empty.
    ]);
})->group('phase2');

it('sends the grouped envelope the List view loops over', function () {
    $props = $this->actingAs($this->admin)
        ->get(route('admin.tasks.index'))
        ->assertOk()
        ->inertiaPage()['props'];

    expect(array_keys($props))->toEqualCanonicalizing([
        'tasks', 'filters', 'groupByOptions', 'statuses', 'priorities', 'projects', 'tags',
        // The bucket chip's options — the vocabulary the dashboard cards link in, so a
        // card's count and the list it opens are the same predicate.
        'buckets',
        // Whether the filter bar offers the tag manager. `TagPolicy::create`, answered here so
        // that no Vue file re-derives it from a role.
        'canManageTags',
        // The assignee picker's options, which slice 1 left the chip bar unable to build.
        'employees',
        // The shared props every page gets from HandleInertiaRequests.
        'auth', 'flash', 'errors', 'app',
    ])
        ->and(array_keys($props['tasks']))->toEqualCanonicalizing(['group_by', 'groups', 'total', 'overdue_count'])
        ->and(array_keys($props['tasks']['groups'][0]))->toEqualCanonicalizing(['key', 'label', 'tone', 'count', 'tasks'])
        ->and($props['groupByOptions'])->toBe(TaskService::GROUP_BY);
})->group('phase2');

it('resolves the badge tone on the server', function () {
    $payload = firstTaskPayload($this);

    // The screen never maps a status to a colour itself, so the two cannot drift.
    expect($payload['status_tone'])->toBe(TaskStatus::from($payload['status'])->tone())
        ->and($payload['status_label'])->toBe(TaskStatus::from($payload['status'])->label());
})->group('phase2');

it('gives every status a tone the StatusBadge knows', function () {
    // The eight StatusKey values in resources/js/Components/StatusBadge.vue. If a status ever
    // gains a tone with no token behind it, this is where it is caught.
    $known = ['backlog', 'todo', 'progress', 'review', 'changes', 'done', 'waiting', 'cancelled'];

    foreach (TaskStatus::cases() as $status) {
        expect($known)->toContain($status->tone());
    }
})->group('phase2');

it('flags the primary assignee and names them separately', function () {
    $shared = Task::query()->has('assignees', '>=', 2)->firstOrFail();

    $payload = collect(
        $this->actingAs($this->admin)
            ->get(route('admin.tasks.index'))
            ->inertiaPage()['props']['tasks']['groups'],
    )
        ->flatMap(fn (array $g): array => $g['tasks'])
        ->firstWhere('id', $shared->id);

    $primaries = array_filter($payload['assignees'], fn (array $a): bool => $a['is_primary']);

    expect($payload['assignees'])->toHaveCount(2)
        ->and($primaries)->toHaveCount(1)
        ->and($payload['primary_assignee']['id'])->toBe(reset($primaries)['id'])
        ->and($payload['primary_assignee']['name'])->not->toBeNull();
})->group('phase2');

it('carries the tag name and its token colour, never a hex value', function () {
    $payload = collect(
        $this->actingAs($this->admin)
            ->get(route('admin.tasks.index'))
            ->inertiaPage()['props']['tasks']['groups'],
    )
        ->flatMap(fn (array $g): array => $g['tasks'])
        ->firstWhere(fn (array $t): bool => $t['tags'] !== []);

    foreach ($payload['tags'] as $tag) {
        expect(array_keys($tag))->toEqualCanonicalizing(['id', 'name', 'colour', 'is_global'])
            // Colour is a StatusKey token name resolved by app.css, never a literal.
            ->and($tag['colour'])->not->toStartWith('#')
            ->and($tag['is_global'])->toBeTrue();
    }
})->group('phase2');

it('computes is_overdue rather than reading a column', function () {
    $asOf = Carbon::parse('2026-06-15');
    $project = Project::where('name', 'Buffalo Modular — SEO')->firstOrFail();
    $tapu = Employee::where('employee_number', 'GT-003')->firstOrFail();

    $late = Task::factory()->for($project)->assignedTo($tapu)->create([
        'title' => 'Late at the fixed date',
        'due_date' => $asOf->copy()->subDay(),
        'status' => TaskStatus::InProgress,
    ]);

    // Travel rather than a column: is_overdue is derived from the date at read time.
    $this->travelTo($asOf);

    $payload = collect(
        $this->actingAs($this->admin)
            ->get(route('admin.tasks.index'))
            ->inertiaPage()['props']['tasks']['groups'],
    )
        ->flatMap(fn (array $g): array => $g['tasks'])
        ->firstWhere('id', $late->id);

    expect($payload['is_overdue'])->toBeTrue();

    // The same row, a week earlier, is not overdue — and nothing was written to say so.
    $this->travelTo($asOf->copy()->subWeek());

    $earlier = collect(
        $this->actingAs($this->admin)
            ->get(route('admin.tasks.index'))
            ->inertiaPage()['props']['tasks']['groups'],
    )
        ->flatMap(fn (array $g): array => $g['tasks'])
        ->firstWhere('id', $late->id);

    expect($earlier['is_overdue'])->toBeFalse();

    $this->travelBack();
})->group('phase2');

it('reports subtask_count as zero until slice 2 adds checklists', function () {
    // The key exists now so the List view's Subtasks column has somewhere to read from, and
    // its shape will not change when the relation arrives.
    expect(firstTaskPayload($this)['subtask_count'])->toBe(0);
})->group('phase2');

it('mirrors the policy in permissions', function () {
    $payload = firstTaskPayload($this);

    expect(array_keys($payload['permissions']))
        ->toEqualCanonicalizing(['can_update', 'can_delete', 'can_archive', 'can_review']);

    // An employee sees a narrower set of trues on their own task than the admin does.
    $tapusTask = Task::query()->forEmployee($this->tapu->employee)->firstOrFail();

    $asTapu = collect(
        $this->actingAs($this->tapu)
            ->get(route('employee.tasks.index'))
            ->inertiaPage()['props']['tasks']['groups'],
    )
        ->flatMap(fn (array $g): array => $g['tasks'])
        ->firstWhere('id', $tapusTask->id);

    expect($asTapu['permissions']['can_update'])->toBeTrue()
        ->and($asTapu['permissions']['can_delete'])->toBeFalse()
        ->and($asTapu['permissions']['can_archive'])->toBeFalse()
        ->and($asTapu['permissions']['can_review'])->toBeFalse();
})->group('phase2');

it('names the creator as the original assigner', function () {
    $payload = firstTaskPayload($this);

    expect(array_keys($payload['created_by']))->toEqualCanonicalizing(['id', 'name'])
        ->and($payload['created_by']['name'])->not->toBeNull();
})->group('phase2');

it('sends dates as plain dates and timestamps as iso8601', function () {
    $completed = Task::where('status', 'completed')->firstOrFail();

    $payload = collect(
        $this->actingAs($this->admin)
            ->get(route('admin.tasks.index'))
            ->inertiaPage()['props']['tasks']['groups'],
    )
        ->flatMap(fn (array $g): array => $g['tasks'])
        ->firstWhere('id', $completed->id);

    expect($payload['due_date'])->toMatch('/^\d{4}-\d{2}-\d{2}$/')
        ->and($payload['completed_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T/')
        ->and($payload['created_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T/')
        // A completed task records who completed it and why it was accepted.
        ->and($payload['completed_by'])->not->toBeNull()
        ->and($payload['work_summary'])->not->toBeNull();
})->group('phase2');

it('offers the employee surface only the group-by variants that mean anything', function () {
    $props = $this->actingAs($this->tapu)
        ->get(route('employee.tasks.index'))
        ->assertOk()
        ->inertiaPage()['props'];

    // Every task here is already theirs, so group-by-assignee would produce one group.
    expect($props['groupByOptions'])->toBe(['status', 'project', 'priority'])
        ->and($props['groupByOptions'])->not->toContain('assignee');

    // ...and asking for it anyway falls back to status rather than erroring.
    $forced = $this->actingAs($this->tapu)
        ->get(route('employee.tasks.index', ['group_by' => 'assignee']))
        ->assertOk()
        ->inertiaPage()['props'];

    expect($forced['tasks']['group_by'])->toBe('status');
})->group('phase2');

it('echoes the filters back without the internal as-of date', function () {
    $props = $this->actingAs($this->admin)
        ->get(route('admin.tasks.index', ['status' => 'in_progress', 'overdue' => 1]))
        ->assertOk()
        ->inertiaPage()['props'];

    expect($props['filters']['status'])->toBe('in_progress')
        ->and($props['filters']['overdue'])->toBeTrue()
        // as_of is an internal detail of the overdue calculation, not a user-facing filter.
        ->and($props['filters'])->not->toHaveKey('as_of');
})->group('phase2');

it('names an assignee by employee id AND by user id, so the completion rule can be checked', function () {
    // The server decides completability by comparing `work_summary_by` — a USER — against the
    // primary assignee's `user_id`. The payload used to carry only the employee id and a
    // display name, so a screen could only bridge the two by name, and two people with the
    // same name are not the same person. `id` stays the employee's, which is the convention
    // ProjectResource sets for pm and members and which the assignee endpoints take back.
    $shared = Task::query()->has('assignees', '>=', 2)->firstOrFail();

    $payload = collect(
        $this->actingAs($this->admin)
            ->get(route('admin.tasks.index'))
            ->inertiaPage()['props']['tasks']['groups'],
    )
        ->flatMap(fn (array $g): array => $g['tasks'])
        ->firstWhere('id', $shared->id);

    foreach ($payload['assignees'] as $assignee) {
        expect(array_keys($assignee))->toEqualCanonicalizing(['id', 'user_id', 'name', 'is_primary'])
            ->and($assignee['user_id'])->toBe(Employee::findOrFail($assignee['id'])->user_id);
    }

    expect(array_keys($payload['primary_assignee']))->toEqualCanonicalizing(['id', 'user_id', 'name']);
})->group('phase2');

it('makes work_summary_by and the primary assignee comparable by id', function () {
    // Tapu writes the summary on his own task and submits it: `work_summary_by` is his USER id
    // and the primary assignee's `user_id` is the same number, which is the comparison
    // TaskService::assertCompletable() makes. Before this key, the screen could only compare
    // two strings and hope.
    $task = Task::where('title', 'Fix the duplicate canonical tags on model pages')->firstOrFail();

    $this->actingAs($this->tapu)->post(route('employee.tasks.status', $task), [
        'status' => TaskStatus::InReview->value,
        'work_summary' => 'All eight canonicals repointed.',
    ])->assertRedirect();

    $payload = $this->actingAs($this->admin)
        ->get(route('admin.tasks.show', $task))
        ->assertOk()
        ->inertiaPage()['props']['task'];

    expect($payload['work_summary_by']['id'])->toBe($this->tapu->id)
        ->and($payload['primary_assignee']['user_id'])->toBe($this->tapu->id)
        // The name bridge that used to be the only one available is still there, and is still
        // not identity: it is what the reader sees, not what the rule reads.
        ->and($payload['primary_assignee']['name'])->toBe($payload['work_summary_by']['name']);
})->group('phase2');

/*
|--------------------------------------------------------------------------
| Phase 3 — where a generated task came from
|--------------------------------------------------------------------------
*/

it('says which template made a task and for which period, on the detail payload', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-09'));

    $template = RecurringTask::where('title_template', 'Buffalo Modular Monthly SEO — {period}')
        ->firstOrFail();

    $this->actingAs($this->admin)
        ->post(route('admin.recurring.generate', $template))
        ->assertRedirect();

    $task = Task::where('recurring_task_id', $template->id)->firstOrFail();

    $payload = $this->actingAs($this->admin)
        ->get(route('admin.tasks.show', $task))
        ->assertOk()
        ->inertiaPage()['props']['task'];

    expect($payload['generated_from'])->not->toBeNull()
        ->and($payload['generated_from']['template'])->toBe($template->title_template)
        ->and($payload['generated_from']['template_id'])->toBe($template->id)
        ->and($payload['generated_from']['project_id'])->toBe($template->project_id)
        ->and($payload['generated_from']['period'])->toBe('2026-10')
        // Read off the stored KEY, so it survives the template's rule being changed.
        ->and($payload['generated_from']['period_label'])->toBe('October 2026')
        ->and($payload['generated_from']['can_manage'])->toBeTrue();

    Carbon::setTestNow();
})->group('phase3');

it('tells an assignee which template made their task, but offers them no way in', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-09'));

    $template = RecurringTask::where('title_template', 'Buffalo Modular Monthly SEO — {period}')
        ->firstOrFail();

    $this->actingAs($this->admin)->post(route('admin.recurring.generate', $template));

    $task = Task::where('recurring_task_id', $template->id)->firstOrFail();

    $payload = $this->actingAs($this->tapu)
        ->get(route('employee.tasks.show', $task))
        ->assertOk()
        ->inertiaPage()['props']['task'];

    // Tapu is the assignee, so he reads the provenance of the task in front of him — the
    // template's name is the pattern his own title was rendered from. Managing it is Admin's,
    // and `can_manage` is the policy's answer rather than a role read in Vue, so the screen
    // draws a sentence and not a link that would 403 him.
    expect($payload['generated_from']['period_label'])->toBe('October 2026')
        ->and($payload['generated_from']['template'])->toBe($template->title_template)
        ->and($payload['generated_from']['can_manage'])->toBeFalse();

    Carbon::setTestNow();
})->group('phase3');

it('leaves generated_from null on a hand-made task and off a list payload entirely', function () {
    $payload = $this->actingAs($this->admin)
        ->get(route('admin.tasks.show', Task::where('title', 'Fix the duplicate canonical tags on model pages')->firstOrFail()))
        ->assertOk()
        ->inertiaPage()['props']['task'];

    expect($payload['generated_from'])->toBeNull();

    // A list row is not a detail payload: the key is behind the same `task_detail` attribute
    // `available_transitions` is, so a board of two hundred cards does not do two hundred
    // relation reads and gate calls to print nothing.
    expect(firstTaskPayload($this))->not->toHaveKey('generated_from');
})->group('phase3');
