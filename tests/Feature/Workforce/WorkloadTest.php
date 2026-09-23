<?php

use App\Models\Employee;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\WorkloadService;
use App\Support\RoleName;
use App\Support\TaskStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Admin → Workforce → Workload
|--------------------------------------------------------------------------
|
| Counts only, and every count is the same query the Tasks list runs under
| the same filter — which is what these tests check, by asking both and
| comparing. A number that only this screen can produce is a number nobody
| can check.
|
| The other half is Part H's: no ranking of people, no ratio between an
| estimate and the hours actually tracked, and no order that depends on a
| total. People come back in alphabetical order, and the two figures come
| back as two figures.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-24 09:00:00');

    $this->seed();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** One task on a project, assigned to somebody, with the fields this screen reads. */
function workloadTask(Employee $employee, Project $project, array $attributes = []): Task
{
    $task = Task::factory()->create([
        'project_id' => $project->id,
        'status' => TaskStatus::InProgress->value,
        ...$attributes,
    ]);

    $task->assignees()->attach($employee->id, ['is_primary' => true]);

    return $task;
}

/* ======================================== the counts match the Tasks list */

it('counts open tasks per employee exactly as the Tasks list does under the same filter', function (): void {
    $payload = $this->actingAs($this->admin)->get('/admin/workload')->assertOk()->viewData('page')['props'];

    foreach ($payload['employees'] as $row) {
        $list = $this->actingAs($this->admin)
            ->get('/admin/tasks?assignee_id='.$row['id'].'&bucket=open')
            ->assertOk()
            ->viewData('page')['props'];

        expect($row['open_count'])->toBe($list['tasks']['total'], "open for {$row['name']}");
    }
});

it('counts overdue per employee exactly as the Tasks list does under the same filter', function (): void {
    // One task that is unmistakably late, so the assertion is not comparing two zeroes.
    $tapu = $this->tapu->employee;
    workloadTask($tapu, Project::query()->firstOrFail(), [
        'title' => 'A thing that is late',
        'due_date' => '2026-09-01',
    ]);

    $payload = $this->actingAs($this->admin)->get('/admin/workload')->assertOk()->viewData('page')['props'];
    $row = collect($payload['employees'])->firstWhere('id', $tapu->id);

    $list = $this->actingAs($this->admin)
        ->get('/admin/tasks?assignee_id='.$tapu->id.'&bucket=overdue')
        ->assertOk()
        ->viewData('page')['props'];

    expect($row['overdue_count'])
        ->toBe($list['tasks']['total'])
        ->toBeGreaterThan(0);
});

it('counts a project\'s pending work exactly as the Tasks list does under the same filter', function (): void {
    $payload = $this->actingAs($this->admin)->get('/admin/workload')->assertOk()->viewData('page')['props'];

    expect($payload['projects'])->not->toBeEmpty();

    foreach ($payload['projects'] as $row) {
        $list = $this->actingAs($this->admin)
            ->get('/admin/tasks?project_id='.$row['id'].'&bucket=open')
            ->assertOk()
            ->viewData('page')['props'];

        expect($row['open_count'])->toBe($list['tasks']['total'], "open on {$row['name']}");
    }
});

/* ======================================================= estimated vs tracked */

