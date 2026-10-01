<?php

use App\Events\TaskChanged;
use App\Models\ActivityLog;
use App\Models\AttendanceRecord;
use App\Models\PayrollPeriod;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\PayrollService;
use App\Services\TimerService;
use App\Services\TimesheetService;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| F3 task-timer — a ▶ on every task, for everyone who works tasks
|--------------------------------------------------------------------------
|
| Office employees and Admins time their tasks inside an open clock-in, and
| those entries are a BREAKDOWN of the day: they reach the task's total and
| never an hours figure — not attendance, not the 5 h target, not the
| timesheet, not the daily_work_summary view payroll reads. Remote-timer
| employees keep the timer they already had; ▶ on a card simply points it at
| that task. One open entry per person; ▶ on another task switches.
|
| Constants and helpers are global in Pest, so everything here is TIMER_.
|
*/

/** A Monday, which every seeded schedule works. */
const TIMER_DAY = '2026-09-14';

/**
 * A fresh task with `$users` assigned, the first one primary.
 */
function TIMER_task(User ...$users): Task
{
    $task = Task::factory()->create();

    foreach ($users as $index => $user) {
        $task->assignees()->attach($user->employee->id, ['is_primary' => $index === 0]);
    }

    return $task;
}

/**
 * @param  list<TaskChanged>  $into
 */
function TIMER_collect(array &$into): void
{
    Event::listen(TaskChanged::class, function (TaskChanged $event) use (&$into): void {
        $into[] = $event;
    });
}

/**
 * @return list<string>
 */
function TIMER_channels(TaskChanged $event): array
{
    $names = array_map(fn (PrivateChannel $channel): string => $channel->name, $event->broadcastOn());
    sort($names);

    return $names;
}

/**
 * Every figure that is somebody's hours or pay for `$user` on TIMER_DAY — read fresh.
 *
 * @return array<string, mixed>
 */
function TIMER_hours(User $user): array
{
    $employee = $user->employee->fresh();
    $day = Carbon::parse(TIMER_DAY);

    // A new service each time: AttendanceService caches tracked minutes per request.
    $attendance = app()->make(AttendanceService::class);
    $record = AttendanceRecord::query()
        ->where('employee_id', $employee->id)
        ->whereDate('date', TIMER_DAY)
        ->first();

    $period = PayrollPeriod::query()->where('month', $day->copy()->startOfMonth()->toDateString())->first()
        ?? app(PayrollService::class)->createDraft(null, $day);

    $week = app(TimesheetService::class)->week($employee, $day);

    return [
        'tracked_minutes' => $attendance->trackedMinutes($employee, $day),
        'worked_minutes' => $record?->workedMinutes(),
        'attendance_status' => $record?->status?->value,
        'counted_seconds' => app(TimerService::class)->countedSecondsOn($employee, $day),
        'summary' => DB::table('daily_work_summary')
            ->where('employee_id', $employee->id)
            ->where('work_date', TIMER_DAY)
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all(),
        'timesheet' => [
            $week['totals']['counted_seconds'] ?? null,
            $week['totals']['pending_seconds'] ?? null,
            count($week['rows'] ?? []),
        ],
        'payroll_unpaid_days' => app(PayrollService::class)->unpaidDaysIn($employee, $period),
        'payroll_payable_days' => app(PayrollService::class)->payableDaysIn($employee, $period),
    ];
}

beforeEach(function () {
    Carbon::setTestNow(TIMER_DAY.' 09:00:00');

    $this->seed();

    // The seeded month of attendance and tracked time is not what these tests are about; each
    // builds the day it measures (the AGENTS.md rule: clear the window you count).
    TimeEntry::query()->delete();
    AttendanceRecord::query()->delete();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();
});

afterEach(function () {
    Carbon::setTestNow();
});

/* ------------------------------------------------------------ the clock-in rule */

