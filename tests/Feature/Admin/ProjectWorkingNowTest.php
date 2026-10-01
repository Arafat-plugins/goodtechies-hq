<?php

use App\Models\AttendanceRecord;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| "Working now" on the Admin Projects pages (flow F3)
|--------------------------------------------------------------------------
|
| The list carries `workingNowByProject` (open task timers grouped by project
| id, one statement for the page); the detail page carries `workingNow` for
| that project alone. Both are ABSENT without `watchLive`, and the Projects
| pages are Admin-surface only.
|
| Constants and helpers are global in Pest, so everything here is PWN_.
|
*/

const PWN_DAY = '2026-09-14';

function PWN_task(User $user, Project $project): Task
{
    $task = Task::factory()->create(['project_id' => $project->getKey()]);
    $task->assignees()->attach($user->employee->id, ['is_primary' => true]);

    return $task;
}

/** @return int statements run by one GET */
function PWN_queries(User $user, string $url): int
{
    $count = 0;
    // A fresh instance each time, so the role's permission keys cached on the model by an
    // earlier request do not make one measurement cheaper than the other.
    $user = $user->fresh();
    DB::listen(function () use (&$count): void {
        $count++;
    });
    test()->actingAs($user)->get($url)->assertOk();
    DB::getEventDispatcher()->forget(QueryExecuted::class);

    return $count;
}

beforeEach(function () {
    Carbon::setTestNow(PWN_DAY.' 09:00:00');

    $this->seed();

    TimeEntry::query()->delete();
    AttendanceRecord::query()->delete();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->faruk = User::where('email', 'faruk@goodtechies.test')->firstOrFail();

    [$this->busy, $this->other, $this->idle] = Project::query()->orderBy('id')->take(3)->get()->all();
});

afterEach(function () {
    Carbon::setTestNow();
});

it('gives the admin the open timers grouped by project on the list and none for an idle project', function () {
    $a = PWN_task($this->tapu, $this->busy);
    $b = PWN_task($this->yaseen, $this->busy);
    PWN_task($this->yaseen, $this->other); // assigned, never started

    $this->actingAs($this->tapu)->post("/tasks/{$a->id}/timer", ['client_uuid' => (string) Str::uuid()])->assertRedirect();
    $this->actingAs($this->yaseen)->post("/tasks/{$b->id}/timer", ['clock_in' => true])->assertRedirect();

    Carbon::setTestNow(PWN_DAY.' 10:12:00');
    // Both tabs alive all along (a jump with no pings is a switched-off PC — brief 028).
    TimeEntry::query()->open()->update(['last_heartbeat_at' => Carbon::now()]);

    $props = $this->actingAs($this->admin)->get('/admin/projects')->assertOk()->viewData('page')['props'];

    expect($props)->toHaveKey('workingNowByProject');
    $grouped = $props['workingNowByProject'];

    expect(array_keys($grouped))->toBe([$this->busy->id])
        ->and(collect($grouped[$this->busy->id])->pluck('task.id')->sort()->values()->all())->toBe(collect([$a->id, $b->id])->sort()->values()->all())
        ->and($grouped)->not->toHaveKey($this->idle->id)
        ->and($grouped)->not->toHaveKey($this->other->id);

    $tapuRow = collect($grouped[$this->busy->id])->firstWhere('task.id', $a->id);
    expect($tapuRow['employee']['name'])->toBe($this->tapu->name)
        ->and($tapuRow['elapsed_seconds'])->toBe(72 * 60)
        ->and($tapuRow['paused'])->toBeFalse();

    // Stopping removes it.
    $this->actingAs($this->tapu)->post('/task-timer/stop')->assertRedirect();
    $this->actingAs($this->yaseen)->post('/task-timer/stop')->assertRedirect();

    $props = $this->actingAs($this->admin)->get('/admin/projects')->assertOk()->viewData('page')['props'];
    expect($props['workingNowByProject'])->toBe([]);

});

it('gives the project page its own working-now rows, and an empty list to an idle project', function () {
    $a = PWN_task($this->tapu, $this->busy);
    $b = PWN_task($this->yaseen, $this->other);

    $this->actingAs($this->tapu)->post("/tasks/{$a->id}/timer", ['client_uuid' => (string) Str::uuid()])->assertRedirect();
    $this->actingAs($this->yaseen)->post("/tasks/{$b->id}/timer", ['clock_in' => true])->assertRedirect();

    $busy = $this->actingAs($this->admin)->get("/admin/projects/{$this->busy->id}")->assertOk()->viewData('page')['props'];
    expect(collect($busy['workingNow'])->pluck('task.id')->all())->toBe([$a->id])
        ->and($busy['workingNow'][0]['employee']['name'])->toBe($this->tapu->name)
        ->and($busy['workingNow'][0]['task']['title'])->toBe($a->title);

    $idle = $this->actingAs($this->admin)->get("/admin/projects/{$this->idle->id}")->assertOk()->viewData('page')['props'];
    expect($idle)->toHaveKey('workingNow')
        ->and($idle['workingNow'])->toBe([]);
});

it('leaves the props out without watchLive, and keeps the Projects pages Admin-only', function () {
    $role = $this->admin->employee->role;
    $role->permissions()->detach(
        $role->permissions()->where('key', 'attendance.manage_others')->pluck('permissions.id')->all(),
    );
    $admin = User::whereKey($this->admin->id)->firstOrFail();

    expect($this->actingAs($admin)->get('/admin/projects')->assertOk()->viewData('page')['props'])
        ->not->toHaveKey('workingNowByProject')
        ->and($this->actingAs($admin)->get("/admin/projects/{$this->busy->id}")->assertOk()->viewData('page')['props'])
        ->not->toHaveKey('workingNow');

    $this->actingAs($this->tapu)->get('/admin/projects')->assertForbidden();
    $this->actingAs($this->yaseen)->get("/admin/projects/{$this->busy->id}")->assertForbidden();
});

it('costs the list the same number of queries with one timer running as with three', function () {
    $tasks = [
        PWN_task($this->tapu, $this->busy),
        PWN_task($this->yaseen, $this->other),
        PWN_task($this->faruk, $this->idle),
    ];

    $this->actingAs($this->tapu)->post("/tasks/{$tasks[0]->id}/timer", ['client_uuid' => (string) Str::uuid()])->assertRedirect();
    $one = PWN_queries($this->admin, '/admin/projects');

    $this->actingAs($this->yaseen)->post("/tasks/{$tasks[1]->id}/timer", ['clock_in' => true])->assertRedirect();
    $this->actingAs($this->faruk)->post("/tasks/{$tasks[2]->id}/timer", ['clock_in' => true])->assertRedirect();
    $three = PWN_queries($this->admin, '/admin/projects');

    expect($three)->toBe($one)
        ->and(count($this->actingAs($this->admin)->get('/admin/projects')->viewData('page')['props']['workingNowByProject']))->toBe(3);
});
