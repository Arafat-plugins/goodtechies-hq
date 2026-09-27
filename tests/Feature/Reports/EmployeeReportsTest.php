<?php

use App\Models\Employee;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\ReportService;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| /employee/reports — self-scoped, and absent rather than zero
|--------------------------------------------------------------------------
|
| docs/report-contract.md §5:
|
|   "GET /employee/reports -> Employee/Reports (route name employee.reports)
|    — self-scoped, a different screen and not a filtered copy of the above:
|    My Tasks / Completed / Pending / Overdue as linked counts, a Time
|    section present only for timer roles, and a weekly or monthly work
|    summary picked with ?period=week|month."
|
|   "ABSENT, NOT ZERO. A section the viewer may not have — the Time block
|    for an office employee — is absent from the payload, not sent as
|    zeroes. An office employee's 0h 0m would be a fact about their
|    tracking mode dressed up as a fact about their work."
|
| So the two tests that matter here are: the Time key is MISSING (not null,
| not an empty array) for Yaseen, and there is no way — no query string, no
| parameter — to make this screen answer about somebody else.
|
| Prefixed MYREPORTS_ / myReports*, because Pest declares both globally.
|
*/

/** @return array<string, mixed> the props the screen was rendered with */
function myReportsProps(User $viewer, array $query = []): array
{
    $props = [];

    test()->actingAs($viewer)
        ->get('/employee/reports'.($query === [] ? '' : '?'.http_build_query($query)))
        ->assertOk()
        ->assertInertia(function (Assert $page) use (&$props): void {
            $page->component('Employee/Reports');
            $props = $page->toArray()['props'];
        });

    return $props;
}

beforeEach(function () {
    $this->seed();

    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();
});

it('is reachable at employee.reports and refuses the other two shells', function () {
    expect(route('employee.reports', [], false))->toBe('/employee/reports');

    // The guest first: `actingAs()` sticks for the rest of the test, so a signed-out
    // assertion after a signed-in one is not signed out at all.
    $this->get('/employee/reports')->assertRedirect('/login');

    $this->actingAs($this->yaseen)->get('/employee/reports')->assertOk();
    $this->actingAs($this->admin)->get('/employee/reports')->assertForbidden();
    $this->actingAs($this->accountant)->get('/employee/reports')->assertForbidden();
})->group('phase10', 'reports');

it('gives the four linked counts, each linking to the plate it was counted from', function () {
    $props = myReportsProps($this->yaseen);

    expect(array_column($props['buckets'], 'label'))
        ->toBe(['My tasks', 'Completed', 'Pending', 'Overdue']);

    // TaskSeeder assigns Yaseen ten tasks; one is completed and two are overdue.
    $counts = array_column($props['buckets'], 'count', 'label');
    expect($counts['My tasks'])->toBe(10)
        ->and($counts['Completed'])->toBe(1)
        ->and($counts['Overdue'])->toBe(2);

    expect(array_column($props['buckets'], 'href', 'label'))->toBe([
        'My tasks' => '/employee/my-tasks',
        'Completed' => '/employee/my-tasks?bucket=completed',
        'Pending' => '/employee/my-tasks',
        'Overdue' => '/employee/my-tasks?bucket=overdue',
    ]);
})->group('phase10', 'reports');

it('agrees with the My Tasks page it links to', function () {
    // The counts and the destination are the same bucket, so this is the assertion that keeps
    // "Overdue 2" from opening a list of three.
    $props = myReportsProps($this->yaseen);
    $overdue = collect($props['buckets'])->firstWhere('label', 'Overdue')['count'];

    $this->actingAs($this->yaseen)
        ->get('/employee/my-tasks?bucket=overdue')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('tasks', $overdue));
})->group('phase10', 'reports');

it('switches between this week and this month on ?period=', function () {
    $week = myReportsProps($this->yaseen);
    expect($week['period'])->toBe('week')
        // Sunday to Saturday — the week Admin -> Workforce -> Time already calls "this week".
        ->and(Carbon::parse($week['range']['from'])->dayOfWeek)->toBe(Carbon::SUNDAY)
        ->and(Carbon::parse($week['range']['to'])->dayOfWeek)->toBe(Carbon::SATURDAY);

    $month = myReportsProps($this->yaseen, ['period' => 'month']);
    expect($month['period'])->toBe('month')
        ->and($month['range']['from'])->toBe(Carbon::today()->startOfMonth()->toDateString())
        ->and($month['range']['to'])->toBe(Carbon::today()->endOfMonth()->toDateString())
        ->and($month['range']['label'])->toBe(Carbon::today()->format('F Y'));

    // A period nobody ever offered is this week, not a 422: a stale link asks a question that
    // no longer exists and the useful answer is the plate.
    expect(myReportsProps($this->yaseen, ['period' => 'fortnight'])['period'])->toBe('week');
})->group('phase10', 'reports');

