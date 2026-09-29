<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskService;
use App\Support\TaskStatus;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Tasks — the toolbar's scope dropdown and the old My Tasks URLs
|--------------------------------------------------------------------------
|
| `?scope=mine|due-today|overdue` is mapped once, in TaskService::filters(),
| onto the `mine` and `bucket` filters that already existed. The sidebar's My
| Tasks / Due Today / Overdue rows are gone, and their URLs 302 to the Tasks
| List with the scope that asks the same question.
|
*/

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();

    $this->project = Project::factory()->create(['name' => 'Scope test project']);

    $this->yaseenLate = Task::factory()->for($this->project)->overdue()->assignedTo($this->yaseen->employee)->create();
    $this->yaseenToday = Task::factory()->for($this->project)->status(TaskStatus::Todo)
        ->assignedTo($this->yaseen->employee)->create(['due_date' => Carbon::today()]);
    $this->tapuLate = Task::factory()->for($this->project)->overdue()->assignedTo($this->tapu->employee)->create();
    $this->adminLate = Task::factory()->for($this->project)->overdue()->assignedTo($this->admin->employee)->create();
});

/** Every task id on a Tasks List payload, across its groups. */
function scopeListIds(array $props): array
{
    return collect($props['tasks']['groups'])->flatMap(fn (array $group) => array_column($group['tasks'], 'id'))
        ->unique()->values()->all();
}

dataset('scope views', [
    'admin list' => ['admin', '/admin/tasks'],
    'admin board' => ['admin', '/admin/tasks/board'],
    'admin calendar' => ['admin', '/admin/tasks/calendar'],
    'admin gantt' => ['admin', '/admin/tasks/gantt'],
    'employee list' => ['yaseen', '/employee/tasks'],
    'employee board' => ['yaseen', '/employee/tasks/board'],
    'employee calendar' => ['yaseen', '/employee/tasks/calendar'],
    'employee gantt' => ['yaseen', '/employee/tasks/gantt'],
]);

it('maps every scope onto the existing filters on every view', function (string $who, string $path) {
    $expected = [
        '' => [null, false, null],
        'mine' => ['mine', true, null],
        'due-today' => ['due-today', true, 'due_today'],
        'overdue' => ['overdue', true, 'overdue'],
        'not-a-scope' => [null, false, null],
    ];

    foreach ($expected as $scope => [$echo, $mine, $bucket]) {
        $filters = $this->actingAs($this->{$who})
            ->get($scope === '' ? $path : "{$path}?scope={$scope}")
            ->assertOk()
            ->inertiaPage()['props']['filters'];

        expect($filters['scope'])->toBe($echo, "scope={$scope} on {$path}")
            ->and($filters['mine'])->toBe($mine, "mine for scope={$scope} on {$path}")
            ->and($filters['bucket'])->toBe($bucket, "bucket for scope={$scope} on {$path}");
    }
})->with('scope views')->group('tasks', 'scope');

it('lets a date scope\'s bucket win over a bucket chip sent beside it', function () {
    $filters = $this->actingAs($this->admin)
        ->get('/admin/tasks?scope=due-today&bucket=completed')
        ->assertOk()
        ->inertiaPage()['props']['filters'];

    expect($filters['bucket'])->toBe('due_today')->and($filters['mine'])->toBeTrue();

    // `scope=mine` has no bucket of its own, so a bucket beside it — Open included — survives.
    foreach (['completed', 'open'] as $bucket) {
        $filters = $this->actingAs($this->admin)->get("/admin/tasks?scope=mine&bucket={$bucket}")
            ->inertiaPage()['props']['filters'];

        expect($filters['bucket'])->toBe($bucket)->and($filters['scope'])->toBe('mine');
    }
})->group('tasks', 'scope');

