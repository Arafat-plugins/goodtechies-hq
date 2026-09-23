<?php

use App\Models\Employee;
use App\Models\Project;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskService;
use App\Support\TaskPriority;
use App\Support\TaskStatus;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| TaskService — the query layer
|--------------------------------------------------------------------------
|
| The grouped List query, its four group-by variants, its filters and the
| overdue bucket. Overdue is the one that needs pinning hardest: it is computed
| at query time and never stored, so every test here fixes the as-of date
| rather than trusting whatever today happens to be when the suite runs.
|
*/

beforeEach(function () {
    $this->seed();

    $this->service = app(TaskService::class);
    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();
});

/**
 * @return list<int>
 */
function groupedIds(array $grouped): array
{
    $ids = [];

    foreach ($grouped['groups'] as $group) {
        foreach ($group['tasks'] as $task) {
            $ids[] = $task->id;
        }
    }

    return $ids;
}

/*
|--------------------------------------------------------------------------
| Group-by: one test per variant
|--------------------------------------------------------------------------
*/

it('groups by status, in board order, keeping the empty columns', function () {
    $grouped = $this->service->grouped($this->admin, [], 'status');

    expect($grouped['group_by'])->toBe('status')
        // Every status gets a group even when it is empty: a column that vanishes makes the
        // board look like the status does not exist.
        ->and($grouped['groups'])->toHaveCount(count(TaskStatus::cases()))
        ->and(array_column($grouped['groups'], 'key'))
        ->toBe(array_map(fn (TaskStatus $s): string => $s->value, TaskStatus::boardOrder()));

    foreach ($grouped['groups'] as $group) {
        $status = TaskStatus::from($group['key']);

        expect($group['label'])->toBe($status->label())
            ->and($group['tone'])->toBe($status->tone())
            ->and($group['count'])->toBe($group['tasks']->count());

        foreach ($group['tasks'] as $task) {
            expect($task->status)->toBe($status);
        }
    }

    // The counts add up to the total, because a task has exactly one status.
    expect(array_sum(array_column($grouped['groups'], 'count')))->toBe($grouped['total']);
})->group('phase2');

it('groups by priority, urgent first rather than alphabetically', function () {
    $grouped = $this->service->grouped($this->admin, [], 'priority');

    expect($grouped['group_by'])->toBe('priority')
        ->and(array_column($grouped['groups'], 'key'))
        ->toBe(['urgent', 'high', 'medium', 'low']);

    foreach ($grouped['groups'] as $group) {
        foreach ($group['tasks'] as $task) {
            expect($task->priority)->toBe(TaskPriority::from($group['key']));
        }
    }

    expect(array_sum(array_column($grouped['groups'], 'count')))->toBe($grouped['total']);
})->group('phase2');

it('groups by project, alphabetically, with only the projects that have tasks', function () {
    $grouped = $this->service->grouped($this->admin, [], 'project');

    $labels = array_column($grouped['groups'], 'label');
    $sorted = $labels;
    usort($sorted, 'strcasecmp');

    expect($grouped['group_by'])->toBe('project')
        ->and($labels)->toBe($sorted)
        ->and($labels)->not->toBeEmpty();

    foreach ($grouped['groups'] as $group) {
        foreach ($group['tasks'] as $task) {
            expect((string) $task->project_id)->toBe($group['key']);
        }
    }

    expect(array_sum(array_column($grouped['groups'], 'count')))->toBe($grouped['total']);
})->group('phase2');

it('groups by assignee and puts a two-person task in both groups', function () {
    $grouped = $this->service->grouped($this->admin, [], 'assignee');

    expect($grouped['group_by'])->toBe('assignee');

    // A task with two assignees is both people's work, so it appears under both. That means
    // the group counts sum to MORE than the total, which is correct, not a bug.
    $shared = Task::query()->has('assignees', '>=', 2)->firstOrFail();
    $groupsHoldingIt = array_filter(
        $grouped['groups'],
        fn (array $group): bool => $group['tasks']->contains('id', $shared->id),
    );

    expect($groupsHoldingIt)->toHaveCount(2)
        ->and(array_sum(array_column($grouped['groups'], 'count')))->toBeGreaterThan($grouped['total']);

    foreach ($grouped['groups'] as $group) {
        expect($group['count'])->toBe($group['tasks']->count());
    }
})->group('phase2');

it('sorts assignee groups by name and parks Unassigned at the end', function () {
    $project = Project::where('name', 'GoodTechies HQ — Internal')->firstOrFail();
    Task::factory()->for($project)->create(['title' => 'Nobody has picked this up yet']);

    $grouped = $this->service->grouped($this->admin, [], 'assignee');
    $keys = array_column($grouped['groups'], 'key');
    $labels = array_column($grouped['groups'], 'label');

    expect(end($keys))->toBe('unassigned')
        ->and(end($labels))->toBe('Unassigned');

    // Everything before it is alphabetical by name.
    $named = array_slice($labels, 0, -1);
    $sorted = $named;
    usort($sorted, 'strcasecmp');

    expect($named)->toBe($sorted);
})->group('phase2');

