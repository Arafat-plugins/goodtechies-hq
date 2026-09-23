<?php

use App\Models\Employee;
use App\Models\Schedule;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\TimerService;
use App\Services\TimesheetService;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| The weekly timesheet
|--------------------------------------------------------------------------
|
| Three things are asserted here rather than argued.
|
| **1. The week starts where the SCHEDULE says, and never on Monday by
| default.** A schedule of Sun–Thu starts on Sunday, one of Mon–Fri on
| Monday, and one of Tue–Thu on **Tuesday** — the third case is the one that
| proves it, because a hard-coded Monday would pass the second.
|
| **2. The totals add up, checked by hand.** The fixture below is five
| entries whose figures are written out in the comment beside them, and the
| row, day and week totals are asserted against those numbers and not
| against anything the service computed.
|
| **3. An employee asking for a colleague's week is 404, never 403** — the
| lookup goes through Employee::attendanceVisibleTo(), so absence is what
| the controller has rather than what it decides (Part C).
|
| 2026-09-24 is a Thursday. The week that holds it, for a Sun–Thu schedule,
| runs Sun 20 September to Sat 26 September.
|
*/

const SHEET_THURSDAY = '2026-09-24';
const SHEET_SUNDAY = '2026-09-20';
const SHEET_MONDAY = '2026-09-21';
const SHEET_TUESDAY = '2026-09-22';
const SHEET_SATURDAY = '2026-09-26';

