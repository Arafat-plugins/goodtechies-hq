<?php

use App\Models\LeaveType;
use App\Models\Task;
use App\Models\User;
use App\Services\LeaveService;
use App\Services\TaskService;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| "Assignee on leave" — the flag, and the reassignment that must not happen
|--------------------------------------------------------------------------
|
| Part D §5 and §9: a task due inside an approved leave window is FLAGGED.
| Part H forbids inventing the rest, so nothing reassigns anything — the
| assignees the task had before the approval are the assignees it has after.
|
| The flag rides on `TaskService::query()`, which is the single funnel for
| the List, the Board, the Calendar, My Tasks and the dashboards, so one
| assertion here covers all of them. Its privacy is `taskFlagScopeFor()`:
| an approver sees every assignee's, everybody else only their own.
|
*/

const FLAG_SUNDAY = '2026-10-04';
const FLAG_MONDAY = '2026-10-05';

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();

    $this->annual = LeaveType::where('name', 'Annual')->firstOrFail();
    $this->leave = app(LeaveService::class);
    $this->tasks = app(TaskService::class);

    // One of Tapu's seeded tasks, moved so it falls due inside the leave window below.
    $this->task = Task::where('title', 'Fix the duplicate canonical tags on model pages')->firstOrFail();
    $this->task->forceFill(['due_date' => FLAG_MONDAY])->save();
});

/** The flagged names on one task, as the reader sees them. */
function flagFor(User $reader, Task $task): array
{
    $row = app(TaskService::class)
        ->query($reader, [])
        ->whereKey($task->getKey())
        ->first();

    $raw = $row?->getAttribute('assignees_on_leave');

    return $raw === null ? [] : (array) json_decode((string) $raw, true);
}

it('flags a task whose assignee is on approved leave when it is due', function () {
    expect(flagFor($this->admin, $this->task))->toBe([]);

    $request = $this->leave->apply($this->tapu, $this->tapu->employee, $this->annual, FLAG_SUNDAY, FLAG_MONDAY, 'Two days.');

    // Pending is not a flag: nothing is booked until it is approved.
    expect(flagFor($this->admin, $this->task))->toBe([]);

    $this->leave->approve($this->admin, $request);

    $flag = flagFor($this->admin, $this->task);

    expect($flag)->toHaveCount(1)
        ->and($flag[0]['name'])->toBe('Tapu')
        ->and($flag[0]['until'])->toBe(FLAG_MONDAY);
});

it('does not flag a task due outside the window', function () {
    $request = $this->leave->apply($this->tapu, $this->tapu->employee, $this->annual, FLAG_SUNDAY, FLAG_SUNDAY, 'One day.');
    $this->leave->approve($this->admin, $request);

    $this->task->forceFill(['due_date' => '2026-10-06'])->save();

    expect(flagFor($this->admin, $this->task))->toBe([]);
});

it('never reassigns the task it flags', function () {
    $before = $this->task->assignees()->pluck('employees.id')->sort()->values()->all();
    $primaryBefore = $this->task->assignees()->wherePivot('is_primary', true)->pluck('employees.id')->all();

    $request = $this->leave->apply($this->tapu, $this->tapu->employee, $this->annual, FLAG_SUNDAY, FLAG_MONDAY, 'Two days.');
    $this->leave->approve($this->admin, $request);

    $task = $this->task->fresh();

    expect(flagFor($this->admin, $task))->toHaveCount(1)
        ->and($task->assignees()->pluck('employees.id')->sort()->values()->all())->toBe($before)
        ->and($task->assignees()->wherePivot('is_primary', true)->pluck('employees.id')->all())->toBe($primaryBefore)
        ->and($task->status)->toBe($this->task->status);
});

it('shows a colleague flag only to somebody who may see their leave', function () {
    $request = $this->leave->apply($this->tapu, $this->tapu->employee, $this->annual, FLAG_SUNDAY, FLAG_MONDAY, 'Two days.');
    $this->leave->approve($this->admin, $request);

    // The Admin holds `leave.approve` and sees every assignee's flag.
    expect(flagFor($this->admin, $this->task))->toHaveCount(1);

    // Tapu holds none, so the only flag he can see is his own — which this is.
    expect(flagFor($this->tapu, $this->task))->toHaveCount(1);

    // Yaseen holds none and is not the person on leave, so he is told nothing about a
    // colleague. He is not on this task either, so the query hands him no row at all — which
    // is the same answer from one line earlier in the same rule.
    expect(flagFor($this->yaseen, $this->task))->toBe([]);
});

it('costs no extra query per task', function () {
    $request = $this->leave->apply($this->tapu, $this->tapu->employee, $this->annual, FLAG_SUNDAY, FLAG_MONDAY, 'Two days.');
    $this->leave->approve($this->admin, $request);

    // The flag is a correlated subquery on the one query the list already ran, so a list of
    // every task in the agency costs exactly what it cost before — the point of hanging it off
    // `TaskService::query()` rather than walking the collection afterwards.
    DB::enableQueryLog();
    DB::flushQueryLog();

    $rows = $this->tasks->query($this->admin, [])->get();

    $selects = collect(DB::getQueryLog())
        ->filter(fn (array $entry): bool => str_contains($entry['query'], 'from "tasks"'))
        ->count();

    DB::disableQueryLog();

    expect($rows)->not->toBeEmpty()
        ->and($selects)->toBe(1);
});