it('reports the estimate and the tracked hours as two separate figures', function (): void {
    // A new employee rather than a seeded one, so every figure below is one this test put
    // there and the assertion is exact instead of a delta against the demo data.
    $newcomer = Employee::factory()->forRole(RoleName::REMOTE_EMPLOYEE)->create();
    $project = Project::query()->firstOrFail();

    // Two estimated tasks and one with no estimate at all: 90 + 30 minutes, and a gap the
    // screen has to own up to rather than let the sum hide.
    $first = workloadTask($newcomer, $project, ['title' => 'Estimated at 90', 'estimated_minutes' => 90]);
    workloadTask($newcomer, $project, ['title' => 'Estimated at 30', 'estimated_minutes' => 30]);
    workloadTask($newcomer, $project, ['title' => 'No estimate at all', 'estimated_minutes' => null]);

    // Two hours approved on the first, and an hour on it that nobody has signed off — which
    // must NOT reach the tracked figure (decision 4-7).
    TimeEntry::factory()->forEmployee($newcomer)->onTask($first)->create(['duration_seconds' => 7200]);
    TimeEntry::factory()->forEmployee($newcomer)->onTask($first)->manual()->create(['duration_seconds' => 3600]);

    $rows = collect(app(WorkloadService::class)->forViewer($this->admin)['employees']);
    $row = $rows->firstWhere('id', $newcomer->id);

    expect($row['estimated_minutes'])->toBe(120)
        ->and($row['unestimated_count'])->toBe(1)
        ->and($row['tracked_seconds'])->toBe(7200);

    // And the two are never combined: no ratio, no variance, no percentage on the row.
    expect(array_keys($row))->toBe([
        'id', 'name', 'employee_number', 'role', 'open_count', 'overdue_count',
        'estimated_minutes', 'unestimated_count', 'tracked_seconds',
    ]);
});

it('reads tracked hours from time_entries and not from the tasks cache', function (): void {
    $tapu = $this->tapu->employee;
    $task = workloadTask($tapu, Project::query()->firstOrFail(), ['title' => 'Fabricated cache']);

    // Phase 2's seeder wrote figures like this onto about twenty tasks (decision 4-8). A
    // capacity screen reading the cache would quote hours nobody worked.
    DB::table('tasks')->where('id', $task->id)->update(['tracked_seconds' => 99999]);

    $row = collect(app(WorkloadService::class)->forViewer($this->admin)['employees'])
        ->firstWhere('id', $tapu->id);

    expect($row['tracked_seconds'])->toBeLessThan(99999);
});

/* ============================================================ counts only */

it('lists people in alphabetical order and never by a total', function (): void {
    // Give the alphabetically last person the most work, so an order by any figure would
    // move them and this assertion would fail.
    $last = collect(app(WorkloadService::class)->forViewer($this->admin)['employees'])->last();
    $employee = Employee::query()->findOrFail($last['id']);
    $project = Project::query()->firstOrFail();

    foreach (range(1, 6) as $index) {
        workloadTask($employee, $project, ['title' => "Piled on {$index}"]);
    }

    $names = collect(app(WorkloadService::class)->forViewer($this->admin)['employees'])->pluck('name');

    expect($names->all())->toBe($names->sort(SORT_NATURAL | SORT_FLAG_CASE)->values()->all());
});

it('orders projects by how much is pending, most first', function (): void {
    $projects = collect(app(WorkloadService::class)->forViewer($this->admin)['projects']);
    $counts = $projects->pluck('open_count')->all();

    expect($counts)->toBe(collect($counts)->sortDesc()->values()->all());
});

it('leaves a project with nothing pending off the list', function (): void {
    $projects = collect(app(WorkloadService::class)->forViewer($this->admin)['projects']);

    expect($projects->pluck('open_count')->every(fn (int $count): bool => $count > 0))->toBeTrue();
});

/* ============================================================== who may read */

it('renders the workload screen for an Admin', function (): void {
    $this->actingAs($this->admin)
        ->get('/admin/workload')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Workload/Index')
            ->has('employees')
            ->has('projects')
            ->has('totals.open_count')
            ->has('totals.overdue_count')
            ->has('as_of.label'));
});

it('refuses the workload screen to every other role with a 403', function (): void {
    foreach ([$this->tapu, $this->yaseen, $this->accountant] as $user) {
        $this->actingAs($user)->get('/admin/workload')->assertForbidden();
    }
});

it('sends a guest to log in', function (): void {
    $this->get('/admin/workload')->assertRedirect('/login');
});