beforeEach(function (): void {
    Carbon::setTestNow(SHEET_THURSDAY.' 09:00:00');

    $this->seed();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * Put a finished entry on a day. Seconds rather than a factory state, because every figure in
 * this file is one a human added up.
 */
function sheetEntry(Employee $employee, Task $task, string $date, int $seconds, bool $counted = true): TimeEntry
{
    $startedAt = Carbon::parse($date)->setTime(10, 0);

    return TimeEntry::factory()
        ->forEmployee($employee)
        ->onTask($task)
        ->create([
            'work_date' => $date,
            'started_at' => $startedAt,
            'ended_at' => $startedAt->copy()->addSeconds($seconds),
            'duration_seconds' => $seconds,
            'approved_at' => $counted ? $startedAt->copy()->addSeconds($seconds) : null,
        ]);
}

/** Two of Tapu's tasks, and the five entries whose sums this file checks by hand. */
function sheetFixture(Employee $tapu): array
{
    $a = Task::factory()->create(['title' => 'Alpha — fix the canonicals']);
    $b = Task::factory()->create(['title' => 'Bravo — rewrite the county pages']);

    $a->assignees()->attach($tapu->id, ['is_primary' => true]);
    $b->assignees()->attach($tapu->id, ['is_primary' => true]);

    // Task A: 2h on Sunday, 1h 30m on Monday, 30m on Saturday (an off day) — 4h counted.
    sheetEntry($tapu, $a, SHEET_SUNDAY, 7200);
    sheetEntry($tapu, $a, SHEET_MONDAY, 5400);
    sheetEntry($tapu, $a, SHEET_SATURDAY, 1800);

    // Task B: 45m counted on Monday, and 3h on Tuesday that nobody has signed off.
    sheetEntry($tapu, $b, SHEET_MONDAY, 2700);
    sheetEntry($tapu, $b, SHEET_TUESDAY, 10800, counted: false);

    return [$a, $b];
}

function sheetSchedule(Employee $employee, array $days, string $hours = '5.00'): void
{
    Schedule::updateOrCreate(
        ['employee_id' => $employee->getKey()],
        [
            'working_days' => $days,
            'working_hours_per_day' => $hours,
            'start_time' => null,
            'office_or_remote' => 'remote',
        ],
    );

    $employee->unsetRelation('schedule');
}

/* ================================================== where the week starts */

it('starts the week on the first working day of the employee schedule, not on Monday', function (): void {
    $tapu = $this->tapu->employee;
    $service = app(TimesheetService::class);
    $thursday = Carbon::parse(SHEET_THURSDAY);

    // The seeded Bangladesh week.
    sheetSchedule($tapu, ['sun', 'mon', 'tue', 'wed', 'thu']);
    expect($service->weekStart($tapu->fresh(), $thursday)->toDateString())->toBe(SHEET_SUNDAY);

    // A western week. On its own this would also pass a hard-coded Monday.
    sheetSchedule($tapu, ['mon', 'tue', 'wed', 'thu', 'fri']);
    expect($service->weekStart($tapu->fresh(), $thursday)->toDateString())->toBe(SHEET_MONDAY);

    // The case that proves it: a three-day week starting mid-week. Nothing but reading
    // `working_days` produces Tuesday here.
    sheetSchedule($tapu, ['tue', 'wed', 'thu']);
    expect($service->weekStart($tapu->fresh(), $thursday)->toDateString())->toBe(SHEET_TUESDAY);
});

it('falls back to the calendar week when an employee has no schedule, and says so', function (): void {
    $tapu = $this->tapu->employee;
    $tapu->schedule?->delete();
    $tapu->unsetRelation('schedule');

    $week = app(TimesheetService::class)->week($tapu->fresh(), Carbon::parse(SHEET_THURSDAY));

    expect($week['week']['starts_on'])->toBe('sun')
        ->and($week['week']['start'])->toBe(SHEET_SUNDAY)
        // No schedule means no target to read a total against, rather than a default nobody set.
        ->and($week['totals']['target_seconds'])->toBeNull()
        ->and($week['week']['starts_on_reason'])->toContain('no work schedule');
});

it('puts the schedule week on the page, not a weekday order from the template', function (): void {
    sheetSchedule($this->tapu->employee, ['tue', 'wed', 'thu']);

    $this->actingAs($this->tapu)
        ->get('/employee/timesheet?week='.SHEET_THURSDAY)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Employee/Timesheet/Index')
            ->where('week.starts_on', 'tue')
            ->where('week.start', SHEET_TUESDAY)
            ->has('days', 7)
            ->where('days.0.weekday', 'tue')
            ->where('days.6.weekday', 'mon')
            // Three working days at 5 h — the target follows the schedule it was read from.
            ->where('totals.working_days', 3)
            ->where('totals.target_seconds', 3 * 5 * 3600));
});

/* ============================================== the totals, checked by hand */

it('adds the row, day and week totals up to the figures in the fixture', function (): void {
    $tapu = $this->tapu->employee;
    sheetSchedule($tapu, ['sun', 'mon', 'tue', 'wed', 'thu']);
    sheetFixture($tapu);

    $week = app(TimesheetService::class)->week($tapu->fresh(), Carbon::parse(SHEET_THURSDAY));

    expect($week['week']['start'])->toBe(SHEET_SUNDAY)
        ->and($week['week']['end'])->toBe(SHEET_SATURDAY);

    // Two tasks worked on, ordered by project then title — Alpha before Bravo.
    $rows = collect($week['rows'])->keyBy(fn (array $row): string => $row['task']['title']);
    expect($rows)->toHaveCount(2);

    // Row totals: 7200 + 5400 + 1800 = 14400 (4h), and 2700 counted with 10800 pending.
    expect($rows['Alpha — fix the canonicals']['counted_seconds'])->toBe(14400)
        ->and($rows['Alpha — fix the canonicals']['pending_seconds'])->toBe(0)
        ->and($rows['Bravo — rewrite the county pages']['counted_seconds'])->toBe(2700)
        ->and($rows['Bravo — rewrite the county pages']['pending_seconds'])->toBe(10800);

    // Day totals: Sun 7200, Mon 5400 + 2700 = 8100, Tue 0 counted (10800 pending), Sat 1800.
    $days = collect($week['days'])->keyBy('date');
    expect($days[SHEET_SUNDAY]['counted_seconds'])->toBe(7200)
        ->and($days[SHEET_MONDAY]['counted_seconds'])->toBe(8100)
        ->and($days[SHEET_TUESDAY]['counted_seconds'])->toBe(0)
        ->and($days[SHEET_TUESDAY]['pending_seconds'])->toBe(10800)
        ->and($days[SHEET_SATURDAY]['counted_seconds'])->toBe(1800);

    // Week totals: 7200 + 8100 + 0 + 1800 = 17100 (4h 45m). The rows agree: 14400 + 2700.
    expect($week['totals']['counted_seconds'])->toBe(17100)
        ->and($week['totals']['pending_seconds'])->toBe(10800)
        // Five working days at 5 h — Tapu's 25 hours.
        ->and($week['totals']['target_seconds'])->toBe(90000)
        ->and($week['totals']['working_days'])->toBe(5);

    // The two ways of adding the same week up have to agree, which is the point of doing both.
    expect(collect($week['days'])->sum('counted_seconds'))
        ->toBe(collect($week['rows'])->sum('counted_seconds'));
});

it('agrees with the Time page about a day, because both ask the same one question', function (): void {
    $tapu = $this->tapu->employee;
    sheetSchedule($tapu, ['sun', 'mon', 'tue', 'wed', 'thu']);
    sheetFixture($tapu);

    $timer = app(TimerService::class);
    $week = app(TimesheetService::class)->week($tapu->fresh(), Carbon::parse(SHEET_THURSDAY));
    $days = collect($week['days'])->keyBy('date');

    foreach ([SHEET_SUNDAY, SHEET_MONDAY, SHEET_TUESDAY, SHEET_SATURDAY] as $date) {
        expect($days[$date]['counted_seconds'])
            ->toBe($timer->countedSecondsOn($tapu, Carbon::parse($date)), "counted on {$date}")
            ->and($days[$date]['pending_seconds'])
            ->toBe($timer->pendingSecondsOn($tapu, Carbon::parse($date)), "pending on {$date}");
    }
});

it('marks a day the schedule does not work, without hiding the hours tracked on it', function (): void {
    $tapu = $this->tapu->employee;
    sheetSchedule($tapu, ['sun', 'mon', 'tue', 'wed', 'thu']);
    sheetFixture($tapu);

    $days = collect(app(TimesheetService::class)->week($tapu->fresh(), Carbon::parse(SHEET_THURSDAY))['days'])
        ->keyBy('date');

    expect($days[SHEET_SATURDAY]['is_working_day'])->toBeFalse()
        // No target on a day nobody is expected to work — null, not zero.
        ->and($days[SHEET_SATURDAY]['target_seconds'])->toBeNull()
        // And the half hour that was tracked on it is still there and still counts.
        ->and($days[SHEET_SATURDAY]['counted_seconds'])->toBe(1800);
});

it('leaves a running entry out of every total, and says a timer is going in the cell', function (): void {
    $tapu = $this->tapu->employee;
    sheetSchedule($tapu, ['sun', 'mon', 'tue', 'wed', 'thu']);
    [$a] = sheetFixture($tapu);

    TimeEntry::factory()
        ->forEmployee($tapu)
        ->onTask($a)
        ->running(Carbon::parse(SHEET_THURSDAY)->setTime(8, 0))
        ->create();

    $week = app(TimesheetService::class)->week($tapu->fresh(), Carbon::parse(SHEET_THURSDAY));
    $row = collect($week['rows'])->firstWhere('key', 'task-'.$a->id);
    $thursday = collect($row['cells'])->firstWhere('date', SHEET_THURSDAY);

    expect($thursday['is_running'])->toBeTrue()
        ->and($thursday['counted_seconds'])->toBe(0)
        // The week total is unchanged by a session whose length is still a question about now().
        ->and($week['totals']['counted_seconds'])->toBe(17100);
});

/* ======================================================== who may read what */

it('shows an employee their own week', function (): void {
    sheetFixture($this->tapu->employee);

    $this->actingAs($this->tapu)
        ->get('/employee/timesheet?week='.SHEET_THURSDAY)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Employee/Timesheet/Index')
            ->where('subject.is_self', true)
            ->where('subject.name', $this->tapu->name)
            ->has('days', 7)
            ->has('rows', 2)
            // Adding time by hand is the timer's fallback and belongs to the person whose week
            // it is. Resolved by the policy on the server, never from a role in Vue.
            ->where('permissions.can_add_time', true));
});

