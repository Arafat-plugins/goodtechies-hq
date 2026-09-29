<?php

use App\Events\TaskChanged;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskService;
use App\Support\RoleName;
use App\Support\TaskStatus;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| F1 task-live-update — a committed task change rings the people who can see it
|--------------------------------------------------------------------------
|
| The Tasks screens used to re-read every twenty seconds whether anything had
| happened or not. Now a task mutation broadcasts ONE `TaskChanged` after its
| transaction commits, on the private `tasks.{user}` channel of every person who
| may see the task before or after the change, and the screens re-read when it
| arrives. The frame is `{task_id, kind}` and nothing else — the same doorbell
| rule as `BroadcastsTaskStatus`.
|
| These tests listen through the REAL dispatcher, for the reason
| `TaskBroadcastTest` gives at length: `Event::fake()` records a dispatch the
| moment it happens and cannot tell a deferred one from an eager one, so it
| cannot see a broadcast that escaped a rolled-back transaction.
|
| Constants and helpers are global in Pest, so everything here is TASK_LIVE_.
|
*/

const TASK_LIVE_SOCKET_ID = '24680.13579';

/**
 * Every `TaskChanged` fired from here on, through the real dispatcher.
 *
 * @param  list<TaskChanged>  $into
 */
function TASK_LIVE_collect(array &$into): void
{
    Event::listen(TaskChanged::class, function (TaskChanged $event) use (&$into): void {
        $into[] = $event;
    });
}

/**
 * The channel names an event goes out on, as strings, sorted.
 *
 * @return list<string>
 */
function TASK_LIVE_channels(TaskChanged $event): array
{
    $names = array_map(fn (PrivateChannel $channel): string => $channel->name, $event->broadcastOn());
    sort($names);

    return $names;
}

function TASK_LIVE_channel(User $user): string
{
    return 'private-tasks.'.$user->getKey();
}

beforeEach(function () {
    $this->seed();

    $this->tasks = app(TaskService::class);
    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();

    // One of Tapu's own tasks that Yaseen is NOT on: Tapu sees it, Yaseen does not, and the
    // Accountant holds no tasks.* key at all.
    $this->task = Task::query()
        ->forEmployee($this->tapu->employee)
        ->whereDoesntHave('assignees', fn ($q) => $q->where('employees.id', $this->yaseen->employee->id))
        ->notArchived()
        ->where('status', TaskStatus::Backlog->value)
        ->orderBy('id')
        ->first()
        ?? Task::query()
            ->forEmployee($this->tapu->employee)
            ->whereDoesntHave('assignees', fn ($q) => $q->where('employees.id', $this->yaseen->employee->id))
            ->notArchived()
            ->orderBy('id')
            ->firstOrFail();

    // Everyone who sees every task: active Admins and Managers.
    $this->seeAll = User::query()
        ->where('status', 'active')
        ->whereHas('employee.role', fn ($q) => $q->whereIn('name', [RoleName::ADMIN->value, RoleName::MANAGER->value]))
        ->get();
});

/*
|--------------------------------------------------------------------------
| The event itself
|--------------------------------------------------------------------------
*/

it('is a queued broadcast that waits for the commit', function () {
    $interfaces = class_implements(TaskChanged::class);

    expect($interfaces)->toHaveKey(ShouldBroadcast::class)
        ->and($interfaces)->toHaveKey(ShouldDispatchAfterCommit::class);
})->group('polish', 'realtime');

/*
|--------------------------------------------------------------------------
| Assign and unassign
|--------------------------------------------------------------------------
*/

it('broadcasts an assignment once, after commit, to the right channels, with ids only', function () {
    $collected = [];
    TASK_LIVE_collect($collected);

    $ids = $this->task->assignees()->pluck('employees.id')->map('intval')->all();

    $this->tasks->syncAssignees($this->admin, $this->task, [...$ids, (int) $this->yaseen->employee->id]);

    expect($collected)->toHaveCount(1);

    $event = $collected[0];
    $payload = $event->broadcastWith();
    $channels = TASK_LIVE_channels($event);

    expect(array_keys($payload))->toBe(['task_id', 'kind'])
        ->and($payload)->toBe(['task_id' => (int) $this->task->getKey(), 'kind' => 'assigned'])
        ->and($event->broadcastAs())->toBe('task.changed')
        ->and($channels)->toContain(TASK_LIVE_channel($this->yaseen))
        ->and($channels)->toContain(TASK_LIVE_channel($this->tapu))
        ->and($channels)->not->toContain(TASK_LIVE_channel($this->accountant));

    foreach ($this->seeAll as $user) {
        expect($channels)->toContain(TASK_LIVE_channel($user));
    }

    $json = json_encode($payload);

    expect($json)->not->toContain($this->task->title)
        ->and($json)->not->toContain((string) $this->yaseen->name);
})->group('polish', 'realtime');