it('refuses an office employee who is not clocked in, then clocks in and starts in one action', function () {
    $task = TIMER_task($this->yaseen);

    $this->actingAs($this->yaseen)
        ->postJson("/tasks/{$task->id}/timer", ['client_uuid' => (string) Str::uuid()])
        ->assertStatus(422)
        ->assertJsonValidationErrors('clock_in');

    expect(TimeEntry::count())->toBe(0)
        ->and(AttendanceRecord::count())->toBe(0);

    $this->actingAs($this->yaseen)
        ->post("/tasks/{$task->id}/timer", ['client_uuid' => (string) Str::uuid(), 'clock_in' => true])
        ->assertRedirect();

    $record = AttendanceRecord::query()->where('employee_id', $this->yaseen->employee->id)->sole();
    $entry = TimeEntry::query()->sole();

    expect($record->clock_in)->not->toBeNull()
        ->and($record->clock_out)->toBeNull()
        ->and($entry->task_id)->toBe($task->id)
        ->and($entry->isRunning())->toBeTrue()
        ->and($entry->counts_toward_hours)->toBeFalse();
});

it('keeps one open entry per person: ▶ on task B stops task A', function () {
    $a = TIMER_task($this->yaseen);
    $b = TIMER_task($this->yaseen);

    $this->actingAs($this->yaseen)->post("/tasks/{$a->id}/timer", ['clock_in' => true])->assertRedirect();

    Carbon::setTestNow(TIMER_DAY.' 09:40:00');

    $this->actingAs($this->yaseen)->post("/tasks/{$b->id}/timer")->assertRedirect();

    $entries = TimeEntry::query()->orderBy('id')->get();

    expect($entries)->toHaveCount(2)
        ->and($entries[0]->task_id)->toBe($a->id)
        ->and($entries[0]->isStopped())->toBeTrue()
        ->and($entries[0]->duration_seconds)->toBe(40 * 60)
        ->and($entries[1]->task_id)->toBe($b->id)
        ->and($entries[1]->isRunning())->toBeTrue()
        ->and(TimeEntry::query()->open()->count())->toBe(1)
        // The task's own total is the breakdown's to show.
        ->and((int) $a->fresh()->tracked_seconds)->toBe(40 * 60);
});

it('stops the running task timer when the person clocks out', function () {
    $task = TIMER_task($this->yaseen);

    $this->actingAs($this->yaseen)->post("/tasks/{$task->id}/timer", ['clock_in' => true])->assertRedirect();

    Carbon::setTestNow(TIMER_DAY.' 12:00:00');

    $this->actingAs($this->yaseen)->post('/attendance/clock-out')->assertRedirect();

    $entry = TimeEntry::query()->sole();

    expect($entry->isStopped())->toBeTrue()
        ->and($entry->ended_at->equalTo(Carbon::parse(TIMER_DAY.' 12:00:00')))->toBeTrue()
        ->and((int) $task->fresh()->tracked_seconds)->toBe(3 * 3600);

    // And ▶ after the clock-out asks to clock in again rather than running outside the day.
    $this->actingAs($this->yaseen)
        ->postJson("/tasks/{$task->id}/timer")
        ->assertStatus(422)
        ->assertJsonValidationErrors('clock_in');
});

it('never lets an office task entry move attendance, tracked minutes, the timesheet or payroll inputs', function () {
    $a = TIMER_task($this->yaseen);
    $b = TIMER_task($this->yaseen);

    $this->actingAs($this->yaseen)->post("/tasks/{$a->id}/timer", ['clock_in' => true])->assertRedirect();
    Carbon::setTestNow(TIMER_DAY.' 11:00:00');
    $this->actingAs($this->yaseen)->post("/tasks/{$b->id}/timer")->assertRedirect();
    Carbon::setTestNow(TIMER_DAY.' 12:30:00');
    $this->actingAs($this->yaseen)->post('/task-timer/stop')->assertRedirect();
    Carbon::setTestNow(TIMER_DAY.' 17:00:00');
    $this->actingAs($this->yaseen)->post('/attendance/clock-out')->assertRedirect();

    expect(TimeEntry::query()->stopped()->sum('duration_seconds'))->toBe(12600);

    $with = TIMER_hours($this->yaseen);

    TimeEntry::query()->delete();

    $without = TIMER_hours($this->yaseen);

    expect($with)->toBe($without)
        ->and($with['tracked_minutes'])->toBe(0)
        ->and($with['counted_seconds'])->toBe(0)
        ->and($with['worked_minutes'])->toBe(8 * 60);
});

