<?php

use App\Models\AttendanceRecord;
use App\Models\PayrollPeriod;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\PayrollService;
use App\Services\TaskTimerService;
use App\Services\TimerService;
use App\Services\TimesheetService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| A timer whose browser went away stops at its last heartbeat (brief 028)
|--------------------------------------------------------------------------
|
| The client: "after topus pc is of it still counted". The watchdog that
| enforces the heartbeat timeout is a scheduled command, and the Windows
| start script ran no scheduler — so nothing ever closed the entry. These
| tests run with NO watchdog: the request sweep, the stop itself and the
| one-off repair are what must get the ending right.
|
| Constants and helpers are global in Pest, so everything here is STALE_.
|
*/

/** A Monday, which every seeded schedule works. */
const STALE_DAY = '2026-09-14';

function STALE_task(User ...$users): Task
{
    $task = Task::factory()->create();

    foreach ($users as $index => $user) {
        $task->assignees()->attach($user->employee->id, ['is_primary' => $index === 0]);
    }

    return $task;
}

/**
 * The card for `$task` on a board payload.
 *
 * @param  array<string, mixed>  $board
 * @return array<string, mixed>
 */
function STALE_card(array $board, Task $task): array
{
    foreach ($board['columns'] as $column) {
        foreach ($column['tasks'] as $card) {
            if ($card['id'] === $task->id) {
                return $card;
            }
        }
    }

    throw new RuntimeException('card not on the board');
}

/**
 * Every figure that is somebody's hours or pay for `$user` on STALE_DAY — read fresh.
 *
 * @return array<string, mixed>
 */
function STALE_hours(User $user): array
{
    $employee = $user->employee->fresh();
    $day = Carbon::parse(STALE_DAY);
    $attendance = app()->make(AttendanceService::class);
    $record = AttendanceRecord::query()->where('employee_id', $employee->id)->whereDate('date', STALE_DAY)->first();
    $period = PayrollPeriod::query()->where('month', $day->copy()->startOfMonth()->toDateString())->first()
        ?? app(PayrollService::class)->createDraft(null, $day);
    $week = app()->make(TimesheetService::class)->week($employee, $day);

    return [
        'tracked_minutes' => $attendance->trackedMinutes($employee, $day),
        'worked_minutes' => $record?->workedMinutes(),
        'attendance_status' => $record?->status?->value,
        'counted_seconds' => app()->make(TimerService::class)->countedSecondsOn($employee, $day),
        'summary' => DB::table('daily_work_summary')
            ->where('employee_id', $employee->id)
            ->where('work_date', STALE_DAY)
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all(),
        'timesheet' => [$week['totals']['counted_seconds'] ?? null, $week['totals']['pending_seconds'] ?? null, count($week['rows'] ?? [])],
        'payroll_unpaid_days' => app(PayrollService::class)->unpaidDaysIn($employee, $period),
        'payroll_payable_days' => app(PayrollService::class)->payableDaysIn($employee, $period),
    ];
}