it('broadcasts nothing when the assignment rolls back', function () {
    $collected = [];
    TASK_LIVE_collect($collected);

    $ids = $this->task->assignees()->pluck('employees.id')->map('intval')->all();

    try {
        DB::transaction(function () use ($ids): void {
            $this->tasks->syncAssignees($this->admin, $this->task, [...$ids, (int) $this->yaseen->employee->id]);

            throw new RuntimeException('something later in the request failed');
        });
    } catch (RuntimeException) {
        // The caller's problem. Ours is that nobody was told about an assignment that did not happen.
    }

    expect($collected)->toBe([]);

    // …and the collector is known to be listening: the same change, committed, rings once.
    $this->tasks->syncAssignees($this->admin, $this->task->refresh(), [...$ids, (int) $this->yaseen->employee->id]);

    expect($collected)->toHaveCount(1);
})->group('polish', 'realtime');

it('reaches the person who was just unassigned', function () {
    $ids = $this->task->assignees()->pluck('employees.id')->map('intval')->all();
    $this->tasks->syncAssignees($this->admin, $this->task, [...$ids, (int) $this->yaseen->employee->id]);

    $collected = [];
    TASK_LIVE_collect($collected);

    // Yaseen comes off again. After the change he can no longer see the task, and he is still
    // told — the removal is the one thing his screen needs to hear about it.
    $this->tasks->syncAssignees($this->admin, $this->task->refresh(), $ids);

    expect($collected)->toHaveCount(1)
        ->and($collected[0]->broadcastWith())->toBe(['task_id' => (int) $this->task->getKey(), 'kind' => 'unassigned'])
        ->and(TASK_LIVE_channels($collected[0]))->toContain(TASK_LIVE_channel($this->yaseen));
})->group('polish', 'realtime');

it('does not broadcast an assignment that changes nothing', function () {
    $collected = [];
    TASK_LIVE_collect($collected);

    $ids = $this->task->assignees()->orderByPivot('is_primary', 'desc')->pluck('employees.id')->map('intval')->all();

    $this->tasks->syncAssignees($this->admin, $this->task, $ids);

    expect($collected)->toBe([]);
})->group('polish', 'realtime');

/*
|--------------------------------------------------------------------------
| Who never hears about it
|--------------------------------------------------------------------------
*/

it('never names a user who cannot see the task', function () {
    $collected = [];
    TASK_LIVE_collect($collected);

    $this->tasks->update($this->admin, $this->task, ['priority' => 'urgent']);

    $channels = TASK_LIVE_channels($collected[0]);

    // Yaseen holds tasks.view and is not assigned; the Accountant holds no tasks key at all.
    expect($channels)->not->toContain(TASK_LIVE_channel($this->yaseen))
        ->and($channels)->not->toContain(TASK_LIVE_channel($this->accountant))
        ->and($channels)->toContain(TASK_LIVE_channel($this->tapu));

    // And the whole list is exactly the people the policy lets see it.
    $expected = User::query()->get()
        ->filter(fn (User $user): bool => $user->can('view', $this->task))
        ->map(fn (User $user): string => TASK_LIVE_channel($user))
        ->sort()
        ->values()
        ->all();

    expect($channels)->toBe($expected);
})->group('polish', 'realtime');

it('lets a person join their own tasks channel and nobody else\'s', function () {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-app-key',
        'broadcasting.connections.reverb.secret' => 'test-app-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app-id',
    ]);

    // See BroadcastAuthTest: the channels were registered at boot against the `null` driver.
    require base_path('routes/channels.php');

    $auth = fn (User $as, User $channelOf) => $this->actingAs($as)->postJson('/broadcasting/auth', [
        'socket_id' => TASK_LIVE_SOCKET_ID,
        'channel_name' => TASK_LIVE_channel($channelOf),
    ]);

    $auth($this->tapu, $this->tapu)->assertOk();
    $auth($this->admin, $this->admin)->assertOk();

    // Not even the widest role on somebody else's.
    $auth($this->yaseen, $this->tapu)->assertForbidden();
    $auth($this->admin, $this->tapu)->assertForbidden();
    $auth($this->tapu, $this->admin)->assertForbidden();

    // No tasks.view, no tasks channel — even their own.
    $auth($this->accountant, $this->accountant)->assertForbidden();

    $this->actingAs($this->tapu)->postJson('/broadcasting/auth', [
        'socket_id' => TASK_LIVE_SOCKET_ID,
        'channel_name' => 'private-tasks.999999',
    ])->assertForbidden();
})->group('polish', 'realtime');

/*
|--------------------------------------------------------------------------
| The acting tab is not echoed
|--------------------------------------------------------------------------
*/

it('carries the X-Socket-ID of the request that made the change', function () {
    $collected = [];
    TASK_LIVE_collect($collected);

    $this->actingAs($this->admin)
        ->post('/admin/tasks/'.$this->task->getKey().'/archive', [], ['X-Socket-ID' => TASK_LIVE_SOCKET_ID])
        ->assertRedirect();

    expect($collected)->toHaveCount(1)
        ->and($collected[0]->socket)->toBe(TASK_LIVE_SOCKET_ID)
        ->and($collected[0]->broadcastWith()['kind'])->toBe('archived');
})->group('polish', 'realtime');