/* ---------------------------------------------------- remote: exactly as before */

it('lets a remote employee ▶ a card on the timer he already had, counting as it always did', function () {
    $a = TIMER_task($this->tapu);
    $b = TIMER_task($this->tapu);

    // No clock-in for the timer's own people: their timer IS their day.
    $this->actingAs($this->tapu)->post("/tasks/{$a->id}/timer")->assertRedirect();
    Carbon::setTestNow(TIMER_DAY.' 10:00:00');
    $this->actingAs($this->tapu)->post("/tasks/{$b->id}/timer")->assertRedirect();

    $first = TimeEntry::query()->orderBy('id')->firstOrFail();

    expect(AttendanceRecord::count())->toBe(0)
        ->and($first->counts_toward_hours)->toBeTrue()
        ->and($first->isStopped())->toBeTrue()
        ->and(app(AttendanceService::class)->trackedMinutes($this->tapu->employee, Carbon::parse(TIMER_DAY)))->toBe(60)
        ->and(app(TimerService::class)->countedSecondsOn($this->tapu->employee, Carbon::parse(TIMER_DAY)))->toBe(3600);

    // The remote widget's own endpoint sees the same running entry.
    $this->actingAs($this->tapu)
        ->getJson('/employee/time/current')
        ->assertOk()
        ->assertJsonPath('running.task.id', $b->id);
});

/* --------------------------------------------------------------- who may track */

it('lets an Admin track their own task', function () {
    $task = TIMER_task($this->admin);

    $this->actingAs($this->admin)->post("/tasks/{$task->id}/timer", ['clock_in' => true])->assertRedirect();

    $entry = TimeEntry::query()->sole();

    expect($entry->employee_id)->toBe($this->admin->employee->id)
        ->and($entry->counts_toward_hours)->toBeFalse();
});

it('refuses an employee a task they are not assigned to, and refuses the Accountant outright', function () {
    $yaseensTask = TIMER_task($this->yaseen);

    // Yaseen cannot see Tapu's task at all: absent, not refused.
    $tapusTask = TIMER_task($this->tapu);

    $this->actingAs($this->yaseen)
        ->post("/tasks/{$tapusTask->id}/timer", ['clock_in' => true])
        ->assertNotFound();

    // The policy itself, for the employee who is not on it: no (brief 021 keeps this rule).
    expect(Gate::forUser($this->yaseen)->allows('trackTask', [TimeEntry::class, $tapusTask]))->toBeFalse();

    foreach (["/tasks/{$yaseensTask->id}/timer", '/task-timer/pause', '/task-timer/resume', '/task-timer/stop', '/task-timer/heartbeat'] as $path) {
        $this->actingAs($this->accountant)->post($path)->assertForbidden();
    }

    expect(TimeEntry::count())->toBe(0)
        ->and(AttendanceRecord::count())->toBe(0);
});

