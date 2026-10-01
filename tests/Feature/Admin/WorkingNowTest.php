<?php

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\TaskTimerService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| "Working now" — who is timing which task, for how long (flow F3)
|--------------------------------------------------------------------------
|
| Admin → Workforce → Time and the Admin dashboard list every open task
| timer. Running-timer identities reach watchers only
| (`TimeEntryPolicy::watchLive`): the prop is ABSENT for anybody else, and
| the Time page itself is 403 outside the Admin surface.
|
| Constants and helpers are global in Pest, so everything here is WN_.
|
*/

const WN_DAY = '2026-09-14';

function WN_task(User $user): Task
{
    $task = Task::factory()->create();
    $task->assignees()->attach($user->employee->id, ['is_primary' => true]);

    return $task;
}

beforeEach(function () {
    Carbon::setTestNow(WN_DAY.' 09:00:00');

    $this->seed();

    TimeEntry::query()->delete();
    AttendanceRecord::query()->delete();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
});

afterEach(function () {
    Carbon::setTestNow();
});

it('lists every open task timer for an Admin on the Time page, running and paused', function () {
    $a = WN_task($this->yaseen);
    $b = WN_task($this->tapu);

    $this->actingAs($this->yaseen)->post("/tasks/{$a->id}/timer", ['clock_in' => true])->assertRedirect();
    $this->actingAs($this->tapu)->post("/tasks/{$b->id}/timer", ['client_uuid' => (string) Str::uuid()])->assertRedirect();

    Carbon::setTestNow(WN_DAY.' 09:42:00');
    // Both tabs alive all along (a jump with no pings is a switched-off PC — brief 028).
    TimeEntry::query()->open()->update(['last_heartbeat_at' => Carbon::now()]);
    $this->actingAs($this->yaseen)->post('/task-timer/pause')->assertRedirect();

    $rows = collect($this->actingAs($this->admin)->get('/admin/time')->assertOk()
        ->viewData('page')['props']['working_now'])->keyBy('task.id');

    expect($rows)->toHaveCount(2);

    $yaseen = $rows[$a->id];
    expect($yaseen['employee'])->toMatchArray([
        'id' => $this->yaseen->employee->id,
        'name' => $this->yaseen->name,
    ])
        ->and($yaseen['employee']['initials'])->toBeString()->not->toBe('')
        ->and($yaseen['task'])->toMatchArray(['id' => $a->id, 'title' => $a->title, 'href' => '/admin/tasks/'.$a->id])
        ->and($yaseen['project'])->toMatchArray(['id' => $a->project_id, 'name' => $a->project?->name])
        ->and($yaseen['started_at'])->toBe(Carbon::parse(WN_DAY.' 09:00:00')->toIso8601String())
        ->and($yaseen['paused'])->toBeTrue()
        ->and($yaseen['elapsed_seconds'])->toBe(42 * 60);

    expect($rows[$b->id]['paused'])->toBeFalse()
        ->and($rows[$b->id]['employee']['id'])->toBe($this->tapu->employee->id);

    // Stopping takes the row away.
    $this->actingAs($this->tapu)->post('/task-timer/stop')->assertRedirect();

    $after = $this->actingAs($this->admin)->get('/admin/time')->viewData('page')['props']['working_now'];
    expect(collect($after)->pluck('task.id')->all())->toBe([$a->id]);
});

it('answers an empty list when nobody is timing, rather than leaving the prop out', function () {
    $props = $this->actingAs($this->admin)->get('/admin/time')->assertOk()->viewData('page')['props'];

    expect($props)->toHaveKey('working_now')
        ->and($props['working_now'])->toBe([]);
});

it('refuses the Time page to an employee', function () {
    $this->actingAs($this->yaseen)->get('/admin/time')->assertForbidden();
    $this->actingAs($this->tapu)->get('/admin/time')->assertForbidden();
});

it('puts Working now on the dashboard for a watcher only', function () {
    $task = WN_task($this->yaseen);
    $this->actingAs($this->yaseen)->post("/tasks/{$task->id}/timer", ['clock_in' => true])->assertRedirect();

    $admin = $this->actingAs($this->admin)->get('/admin/dashboard')->assertOk()->viewData('page')['props'];
    expect($admin)->toHaveKey('workingNow')
        ->and(collect($admin['workingNow'])->pluck('task.id')->all())->toBe([$task->id]);

    // Only the ADMIN role reaches this surface. Take `attendance.manage_others` off it — a
    // combination nobody seeded — and the dashboard still opens, with the names of who is
    // working not in it at all: the prop follows `watchLive`, not the surface.
    $role = $this->admin->employee->role;
    $role->permissions()->detach(
        $role->permissions()->where('key', 'attendance.manage_others')->pluck('permissions.id')->all(),
    );
    $admin = User::whereKey($this->admin->id)->firstOrFail();

    $props = $this->actingAs($admin)->get('/admin/dashboard')->assertOk()->viewData('page')['props'];
    expect($props)->not->toHaveKey('workingNow')
        ->and(app(TaskTimerService::class)->workingNow($admin))->toBeNull();

    // And every other surface is refused the page outright.
    $this->actingAs($this->yaseen)->get('/admin/dashboard')->assertForbidden();
});

it('reads Working now in a fixed number of queries, however many people are timing', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(TaskTimerService::class)->workingNow($this->admin);
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    $one = WN_task($this->yaseen);
    $this->actingAs($this->yaseen)->post("/tasks/{$one->id}/timer", ['clock_in' => true])->assertRedirect();
    $this->admin = User::whereKey($this->admin->id)->firstOrFail();
    $count();
    $single = $count();

    foreach (Employee::factory()->count(5)->create() as $employee) {
        TimeEntry::factory()->forEmployee($employee)->onTask(WN_task($employee->user))->running()->create();
    }

    expect(count(app(TaskTimerService::class)->workingNow($this->admin)))->toBe(6)
        ->and($count())->toBe($single)
        ->and($single)->toBeLessThanOrEqual(2);
});