it('is 404, never 403, when an employee asks for a colleague\'s week', function (): void {
    $this->actingAs($this->tapu)
        ->get('/employee/timesheet/'.$this->yaseen->employee->id)
        ->assertNotFound();
});

it('is 404 for an employee id that does not exist', function (): void {
    $this->actingAs($this->tapu)
        ->get('/employee/timesheet/999999')
        ->assertNotFound();
});

it('refuses the employee timesheet to anybody the timer does not measure, with a 403', function (): void {
    // Yaseen is on the surface and has no timer: a fact about him, so a refusal and not an
    // absence. The Accountant and the Admin are stopped by the surface itself.
    $this->actingAs($this->yaseen)->get('/employee/timesheet')->assertForbidden();
    $this->actingAs($this->accountant)->get('/employee/timesheet')->assertForbidden();
    $this->actingAs($this->admin)->get('/employee/timesheet')->assertForbidden();
});

it('lets an Admin read somebody else\'s week, with no control to add to it', function (): void {
    $tapu = $this->tapu->employee;
    sheetSchedule($tapu, ['sun', 'mon', 'tue', 'wed', 'thu']);
    sheetFixture($tapu);

    $this->actingAs($this->admin)
        ->get('/admin/timesheet/'.$tapu->id.'?week='.SHEET_THURSDAY)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Timesheet/Index')
            ->where('subject.id', $tapu->id)
            ->where('subject.is_self', false)
            ->where('totals.counted_seconds', 17100)
            // The Admin reads; the write lives on the Employee surface.
            ->where('permissions.can_add_time', false)
            ->has('employees'));
});