it('lets an Admin time a task they are not assigned to, under their own record, without assigning them', function () {
    $yaseensTask = TIMER_task($this->yaseen);

    $this->actingAs($this->admin)
        ->post("/tasks/{$yaseensTask->id}/timer", ['clock_in' => true])
        ->assertRedirect();

    $entry = TimeEntry::query()->sole();

    expect($entry->employee_id)->toBe($this->admin->employee->id)
        ->and($entry->task_id)->toBe($yaseensTask->id)
        ->and($yaseensTask->assignees()->where('employees.id', $this->admin->employee->id)->exists())->toBeFalse();

    $board = $this->actingAs($this->admin)->get('/admin/tasks/board')->assertOk()
        ->viewData('page')['props']['board'];
    $card = collect($board['columns'])->flatMap(fn (array $column) => $column['tasks'])
        ->firstWhere('id', $yaseensTask->id);

    expect($card)->not->toBeNull()
        ->and($card['permissions']['can_track_time'])->toBeTrue()
        ->and($card['my_timer'])->not->toBeNull()
        ->and($card['my_timer']['state'])->toBe('running');
});

/* ------------------------------------------------------------- the board payload */

it('shows running timers to an Admin and keeps them absent for an employee', function () {
    $task = TIMER_task($this->yaseen);

    $this->actingAs($this->yaseen)->post("/tasks/{$task->id}/timer", ['clock_in' => true])->assertRedirect();

    Carbon::setTestNow(TIMER_DAY.' 09:12:00');

    $card = function (array $board) use ($task): array {
        foreach ($board['columns'] as $column) {
            foreach ($column['tasks'] as $card) {
                if ($card['id'] === $task->id) {
                    return $card;
                }
            }
        }

        throw new RuntimeException('card not on the board');
    };

    $adminBoard = $this->actingAs($this->admin)->get('/admin/tasks/board')->assertOk()
        ->viewData('page')['props']['board'];
    $adminCard = $card($adminBoard);

    expect($adminCard)->toHaveKey('running_timers')
        ->and($adminCard['running_timers'])->toHaveCount(1)
        ->and($adminCard['running_timers'][0]['name'])->toBe($this->yaseen->name)
        ->and($adminCard['running_timers'][0]['elapsed_seconds'])->toBe(12 * 60)
        ->and($adminCard['my_timer'])->toBeNull()
        // Not assigned, and still ▶: an Admin may time any card they see (brief 021).
        ->and($adminCard['permissions']['can_track_time'])->toBeTrue();

    $yaseenBoard = $this->actingAs($this->yaseen)->get('/employee/tasks/board')->assertOk()
        ->viewData('page')['props']['board'];
    $yaseenCard = $card($yaseenBoard);

    expect($yaseenCard)->not->toHaveKey('running_timers')
        ->and($yaseenCard['my_timer']['state'])->toBe('running')
        ->and($yaseenCard['my_timer']['elapsed_seconds'])->toBe(12 * 60)
        ->and($yaseenCard['permissions']['can_track_time'])->toBeTrue();

    // Running-timer identities reach nobody else either: another of Yaseen's own cards with the
    // Admin's timer on it shows him nothing of it.
    $shared = TIMER_task($this->admin, $this->yaseen);
    $this->actingAs($this->admin)->post("/tasks/{$shared->id}/timer", ['clock_in' => true])->assertRedirect();

    $sharedCard = (function (array $board) use ($shared): array {
        foreach ($board['columns'] as $column) {
            foreach ($column['tasks'] as $card) {
                if ($card['id'] === $shared->id) {
                    return $card;
                }
            }
        }

        throw new RuntimeException('shared card not on the board');
    })($this->actingAs($this->yaseen)->get('/employee/tasks/board')->viewData('page')['props']['board']);

    expect($sharedCard)->not->toHaveKey('running_timers')
        ->and($sharedCard['my_timer'])->toBeNull();
});

/* -------------------------------------------------------------------- live (F1) */

