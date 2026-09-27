<?php

use App\Models\Schedule;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskService;
use App\Support\TaskBucket;

/*
|--------------------------------------------------------------------------
| Employee — the dashboard is one person's, and only that person's
|--------------------------------------------------------------------------
|
| Part D §3: "Served by a dedicated endpoint scoped to the requesting
| user." Phase 10 finished the card list — "My Schedule" and "Recent
| Activity" were the two still missing — so this file asserts the two new
| blocks and, more importantly, the property the whole screen rests on:
| **nothing on it can ever be somebody else's.**
|
| "Recent activity" is the block that could go wrong quietly. It reads
| `activity_logs`, a table with every actor's rows in it, so the scope is
| `actor_id` and it is tested from both sides: the reader's own row is
| there, and a colleague's row on a task they share is not. An activity
| feed of other people is the surveillance Part H §1 forbids and spec §22
| keeps off the Company dashboard.
|
*/

beforeEach(function () {
    $this->seed();

    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
});

/**
 * The employee dashboard's props, for this user.
 *
 * @return array<string, mixed>
 */
function employeeDashboardFor(object $test, User $user): array
{
    return $test->actingAs($user)->get('/employee/dashboard')->assertOk()->inertiaPage()['props'];
}

/*
|--------------------------------------------------------------------------
| My Schedule
|--------------------------------------------------------------------------
*/

it('sends the reader their own week, and nobody else\'s', function () {
    $mine = employeeDashboardFor($this, $this->yaseen)['schedule'];
    $theirs = employeeDashboardFor($this, $this->tapu)['schedule'];

    // Yaseen is an office employee on eight hours with a start time; Tapu runs a timer on five
    // and has no clock to be late against. The card is the reader's own schedule row, so the two
    // answers differ — and each matches that person's `schedules` row rather than a constant.
    expect((float) $mine['hours_per_day'])->toBe((float) $this->yaseen->employee->schedule->working_hours_per_day)
        ->and($mine['location'])->toBe('Office')
        ->and($mine['start_time'])->toBe(substr((string) $this->yaseen->employee->schedule->start_time, 0, 5))
        ->and((float) $theirs['hours_per_day'])->toBe((float) $this->tapu->employee->schedule->working_hours_per_day)
        ->and($theirs['location'])->toBe('Remote')
        ->and($mine['href'])->toBe('/attendance');
})->group('phase10');

it('prints the working days in the week\'s order, as words', function () {
    $schedule = employeeDashboardFor($this, $this->yaseen)['schedule'];

    // `sun` → `Sun`, from `Weekday`, in the enum's order — so the week reads Sunday first as it
    // does everywhere else in this application, and no screen has to sort it.
    expect($schedule['days'])->toBe(['Sun', 'Mon', 'Tue', 'Wed', 'Thu']);
})->group('phase10');

it('sends no schedule at all for somebody nobody has given one', function () {
    Schedule::query()->where('employee_id', $this->yaseen->employee->getKey())->delete();
    $this->yaseen->employee->unsetRelation('schedule');

    // Null, not a zero-hour week. The card says so in words — an eight-hour default invented
    // here would be a rule about somebody's pay that nobody wrote down.
    expect(employeeDashboardFor($this, $this->yaseen)['schedule'])->toBeNull();
})->group('phase10');

/*
|--------------------------------------------------------------------------
| Recent activity
|--------------------------------------------------------------------------
*/

it('shows the reader their own task changes, each one opening the task', function () {
    $task = Task::visibleTo($this->yaseen)->notArchived()->firstOrFail();

    app(TaskService::class)->addChecklistItem($this->yaseen, $task, 'Re-check the redirect map');

    $rows = employeeDashboardFor($this, $this->yaseen)['recentActivity'];

    expect($rows)->not->toBeEmpty()
        ->and($rows[0]['title'])->toBe('Checklist item added: Re-check the redirect map')
        // The meta names the task and when it happened, composed on the server: "2 hours ago"
        // worked out from a `Date` in the browser moves by five hours on a laptop in London.
        ->and($rows[0]['meta'])->toStartWith($task->title.' · ')
        ->and($rows[0]['href'])->toBe('/employee/tasks/'.$task->id);

    $this->actingAs($this->yaseen)->get($rows[0]['href'])->assertOk();
})->group('phase10');