/*
|--------------------------------------------------------------------------
| One broadcast per change, for every other kind
|--------------------------------------------------------------------------
*/

it('broadcasts exactly one frame per change', function (string $expectedKind, Closure $change) {
    $collected = [];
    TASK_LIVE_collect($collected);

    $taskId = $change->call($this);

    expect($collected)->toHaveCount(1)
        ->and($collected[0]->broadcastWith())->toBe(['task_id' => $taskId, 'kind' => $expectedKind]);
})->with([
    'priority' => ['updated', function (): int {
        $this->tasks->update($this->admin, $this->task, ['priority' => 'urgent']);

        return (int) $this->task->getKey();
    }],
    'due date' => ['updated', function (): int {
        $this->tasks->update($this->admin, $this->task, ['due_date' => now()->addDays(9)->toDateString()]);

        return (int) $this->task->getKey();
    }],
    'title' => ['updated', function (): int {
        $this->tasks->update($this->admin, $this->task, ['title' => 'A title nobody else has']);

        return (int) $this->task->getKey();
    }],
    'tags' => ['updated', function (): int {
        $tag = Tag::query()
            ->where(fn ($q) => $q->whereNull('project_id')->orWhere('project_id', $this->task->project_id))
            ->orderBy('id')
            ->firstOrFail();

        $this->tasks->update($this->admin, $this->task, ['tag_ids' => [$tag->getKey()]]);

        return (int) $this->task->getKey();
    }],
    'checklist' => ['updated', function (): int {
        $this->tasks->addChecklistItem($this->admin, $this->task, 'Check the redirects');

        return (int) $this->task->getKey();
    }],
    'status' => ['status', function (): int {
        $to = $this->task->status === TaskStatus::Backlog ? TaskStatus::Todo : TaskStatus::Backlog;
        $this->tasks->transition($this->admin, $this->task, $to);

        return (int) $this->task->getKey();
    }],
    'create' => ['created', function (): int {
        $task = $this->tasks->create(
            $this->admin,
            ['title' => 'Born live', 'project_id' => $this->task->project_id, 'priority' => 'medium'],
            [(int) $this->tapu->employee->id],
        );

        return (int) $task->getKey();
    }],
    'delete' => ['deleted', function (): int {
        $this->tasks->delete($this->admin, $this->task);

        return (int) $this->task->getKey();
    }],
    'archive' => ['archived', function (): int {
        $this->tasks->archive($this->admin, $this->task);

        return (int) $this->task->getKey();
    }],
    'restore' => ['restored', function (): int {
        $this->task->forceFill(['archived_at' => now()])->save();
        $this->tasks->unarchive($this->admin, $this->task->refresh());

        return (int) $this->task->getKey();
    }],
    'comment' => ['commented', function (): int {
        $this->actingAs($this->tapu)
            ->post('/employee/tasks/'.$this->task->getKey().'/discussion', ['body' => 'Pushed the fix.'])
            ->assertRedirect()
            ->assertSessionHas('success');

        return (int) $this->task->getKey();
    }],
])->group('polish', 'realtime');

it('does not broadcast a comment that was refused', function () {
    $collected = [];
    TASK_LIVE_collect($collected);

    // Yaseen may not see the task at all: 404, nothing written, nothing rung.
    $this->actingAs($this->yaseen)
        ->post('/employee/tasks/'.$this->task->getKey().'/discussion', ['body' => 'Not mine.'])
        ->assertNotFound();

    expect($collected)->toBe([]);
})->group('polish', 'realtime');

it('includes the new assignee when a task is created for them', function () {
    $collected = [];
    TASK_LIVE_collect($collected);

    $this->tasks->create(
        $this->admin,
        ['title' => 'For Yaseen', 'project_id' => $this->task->project_id, 'priority' => 'low'],
        [(int) $this->yaseen->employee->id],
    );

    expect(TASK_LIVE_channels($collected[0]))->toContain(TASK_LIVE_channel($this->yaseen))
        ->and(TASK_LIVE_channels($collected[0]))->not->toContain(TASK_LIVE_channel($this->tapu));
})->group('polish', 'realtime');

it('keeps the fan-out to a handful of queries', function () {
    // A small team, but a mutation must not grow a query per task or per screen. The fan-out is
    // the task's assignees, one candidate query with its two eager loads, and the policy's
    // permission read per candidate — pinned here so a change that makes it a scan fails.
    $candidates = User::query()
        ->where('status', 'active')
        ->whereHas('employee', fn ($q) => $q
            ->whereIn('employees.id', $this->task->assignees()->pluck('employees.id'))
            ->orWhereHas('role', fn ($r) => $r->whereIn('name', [RoleName::ADMIN->value, RoleName::MANAGER->value])))
        ->count();

    DB::enableQueryLog();
    DB::flushQueryLog();

    $this->tasks->viewerIds($this->task);
    $fanOut = count(DB::getQueryLog());

    DB::disableQueryLog();

    expect($fanOut)->toBe(4 + $candidates)
        ->and($candidates)->toBeLessThan(10);
})->group('polish', 'realtime');