it('rings `timer` after the commit — watchers and the owner on a start, every viewer on a stop', function () {
    $task = TIMER_task($this->yaseen);

    $this->actingAs($this->yaseen)->post("/tasks/{$task->id}/timer", ['clock_in' => true])->assertRedirect();

    $events = [];
    TIMER_collect($events);

    // A start inside a transaction that rolls back tells nobody.
    try {
        DB::transaction(function () {
            app(TimerService::class)->pause(TimeEntry::query()->open()->sole());

            throw new RuntimeException('roll back');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect($events)->toBe([]);

    $this->actingAs($this->yaseen)->post('/task-timer/pause')->assertRedirect();

    expect($events)->toHaveCount(1)
        ->and($events[0]->kind)->toBe('timer')
        ->and($events[0]->broadcastWith())->toBe(['task_id' => $task->id, 'kind' => 'timer'])
        ->and(TIMER_channels($events[0]))->toContain('private-tasks.'.$this->admin->id)
        ->and(TIMER_channels($events[0]))->toContain('private-tasks.'.$this->yaseen->id);

    // A viewer who is neither a watcher nor the owner hears the STOP (the total moved) and
    // nothing before it.
    $events = [];
    $task->assignees()->attach($this->tapu->employee->id, ['is_primary' => false]);

    $this->actingAs($this->yaseen)->post('/task-timer/resume')->assertRedirect();
    expect(TIMER_channels($events[0]))->not->toContain('private-tasks.'.$this->tapu->id);

    $this->actingAs($this->yaseen)->post('/task-timer/stop')->assertRedirect();
    expect($events)->toHaveCount(2)
        ->and(TIMER_channels($events[1]))->toContain('private-tasks.'.$this->tapu->id);

    expect(in_array('timer', TaskChanged::KINDS, true))->toBeTrue();
});

/* ------------------------------------------------------------------ the watchdog */

it('still has the watchdog stop an office entry whose heartbeat went quiet', function () {
    $task = TIMER_task($this->yaseen);

    $this->actingAs($this->yaseen)->post("/tasks/{$task->id}/timer", ['clock_in' => true])->assertRedirect();

    Carbon::setTestNow(TIMER_DAY.' 09:03:00');
    $this->actingAs($this->yaseen)->postJson('/task-timer/heartbeat')->assertOk()
        ->assertJsonPath('running.task_id', $task->id);

    Carbon::setTestNow(TIMER_DAY.' 09:20:00');
    Artisan::call('hq:timer-watchdog');

    $entry = TimeEntry::query()->sole();

    expect($entry->isStopped())->toBeTrue()
        ->and($entry->ended_at->equalTo(Carbon::parse(TIMER_DAY.' 09:03:00')))->toBeTrue()
        ->and($entry->isFlagged())->toBeTrue()
        ->and($entry->counts_toward_hours)->toBeFalse();

    $this->actingAs($this->yaseen)->postJson('/task-timer/heartbeat')->assertOk()
        ->assertJsonPath('running', null);
});

/* ------------------------- restart the same day (brief 027, the client's point 9) */

// The client: "once a person stop the timer . then its cant turn on in the current day".

dataset('TIMER_restarters', [
    'office employee' => ['yaseen', true],
    'admin' => ['admin', true],
    'remote employee' => ['tapu', false],
]);

it('starts again after a stop the same day — the same task and another task', function (string $who, bool $office) {
    $user = $this->{$who};
    $a = TIMER_task($user);
    $b = TIMER_task($user);

    $this->actingAs($user)->post("/tasks/{$a->id}/timer", ['client_uuid' => (string) Str::uuid(), 'clock_in' => $office])
        ->assertSessionHas('success');
    Carbon::setTestNow(TIMER_DAY.' 10:00:00');
    $this->actingAs($user)->post('/task-timer/stop')->assertSessionHas('success');

    // The same task again, the same day.
    Carbon::setTestNow(TIMER_DAY.' 10:30:00');
    $this->actingAs($user)->post("/tasks/{$a->id}/timer", ['client_uuid' => (string) Str::uuid()])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success');

    $again = TimeEntry::query()->open()->sole();
    expect($again->task_id)->toBe($a->id)->and($again->isRunning())->toBeTrue();

    // And another task after a second stop.
    Carbon::setTestNow(TIMER_DAY.' 11:00:00');
    $this->actingAs($user)->post('/task-timer/stop')->assertSessionHas('success');
    $this->actingAs($user)->post("/tasks/{$b->id}/timer", ['client_uuid' => (string) Str::uuid()])
        ->assertSessionHas('success');

    $other = TimeEntry::query()->open()->sole();
    expect($other->task_id)->toBe($b->id)
        ->and($other->isRunning())->toBeTrue()
        ->and(TimeEntry::query()->where('employee_id', $user->employee->id)->count())->toBe(3)
        ->and($other->counts_toward_hours)->toBe(! $office);
})->with('TIMER_restarters');

it('re-opens the day when an office employee who clocked out presses "Clock in and start?"', function () {
    $task = TIMER_task($this->yaseen);

    $this->actingAs($this->yaseen)->post("/tasks/{$task->id}/timer", ['clock_in' => true])->assertSessionHas('success');
    Carbon::setTestNow(TIMER_DAY.' 12:00:00');
    $this->actingAs($this->yaseen)->post('/attendance/clock-out')->assertSessionHas('success');

    Carbon::setTestNow(TIMER_DAY.' 13:00:00');
    $this->actingAs($this->yaseen)
        ->postJson("/tasks/{$task->id}/timer")
        ->assertStatus(422)
        ->assertJsonValidationErrors('clock_in');

    $this->actingAs($this->yaseen)
        ->post("/tasks/{$task->id}/timer", ['client_uuid' => (string) Str::uuid(), 'clock_in' => true])
        ->assertSessionHas('success', 'Timer started.');

    $record = AttendanceRecord::query()->where('employee_id', $this->yaseen->employee->id)->sole();
    $running = TimeEntry::query()->open()->sole();

    expect($record->clock_in->equalTo(Carbon::parse(TIMER_DAY.' 09:00:00')))->toBeTrue()
        ->and($record->clock_out)->toBeNull()
        ->and($record->status->value)->toBe('present')
        ->and($running->task_id)->toBe($task->id)
        ->and($running->isRunning())->toBeTrue()
        ->and(app(AttendanceService::class)->isClockedIn($this->yaseen->employee))->toBeTrue()
        ->and(ActivityLog::query()
            ->where('object_type', $record->getMorphClass())
            ->where('object_id', $record->id)
            ->where('description', 're-opened the day')
            ->count())->toBe(1);

    // The day then closes again at the second clock-out, stopping the timer with it.
    Carbon::setTestNow(TIMER_DAY.' 17:00:00');
    $this->actingAs($this->yaseen)->post('/attendance/clock-out')->assertSessionHas('success');

    expect($record->fresh()->clock_out->equalTo(Carbon::parse(TIMER_DAY.' 17:00:00')))->toBeTrue()
        ->and(TimeEntry::query()->open()->count())->toBe(0);
});

it('re-opens the day from the plain Clock-in button after a clock-out, the same way', function () {
    $this->actingAs($this->yaseen)->post('/attendance/clock-in')->assertSessionHas('success');
    Carbon::setTestNow(TIMER_DAY.' 12:00:00');
    $this->actingAs($this->yaseen)->post('/attendance/clock-out')->assertSessionHas('success');

    Carbon::setTestNow(TIMER_DAY.' 13:00:00');
    $this->actingAs($this->yaseen)->post('/attendance/clock-in')
        ->assertSessionMissing('error')
        ->assertSessionHas('success');

    $record = AttendanceRecord::query()->where('employee_id', $this->yaseen->employee->id)->sole();

    expect($record->clock_in->equalTo(Carbon::parse(TIMER_DAY.' 09:00:00')))->toBeTrue()
        ->and($record->clock_out)->toBeNull()
        ->and(app(AttendanceService::class)->isClockedIn($this->yaseen->employee))->toBeTrue();

    // Still open: a second press is the double tap the old refusal was for.
    $this->actingAs($this->yaseen)->post('/attendance/clock-in')->assertSessionHas('error', 'You already clocked in at 09:00 today.');
});