it('makes the date scopes personal for an admin, who can otherwise see everything', function () {
    $overdue = scopeListIds($this->actingAs($this->admin)->get('/admin/tasks?scope=overdue')->inertiaPage()['props']);
    $agency = scopeListIds($this->actingAs($this->admin)->get('/admin/tasks?bucket=overdue')->inertiaPage()['props']);

    expect($overdue)->toContain($this->adminLate->id)
        ->not->toContain($this->yaseenLate->id)
        ->not->toContain($this->tapuLate->id)
        ->and($agency)->toContain($this->yaseenLate->id, $this->tapuLate->id);

    $today = scopeListIds($this->actingAs($this->yaseen)->get('/employee/tasks?scope=due-today')->inertiaPage()['props']);

    expect($today)->toContain($this->yaseenToday->id)->not->toContain($this->yaseenLate->id);
})->group('tasks', 'scope');

it('never shows a task outside Task::visibleTo under any scope', function () {
    $visible = Task::query()->visibleTo($this->yaseen)->pluck('id')->all();

    foreach (['mine', 'due-today', 'overdue'] as $scope) {
        $ids = scopeListIds($this->actingAs($this->yaseen)
            ->get("/employee/tasks?scope={$scope}&archived=1")
            ->assertOk()
            ->inertiaPage()['props']);

        expect(array_diff($ids, $visible))->toBe([], "scope={$scope} leaked a task")
            ->and($ids)->not->toContain($this->tapuLate->id)
            ->not->toContain($this->adminLate->id);
    }
})->group('tasks', 'scope', 'permissions');

it('lands an employee on the tasks the old My Tasks page listed', function () {
    // What MyTaskController used to list: the Open bucket of the `mine` filter, capped at 100.
    $old = app(TaskService::class)
        ->query($this->yaseen, ['mine' => true, 'bucket' => 'open', 'as_of' => Carbon::today()])
        ->limit(100)
        ->pluck('id')
        ->sort()->values()->all();

    $this->actingAs($this->yaseen)->get('/employee/my-tasks')->assertRedirect('/employee/tasks?scope=mine&bucket=open');

    $new = collect(scopeListIds($this->actingAs($this->yaseen)->get('/employee/tasks?scope=mine&bucket=open')
        ->assertOk()->inertiaPage()['props']))->sort()->values()->all();

    // Exactly the same ids: `bucket=open` survives beside `scope=mine`, which has no bucket of
    // its own to override it with.
    expect($old)->not->toBeEmpty()->and($new)->toBe($old);
})->group('tasks', 'scope', 'permissions');

dataset('my-tasks redirects', [
    'bare' => ['', 'scope=mine&bucket=open'],
    'due today' => ['?bucket=due_today', 'scope=due-today'],
    'overdue' => ['?bucket=overdue', 'scope=overdue'],
    'open' => ['?bucket=open', 'scope=mine&bucket=open'],
    'other bucket' => ['?bucket=completed', 'scope=mine&bucket=completed'],
    'unknown bucket' => ['?bucket=not-a-bucket', 'scope=mine&bucket=open'],
    'extra params kept' => ['?bucket=overdue&project_id=7&group_by=project', 'scope=overdue&project_id=7&group_by=project'],
    'extra params with other bucket' => ['?status=todo&bucket=in_review', 'scope=mine&bucket=in_review&status=todo'],
]);

it('redirects the old admin My Tasks URLs into the Tasks List', function (string $from, string $to) {
    $this->actingAs($this->admin)->get("/admin/my-tasks{$from}")->assertStatus(302)->assertRedirect("/admin/tasks?{$to}");
})->with('my-tasks redirects')->group('tasks', 'scope');

it('redirects the old employee My Tasks URLs into the Tasks List', function (string $from, string $to) {
    $this->actingAs($this->yaseen)->get("/employee/my-tasks{$from}")->assertStatus(302)->assertRedirect("/employee/tasks?{$to}");
})->with('my-tasks redirects')->group('tasks', 'scope');

it('keeps the old URLs refused to anybody the Tasks List refuses', function () {
    $accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();

    $this->actingAs($accountant)->get('/admin/my-tasks')->assertForbidden();
    $this->actingAs($this->yaseen)->get('/admin/my-tasks')->assertForbidden();
    $this->actingAs($this->admin)->get('/employee/my-tasks')->assertForbidden();
})->group('tasks', 'scope', 'permissions');