it('falls back to status for an unknown group-by', function () {
    expect($this->service->grouped($this->admin, [], 'nonsense')['group_by'])->toBe('status')
        ->and($this->service->grouped($this->admin, [], '')['group_by'])->toBe('status');
})->group('phase2');

/*
|--------------------------------------------------------------------------
| Overdue — at a fixed date, because it is computed, never stored
|--------------------------------------------------------------------------
*/

it('buckets overdue tasks at a fixed date', function () {
    $asOf = Carbon::parse('2026-06-15');
    $project = Project::where('name', 'Buffalo Modular — SEO')->firstOrFail();
    $tapu = Employee::where('employee_number', 'GT-003')->firstOrFail();

    // Clear the seed out so the fixture is exactly these five rows.
    Task::query()->forceDelete();

    $yesterday = Task::factory()->for($project)->assignedTo($tapu)
        ->create(['title' => 'Due yesterday, in progress', 'due_date' => $asOf->copy()->subDay(), 'status' => TaskStatus::InProgress]);
    $today = Task::factory()->for($project)->assignedTo($tapu)
        ->create(['title' => 'Due today, in progress', 'due_date' => $asOf->copy(), 'status' => TaskStatus::InProgress]);
    $tomorrow = Task::factory()->for($project)->assignedTo($tapu)
        ->create(['title' => 'Due tomorrow, in progress', 'due_date' => $asOf->copy()->addDay(), 'status' => TaskStatus::InProgress]);
    $done = Task::factory()->for($project)->assignedTo($tapu)
        ->create(['title' => 'Due yesterday but completed', 'due_date' => $asOf->copy()->subDay(), 'status' => TaskStatus::Completed]);
    $cancelled = Task::factory()->for($project)->assignedTo($tapu)
        ->create(['title' => 'Due yesterday but cancelled', 'due_date' => $asOf->copy()->subDay(), 'status' => TaskStatus::Cancelled]);
    $undated = Task::factory()->for($project)->assignedTo($tapu)
        ->create(['title' => 'No due date at all', 'due_date' => null, 'status' => TaskStatus::InProgress]);

    $overdue = $this->service->overdue($this->admin, [], $asOf)->pluck('id')->all();

    expect($overdue)->toBe([$yesterday->id])
        // Due today is not overdue: the rule is strictly `due_date < today`.
        ->and($overdue)->not->toContain($today->id)
        ->and($overdue)->not->toContain($tomorrow->id)
        // A finished or abandoned task is never overdue, whatever its due date says.
        ->and($overdue)->not->toContain($done->id)
        ->and($overdue)->not->toContain($cancelled->id)
        ->and($overdue)->not->toContain($undated->id);
})->group('phase2');

it('counts the overdue bucket in the grouped payload at the same fixed date', function () {
    $asOf = Carbon::parse('2026-06-15');
    $project = Project::where('name', 'Buffalo Modular — SEO')->firstOrFail();
    $tapu = Employee::where('employee_number', 'GT-003')->firstOrFail();

    Task::query()->forceDelete();

    Task::factory()->count(3)->for($project)->assignedTo($tapu)->sequence(
        ['title' => 'Late one', 'due_date' => $asOf->copy()->subDays(5)],
        ['title' => 'Late two', 'due_date' => $asOf->copy()->subDays(1)],
        ['title' => 'Not late', 'due_date' => $asOf->copy()->addDays(5)],
    )->create(['status' => TaskStatus::InProgress]);

    $grouped = $this->service->grouped($this->admin, ['as_of' => $asOf], 'status');

    expect($grouped['overdue_count'])->toBe(2)
        ->and($grouped['total'])->toBe(3);
})->group('phase2');

it('never stores overdue as a column', function () {
    // The rule is "computed at query time, never stored". If somebody adds the column, this
    // is the test that says no.
    expect(Schema::hasColumn('tasks', 'is_overdue'))->toBeFalse()
        ->and(Schema::hasColumn('tasks', 'overdue'))->toBeFalse();
})->group('phase2');

it('filters to the overdue bucket through the shared filter set', function () {
    $asOf = Carbon::parse('2026-06-15');

    $all = $this->service->grouped($this->admin, ['as_of' => $asOf], 'status')['total'];
    $onlyLate = $this->service->grouped($this->admin, ['as_of' => $asOf, 'overdue' => true], 'status');

    expect($onlyLate['total'])->toBeLessThan($all)
        // Everything in the bucket really is overdue at that date.
        ->and($onlyLate['total'])->toBe($onlyLate['overdue_count']);
})->group('phase2');

/*
|--------------------------------------------------------------------------
| Filters and scoping
|--------------------------------------------------------------------------
*/

it('scopes every query through visibleTo', function () {
    $adminTotal = $this->service->grouped($this->admin)['total'];
    $tapuTotal = $this->service->grouped($this->tapu)['total'];
    $accountantTotal = $this->service->grouped($this->accountant)['total'];

    expect($adminTotal)->toBeGreaterThan($tapuTotal)
        ->and($tapuTotal)->toBeGreaterThan(0)
        ->and($accountantTotal)->toBe(0);

    // The accountant's groups exist but are all empty — the shape does not change with the
    // requester, only the contents.
    expect($this->service->grouped($this->accountant)['groups'])->toHaveCount(count(TaskStatus::cases()));
})->group('phase2');