it('opens the Admin timesheet on a timer-tracked employee when the URL names nobody', function (): void {
    $this->actingAs($this->admin)
        ->get('/admin/timesheet')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Timesheet/Index')
            ->where('subject.id', $this->tapu->employee->id)
            ->where('subject.tracking_mode', 'remote_timer'));
});

it('is 404 on the Admin timesheet for an employee id that does not exist', function (): void {
    $this->actingAs($this->admin)
        ->get('/admin/timesheet/999999')
        ->assertNotFound();
});

it('refuses the Admin timesheet to every other role with a 403', function (): void {
    foreach ([$this->tapu, $this->yaseen, $this->accountant] as $user) {
        $this->actingAs($user)->get('/admin/timesheet')->assertForbidden();
    }
});

it('sends the same week to the Admin and to the employee', function (): void {
    $tapu = $this->tapu->employee;
    sheetSchedule($tapu, ['sun', 'mon', 'tue', 'wed', 'thu']);
    sheetFixture($tapu);

    $mine = $this->actingAs($this->tapu)
        ->get('/employee/timesheet?week='.SHEET_THURSDAY)
        ->viewData('page')['props'];

    $theirs = $this->actingAs($this->admin)
        ->get('/admin/timesheet/'.$tapu->id.'?week='.SHEET_THURSDAY)
        ->viewData('page')['props'];

    // One payload builder, so the two readings of a week cannot differ by a second.
    expect($theirs['totals'])->toBe($mine['totals'])
        ->and($theirs['days'])->toBe($mine['days'])
        ->and($theirs['rows'])->toBe($mine['rows']);
});

it('shows a week with nothing in it as a week with nothing in it', function (): void {
    $this->actingAs($this->tapu)
        ->get('/employee/timesheet?week=2026-01-07')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('days', 7)
            ->has('rows', 0)
            ->where('totals.counted_seconds', 0)
            ->where('totals.pending_seconds', 0));
});

it('falls back to this week when the week parameter is nonsense', function (): void {
    $this->actingAs($this->tapu)
        ->get('/employee/timesheet?week=not-a-date')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('week.is_current', true));
});