it('never puts another person\'s activity on this dashboard', function () {
    // A task they can both see, changed by somebody else. Yaseen may open the task and read its
    // timeline there — this card is not that. It is what HE did.
    $shared = Task::visibleTo($this->yaseen)->notArchived()->firstOrFail();

    app(TaskService::class)->addChecklistItem($this->admin, $shared, 'Typed by the Admin');

    $rows = employeeDashboardFor($this, $this->yaseen)['recentActivity'];

    expect(array_column($rows, 'title'))->not->toContain('Checklist item added: Typed by the Admin');

    // And from the other side: the Admin's own My Work view of the same log line is his.
    app(TaskService::class)->addChecklistItem($this->yaseen, $shared, 'Typed by Yaseen');

    expect(array_column(employeeDashboardFor($this, $this->yaseen)['recentActivity'], 'title'))
        ->toContain('Checklist item added: Typed by Yaseen');
})->group('phase10');

it('drops a row whose task has left the reader\'s scope', function () {
    $task = Task::visibleTo($this->yaseen)->notArchived()->firstOrFail();

    app(TaskService::class)->addChecklistItem($this->yaseen, $task, 'Worked on before the hand-off');

    // Taken off the task. The log line is still theirs, but the title on it is not something
    // they may read any more — so the row goes rather than appearing without a link.
    app(TaskService::class)->syncAssignees($this->admin, $task, [$this->tapu->employee->getKey()]);

    $rows = employeeDashboardFor($this, $this->yaseen)['recentActivity'];

    expect(array_column($rows, 'title'))->not->toContain('Checklist item added: Worked on before the hand-off');
})->group('phase10');

it('shows a shortlist of activity rather than a log', function () {
    $task = Task::visibleTo($this->yaseen)->notArchived()->firstOrFail();

    for ($i = 1; $i <= 8; $i++) {
        app(TaskService::class)->addChecklistItem($this->yaseen, $task, 'Step '.$i);
    }

    expect(count(employeeDashboardFor($this, $this->yaseen)['recentActivity']))->toBe(5);
})->group('phase10');

/*
|--------------------------------------------------------------------------
| Everything else on the screen is this person's too
|--------------------------------------------------------------------------
*/

it('counts only the reader\'s own tasks on the five cards', function () {
    $props = employeeDashboardFor($this, $this->yaseen);

    foreach ($props['taskStats'] as $card) {
        // `mine` on the server, so the number is theirs and the link opens theirs. An Admin
        // reading this surface would get their own plate too — the scope is the requester's.
        expect($card['count'])->toBe(app(TaskService::class)->count($this->yaseen, [
            'mine' => true,
            'bucket' => $card['key'],
        ]))->and($card['href'])->toStartWith('/employee/my-tasks');
    }

    // And they are not the agency's: Yaseen holds fewer open tasks than every open task there is.
    $mine = collect($props['taskStats'])->firstWhere('key', TaskBucket::Open->value)['count'];

    expect($mine)->toBeLessThan((int) Task::query()->notArchived()->open()->count());
})->group('phase10');

it('sends every documented block on every render, whichever kind of day the reader has', function (string $email) {
    $props = employeeDashboardFor($this, User::where('email', $email)->firstOrFail());

    // A payload whose SHAPE depended on the reader is a payload no screen can be typed against,
    // which is why `timer` and `attendance` are both always present and exactly one is null. The
    // two Phase 10 blocks follow the same rule: `schedule` is an object or null, `recentActivity`
    // is always an array.
    expect($props)->toHaveKeys(['schedule', 'recentActivity', 'timer', 'attendance', 'leave'])
        ->and($props['recentActivity'])->toBeArray()
        ->and($props['schedule'])->not->toBeNull()
        // Exactly one hero, decided on the server from `tracking_mode`.
        ->and($props['timer'] === null)->not->toBe($props['attendance'] === null);
})->with([
    'office' => ['yaseen@goodtechies.test'],
    'remote' => ['tapu@goodtechies.test'],
])->group('phase10');