it('filters by project, status, priority, assignee and tag', function () {
    $project = Project::where('name', 'Buffalo Modular — SEO')->firstOrFail();
    $tapu = Employee::where('employee_number', 'GT-003')->firstOrFail();
    $seo = Tag::where('name', 'SEO')->firstOrFail();

    // Set comparison, not sequence: this test is about which rows survive the filter.
    // Ordering has its own test below.
    $byProject = $this->service->grouped($this->admin, ['project_id' => $project->id]);
    expect(groupedIds($byProject))->toEqualCanonicalizing(
        Task::where('project_id', $project->id)->notArchived()->pluck('id')->all(),
    );

    $byStatus = $this->service->grouped($this->admin, ['status' => 'in_progress']);
    expect($byStatus['total'])->toBe(Task::where('status', 'in_progress')->notArchived()->count())
        ->and($byStatus['total'])->toBeGreaterThan(0);

    $byPriority = $this->service->grouped($this->admin, ['priority' => 'urgent']);
    expect($byPriority['total'])->toBe(Task::where('priority', 'urgent')->notArchived()->count())
        ->and($byPriority['total'])->toBeGreaterThan(0);

    $byAssignee = $this->service->grouped($this->admin, ['assignee_id' => $tapu->id]);
    expect($byAssignee['total'])->toBe(Task::query()->forEmployee($tapu)->notArchived()->count())
        ->and($byAssignee['total'])->toBeGreaterThan(0);

    $byTag = $this->service->grouped($this->admin, ['tag_id' => $seo->id]);
    expect($byTag['total'])->toBe($seo->tasks()->whereNull('archived_at')->count())
        ->and($byTag['total'])->toBeGreaterThan(0);
})->group('phase2');

it('searches on title, case-insensitively', function () {
    $task = Task::query()->firstOrFail();
    $fragment = mb_strtoupper(mb_substr($task->title, 0, 8));

    expect(groupedIds($this->service->grouped($this->admin, ['search' => $fragment])))
        ->toContain($task->id);
})->group('phase2');

it('drops an unrecognised enum filter rather than passing it to the query', function () {
    $filters = $this->service->filters(['status' => 'not-a-status', 'priority' => 'urgentish']);

    expect($filters['status'])->toBeNull()
        ->and($filters['priority'])->toBeNull();

    // And the list is therefore unfiltered rather than empty.
    expect($this->service->grouped($this->admin, ['status' => 'not-a-status'])['total'])
        ->toBe($this->service->grouped($this->admin)['total']);
})->group('phase2');

it('defaults every filter key so the payload shape never varies', function () {
    expect(array_keys($this->service->filters([])))->toEqualCanonicalizing([
        'search', 'project_id', 'status', 'priority', 'assignee_id', 'tag_id',
        // The bucket a card was counted in, and "assigned to me" — what the My Tasks page and
        // the two dashboards ask with.
        'bucket', 'mine',
        // The calendar's window. Defaulted to null like every other key, so a caller that
        // asks for no window gets the same array shape as one that does.
        'date_from', 'date_to',
        'overdue', 'archived', 'as_of',
    ]);
})->group('phase2');

it('hides archived tasks unless asked for them', function () {
    $task = Task::query()->firstOrFail();
    $task->forceFill(['archived_at' => now()])->save();

    expect(groupedIds($this->service->grouped($this->admin)))->not->toContain($task->id)
        ->and(groupedIds($this->service->grouped($this->admin, ['archived' => true])))->toContain($task->id);
})->group('phase2');

it('orders by due date with undated last, then position, then id', function () {
    $ids = groupedIds($this->service->grouped($this->admin, ['project_id' => Project::where('name', 'Buffalo Modular — SEO')->firstOrFail()->id], 'project'));

    $tasks = Task::whereIn('id', $ids)->get()->keyBy('id');
    $dated = [];
    $undated = [];

    foreach ($ids as $id) {
        $tasks[$id]->due_date === null ? $undated[] = $id : $dated[] = $id;
    }

    // Everything dated comes before everything undated...
    expect(array_merge($dated, $undated))->toBe($ids);

    // ...and the dated ones are ascending.
    $dates = array_map(fn (int $id): string => $tasks[$id]->due_date->toDateString(), $dated);
    $sorted = $dates;
    sort($sorted);
    expect($dates)->toBe($sorted);
})->group('phase2');

it('eager-loads every relation the payload reads', function () {
    $grouped = $this->service->grouped($this->admin);
    $task = collect($grouped['groups'])->flatMap(fn (array $g): array => $g['tasks']->all())->first();

    // If any of these is lazily loaded, a list of 25 tasks is 100 queries.
    expect($task->relationLoaded('project'))->toBeTrue()
        ->and($task->relationLoaded('assignees'))->toBeTrue()
        ->and($task->relationLoaded('tags'))->toBeTrue()
        ->and($task->project->relationLoaded('pm'))->toBeTrue();
})->group('phase2');