beforeEach(function () {
    Carbon::setTestNow(STALE_DAY.' 09:00:00');

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

it('closes an office timer whose browser went away on the next board load, at its last heartbeat, with no watchdog', function () {
    $task = STALE_task($this->yaseen);

    $this->actingAs($this->yaseen)->post("/tasks/{$task->id}/timer", ['clock_in' => true])->assertRedirect();

    // The tab is alive until 09:10, pinging once a minute.
    foreach (range(1, 10) as $minute) {
        Carbon::setTestNow(Carbon::parse(STALE_DAY.' 09:00:00')->addMinutes($minute));
        $this->actingAs($this->yaseen)->postJson('/task-timer/heartbeat')->assertOk()->assertJsonPath('running.task_id', $task->id);
    }

    // The PC is switched off. Twenty minutes later somebody — anybody — opens the board.
    Carbon::setTestNow(STALE_DAY.' 09:30:00');

    $card = STALE_card($this->actingAs($this->admin)->get('/admin/tasks/board')->assertOk()->viewData('page')['props']['board'], $task);

    $entry = TimeEntry::query()->sole();

    expect($entry->isStopped())->toBeTrue()
        ->and($entry->ended_at->equalTo(Carbon::parse(STALE_DAY.' 09:10:00')))->toBeTrue()
        ->and($entry->duration_seconds)->toBe(600)
        ->and($entry->isFlagged())->toBeTrue()
        ->and((int) $task->fresh()->tracked_seconds)->toBe(600)
        // The very response that ran the sweep already shows the truth.
        ->and($card['running_timers'])->toBe([])
        ->and($card['tracked_seconds'])->toBe(600)
        ->and(app(TaskTimerService::class)->workingNow($this->admin))->toBe([]);

    $mine = STALE_card($this->actingAs($this->yaseen)->get('/employee/tasks/board')->assertOk()->viewData('page')['props']['board'], $task);

    expect($mine['my_timer'])->toBeNull()
        ->and($mine['tracked_seconds'])->toBe(600);
});

it('ends the entry at its last heartbeat when the person clocks out three hours later', function () {
    $task = STALE_task($this->yaseen);
    $employee = $this->yaseen->employee;

    app(TaskTimerService::class)->start($employee, $task, null, clockIn: true);

    Carbon::setTestNow(STALE_DAY.' 09:05:00');
    app(TimerService::class)->heartbeat(TimeEntry::query()->open()->sole());

    // Straight at the service: no request, so no request sweep — the clock-out alone.
    Carbon::setTestNow(STALE_DAY.' 12:05:00');
    $record = app(AttendanceService::class)->clockOut($employee->fresh());

    $entry = TimeEntry::query()->sole();

    expect($entry->ended_at->equalTo(Carbon::parse(STALE_DAY.' 09:05:00')))->toBeTrue()
        ->and($entry->duration_seconds)->toBe(300)
        ->and($entry->isFlagged())->toBeTrue()
        ->and((int) $task->fresh()->tracked_seconds)->toBe(300)
        // The day itself is the clock's, untouched: 09:00 → 12:05.
        ->and($record->clock_out->equalTo(Carbon::parse(STALE_DAY.' 12:05:00')))->toBeTrue();
});

it('ends the old entry at its last heartbeat when ▶ on another task switches away from a dead one', function () {
    $a = STALE_task($this->yaseen);
    $b = STALE_task($this->yaseen);
    $employee = $this->yaseen->employee;

    app(TaskTimerService::class)->start($employee, $a, null, clockIn: true);

    Carbon::setTestNow(STALE_DAY.' 09:04:00');
    app(TimerService::class)->heartbeat(TimeEntry::query()->open()->sole());

    Carbon::setTestNow(STALE_DAY.' 15:00:00');
    app(TaskTimerService::class)->start($employee->fresh(), $b, null, clockIn: false);

    $old = TimeEntry::query()->where('task_id', $a->id)->sole();

    expect($old->ended_at->equalTo(Carbon::parse(STALE_DAY.' 09:04:00')))->toBeTrue()
        ->and($old->duration_seconds)->toBe(240)
        ->and(TimeEntry::query()->where('task_id', $b->id)->sole()->isRunning())->toBeTrue();
});

it('still ends a live entry where the person stops it', function () {
    $task = STALE_task($this->yaseen);

    $this->actingAs($this->yaseen)->post("/tasks/{$task->id}/timer", ['clock_in' => true])->assertRedirect();

    Carbon::setTestNow(STALE_DAY.' 09:03:00');
    $this->actingAs($this->yaseen)->postJson('/task-timer/heartbeat')->assertOk();

    Carbon::setTestNow(STALE_DAY.' 09:06:30');
    $this->actingAs($this->yaseen)->post('/task-timer/stop')->assertRedirect();

    $entry = TimeEntry::query()->sole();

    expect($entry->ended_at->equalTo(Carbon::parse(STALE_DAY.' 09:06:30')))->toBeTrue()
        ->and($entry->isFlagged())->toBeFalse();
});

it('sweeps at most once a minute, app-wide', function () {
    $task = STALE_task($this->yaseen);
    $other = STALE_task($this->tapu);

    // Two dead entries, neither of them the requester's — so only the sweep can close them.
    $first = TimeEntry::factory()->forEmployee($this->yaseen->employee)->onTask($task)
        ->running(Carbon::parse(STALE_DAY.' 08:00:00'))->heartbeatAt(Carbon::parse(STALE_DAY.' 08:30:00'))
        ->state(['counts_toward_hours' => false])->create();

    Carbon::setTestNow(STALE_DAY.' 09:00:00');
    $this->actingAs($this->admin)->get('/profile')->assertOk();

    expect($first->fresh()->isStopped())->toBeTrue()
        ->and($first->fresh()->ended_at->equalTo(Carbon::parse(STALE_DAY.' 08:30:00')))->toBeTrue();

    $second = TimeEntry::factory()->forEmployee($this->tapu->employee)->onTask($other)
        ->running(Carbon::parse(STALE_DAY.' 08:00:00'))->heartbeatAt(Carbon::parse(STALE_DAY.' 08:20:00'))
        ->create();

    // Inside the window: the sweep does not run again.
    Carbon::setTestNow(STALE_DAY.' 09:00:40');
    $this->actingAs($this->admin)->get('/profile')->assertOk();

    expect($second->fresh()->isOpen())->toBeTrue();

    // A minute on, it does.
    Carbon::setTestNow(STALE_DAY.' 09:01:01');
    $this->actingAs($this->admin)->get('/profile')->assertOk();

    expect($second->fresh()->isStopped())->toBeTrue()
        ->and($second->fresh()->ended_at->equalTo(Carbon::parse(STALE_DAY.' 08:20:00')))->toBeTrue();
});

it('does not sweep for a guest', function () {
    $entry = TimeEntry::factory()->forEmployee($this->tapu->employee)->onTask(STALE_task($this->tapu))
        ->running(Carbon::parse(STALE_DAY.' 08:00:00'))->heartbeatAt(Carbon::parse(STALE_DAY.' 08:20:00'))
        ->create();

    $this->get('/login')->assertOk();

    expect($entry->fresh()->isOpen())->toBeTrue();
});

it('repairs a closed breakdown row that ran past its last heartbeat, leaves a counting row alone, and is idempotent', function () {
    $officeTask = STALE_task($this->yaseen);
    $remoteTask = STALE_task($this->tapu);

    // The shape the bug left behind: ▶ at 09:00, last ping 10:00, closed the next morning.
    $broken = TimeEntry::factory()->forEmployee($this->yaseen->employee)->onTask($officeTask)->state([
        'started_at' => Carbon::parse(STALE_DAY.' 09:00:00'),
        'work_date' => STALE_DAY,
        'last_heartbeat_at' => Carbon::parse(STALE_DAY.' 10:00:00'),
        'ended_at' => Carbon::parse('2026-09-15 03:11:00'),
        'duration_seconds' => 65460,
        'counts_toward_hours' => false,
    ])->create();

    // A healthy breakdown row on the same task: ended at its last ping.
    $healthy = TimeEntry::factory()->forEmployee($this->yaseen->employee)->onTask($officeTask)->state([
        'started_at' => Carbon::parse(STALE_DAY.' 11:00:00'),
        'work_date' => STALE_DAY,
        'last_heartbeat_at' => Carbon::parse(STALE_DAY.' 11:30:00'),
        'ended_at' => Carbon::parse(STALE_DAY.' 11:30:30'),
        'duration_seconds' => 1830,
        'counts_toward_hours' => false,
    ])->create();

    // The same shape on somebody's HOURS — pay reads it, so the repair must not.
    $counting = TimeEntry::factory()->forEmployee($this->tapu->employee)->onTask($remoteTask)->state([
        'started_at' => Carbon::parse(STALE_DAY.' 09:00:00'),
        'work_date' => STALE_DAY,
        'last_heartbeat_at' => Carbon::parse(STALE_DAY.' 10:00:00'),
        'ended_at' => Carbon::parse('2026-09-15 03:11:00'),
        'duration_seconds' => 65460,
        'counts_toward_hours' => true,
    ])->create();

    $healthyBefore = $healthy->fresh()->toArray();
    $countingBefore = $counting->fresh()->toArray();

    Carbon::setTestNow('2026-09-15 09:00:00');

    expect(Artisan::call('hq:repair-task-timer-overruns'))->toBe(0);

    $broken->refresh();

    expect($broken->ended_at->equalTo(Carbon::parse(STALE_DAY.' 10:00:00')))->toBeTrue()
        ->and($broken->duration_seconds)->toBe(3600)
        ->and($broken->isFlagged())->toBeTrue()
        ->and((int) $officeTask->fresh()->tracked_seconds)->toBe(3600 + 1830)
        ->and($healthy->fresh()->toArray())->toBe($healthyBefore)
        ->and($counting->fresh()->toArray())->toBe($countingBefore);

    $after = TimeEntry::query()->orderBy('id')->get()->toArray();
    $totals = DB::table('tasks')->orderBy('id')->pluck('tracked_seconds', 'id')->all();

    Artisan::call('hq:repair-task-timer-overruns');

    expect(TimeEntry::query()->orderBy('id')->get()->toArray())->toBe($after)
        ->and(DB::table('tasks')->orderBy('id')->pluck('tracked_seconds', 'id')->all())->toBe($totals);
});

it('moves no attendance, hours or payroll figure when the sweep and the repair run', function () {
    $task = STALE_task($this->yaseen);
    $remoteTask = STALE_task($this->tapu);

    // Yaseen's office day: clocked in 09:00, a task timer whose browser died at 09:20.
    $this->actingAs($this->yaseen)->post("/tasks/{$task->id}/timer", ['clock_in' => true])->assertRedirect();
    TimeEntry::query()->open()->sole()->forceFill(['last_heartbeat_at' => Carbon::parse(STALE_DAY.' 09:20:00')])->save();

    // An older breakdown row that was closed hours past its last ping.
    TimeEntry::factory()->forEmployee($this->yaseen->employee)->onTask($task)->state([
        'started_at' => Carbon::parse(STALE_DAY.' 07:00:00'),
        'work_date' => STALE_DAY,
        'last_heartbeat_at' => Carbon::parse(STALE_DAY.' 07:30:00'),
        'ended_at' => Carbon::parse(STALE_DAY.' 08:55:00'),
        'duration_seconds' => 6900,
        'counts_toward_hours' => false,
    ])->create();

    // Tapu's counted remote hours, including one with the same overrun shape.
    TimeEntry::factory()->forEmployee($this->tapu->employee)->onTask($remoteTask)->state([
        'started_at' => Carbon::parse(STALE_DAY.' 06:00:00'),
        'work_date' => STALE_DAY,
        'last_heartbeat_at' => Carbon::parse(STALE_DAY.' 06:30:00'),
        'ended_at' => Carbon::parse(STALE_DAY.' 08:00:00'),
        'duration_seconds' => 7200,
    ])->create();

    Carbon::setTestNow(STALE_DAY.' 10:00:00');

    $before = [STALE_hours($this->yaseen), STALE_hours($this->tapu)];

    $this->actingAs($this->admin)->get('/admin/tasks/board')->assertOk();
    Artisan::call('hq:repair-task-timer-overruns');

    expect(TimeEntry::query()->open()->count())->toBe(0)
        ->and([STALE_hours($this->yaseen), STALE_hours($this->tapu)])->toBe($before);
});

it('stores a client timestamp sent in UTC as the same instant', function () {
    $task = STALE_task($this->tapu);

    // The widget sends `new Date().toISOString()` — UTC, `Z`. 03:00Z is 09:00 in Dhaka.
    $this->actingAs($this->tapu)->post('/employee/time/start', [
        'task_id' => $task->id,
        'client_uuid' => (string) Str::uuid(),
        'started_at' => '2026-09-14T03:00:00.000Z',
    ])->assertRedirect();

    $entry = TimeEntry::query()->sole();

    expect($entry->started_at->equalTo(Carbon::parse('2026-09-14T03:00:00Z')))->toBeTrue()
        ->and($entry->elapsedSeconds())->toBe(0);
});