/*
|--------------------------------------------------------------------------
| Absent, not zero
|--------------------------------------------------------------------------
*/

it('leaves the Time section OUT of an office employee\'s payload entirely', function () {
    $props = myReportsProps($this->yaseen);

    // Not null and not an empty object: the key is not there. `0h 0m tracked` beside Yaseen's
    // name would be a fact about his tracking mode wearing the clothes of a fact about his
    // work (report contract §5).
    expect($props)->not->toHaveKey('time');

    $this->actingAs($this->yaseen)
        ->get('/employee/reports')
        ->assertInertia(fn (Assert $page) => $page->missing('time'));

    // He is on the office clock, so the clocked block IS his — otherwise this test would pass
    // on a build that had simply dropped both.
    expect($props)->toHaveKey('clocked');
})->group('phase10', 'reports');

it('gives the Time section to somebody the timer tracks, with their own minutes in it', function () {
    $tapu = Employee::where('employee_number', 'GT-003')->firstOrFail();
    $task = Task::where('title', 'Optimize Home Model pages')->firstOrFail();

    // The three figures below are hand-computed from the two entries this test writes, and the
    // period is a whole week. Phase 10's `WorkSeeder` now seeds Tapu two real months of tracked
    // time, which every screen needed and which this arithmetic cannot include, so his table
    // starts empty here. The seeded month is asserted in `tests/Feature/Database/WorkSeederTest.php`.
    TimeEntry::query()->where('employee_id', $tapu->getKey())->delete();

    TimeEntry::factory()->forEmployee($tapu)->onTask($task)->on(Carbon::today())->create(['duration_seconds' => 5400]);
    TimeEntry::factory()->forEmployee($tapu)->onTask($task)->on(Carbon::today())
        ->create(['duration_seconds' => 1800, 'approved_at' => null]);

    $props = myReportsProps($this->tapu);

    expect($props)->toHaveKey('time');
    expect($props['time']['tracked_minutes'])->toBe(90)
        ->and($props['time']['pending_minutes'])->toBe(30)
        ->and($props['time']['entries'])->toBe(2);

    // And the office block is the one that is absent for him — the mirror of the test above,
    // so neither can pass by the section simply always being present.
    expect($props)->not->toHaveKey('clocked');
})->group('phase10', 'reports');

/*
|--------------------------------------------------------------------------
| Self-scoped: there is nothing to pass
|--------------------------------------------------------------------------
*/

it('cannot be made to answer about anybody else', function () {
    $yaseen = Employee::where('employee_number', 'GT-004')->firstOrFail();
    $tapuEmployee = Employee::where('employee_number', 'GT-003')->firstOrFail();

    $mine = myReportsProps($this->tapu);

    // Every query string somebody might reach for. The counts must not move, because none of
    // them is read: this screen takes no employee parameter at all.
    foreach ([
        ['employee' => $yaseen->getKey()],
        ['employee_id' => $yaseen->getKey()],
        ['user' => $yaseen->user?->getKey()],
        ['mine' => 0],
        ['assignee_id' => $yaseen->getKey()],
    ] as $attempt) {
        expect(myReportsProps($this->tapu, $attempt)['buckets'])->toBe($mine['buckets']);
    }

    // Tapu's nine tasks are his; Yaseen's ten are not, and the two counts are different, so a
    // build that leaked would show it here.
    expect(collect($mine['buckets'])->firstWhere('label', 'My tasks')['count'])->toBe(9);

    // There is no route that takes an id, either — the timesheet next door has one and this
    // deliberately does not.
    $this->actingAs($this->tapu)->get('/employee/reports/'.$yaseen->getKey())->assertNotFound();

    expect($tapuEmployee->getKey())->not->toBe($yaseen->getKey());
})->group('phase10', 'reports');

it('counts an Admin\'s own plate when they use the service, not the agency\'s', function () {
    // `Task::visibleTo()` gives an Admin every task, so the narrowing here is the `mine`
    // filter — the same one My Tasks applies. Asserted at the service because the Admin cannot
    // reach the employee surface.
    $counts = collect(app(ReportService::class)->forEmployee($this->admin)['buckets'])
        ->pluck('count', 'label');

    // Shahadat is assigned three: the brand palette, the onboarding runbook and the
    // case-study deck. The agency has twenty-five.
    expect($counts['My tasks'])->toBe(3)
        ->and($counts['My tasks'])->toBeLessThan(Task::count());
})->group('phase10', 'reports');
