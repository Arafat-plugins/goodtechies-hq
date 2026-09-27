<?php

use App\Models\Conversation;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\Meeting;
use App\Models\Message;
use App\Models\Project;
use App\Models\User;
use App\Services\ConversationService;
use App\Services\LeaveService;
use App\Services\MeetingService;
use App\Services\ReportService;
use App\Support\ReportColumn;
use App\Support\ReportFilters;
use App\Support\ReportKey;
use App\Support\ReportResult;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Leave, Meeting and In-app coordination against the seeded data
|--------------------------------------------------------------------------
|
| The three of the eight that are not about tasks. Each one is checked at
| two moments: on the freshly seeded database, where the row has to be real
| rather than a nought, and after the application itself has written
| something through its own service — an approved leave request, a meeting's
| notes and action item, a direct message — because a report that counts
| nothing correctly has not been tested.
|
| The DM is the sharpest case in the file: two other people's conversation
| must be invisible to an Admin, who can see every project, every task and
| every meeting in the agency. `ConversationPolicy` is what says so, and this
| report asks it rather than restating it.
|
| Prefixed SLICEB_ / sliceb*, because Pest declares constants and functions
| globally (AGENTS.md). `lmcBuild()` and friends live in
| ProjectCutReportsTest.php, which is the same folder and the same suite.
|
*/

function lmcWindow(): array
{
    return [
        'from' => Carbon::today()->subDays(45)->toDateString(),
        'to' => Carbon::today()->addDays(35)->toDateString(),
    ];
}

function lmcBuild(ReportKey $key, User $viewer, array $input = []): ReportResult
{
    return app(ReportService::class)->build($key, $viewer, ReportFilters::for($key, $input, Carbon::today()));
}

/** One row of a result, found by the value of a named cell. */
function lmcRow(ReportResult $result, string $key, string $value): array
{
    foreach ($result->rows as $row) {
        if ((string) $row[$key] === $value) {
            return $row;
        }
    }

    throw new RuntimeException("No row where {$key} = {$value}.");
}

/** @return list<string> */
function lmcColumnKeys(ReportResult $result): array
{
    return array_map(fn (ReportColumn $column): string => $column->key, $result->columns);
}

function lmcLeaveDays(Employee $employee, Carbon $from, Carbon $to): int
{
    // The application's own answer to "which dates are leave" — this employee's schedule, the
    // same expansion the approval, the balance and payroll all used.
    return count(app(LeaveService::class)->leaveDays($employee, $from, $to));
}

function lmcMeetingReport(User $viewer): ReportResult
{
    return app(ReportService::class)->build(
        ReportKey::Meeting,
        $viewer,
        ReportFilters::for(ReportKey::Meeting, [
            'from' => Carbon::today()->subDays(45)->toDateString(),
            'to' => Carbon::today()->addDays(35)->toDateString(),
        ], Carbon::today()),
    );
}

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();
});

/*
|--------------------------------------------------------------------------
| Leave
|--------------------------------------------------------------------------
*/

it('lists everybody with the balances LeaveSeeder opened, even before anybody has taken a day', function () {
    $result = lmcBuild(ReportKey::Leave, $this->admin, lmcWindow());

    // By name, and everybody in scope — including the people who took nothing. "How much is
    // left" is an answer about them whether or not they were away, which is why this report
    // does not drop a quiet row the way Attendance drops an unrecorded day.
    expect(array_column($result->rows, 'employee'))
        ->toBe(['Accountant', 'Faruk Ahmed', 'Shahadat Hossain', 'Tapu', 'Yaseen']);

    // LeaveSeeder's opening balances: Annual 15, Sick 10, Emergency 5, Personal 3.
    $tapu = lmcRow($result, 'employee', 'Tapu');
    expect($tapu['left_annual'])->toBe(15)
        ->and($tapu['left_sick'])->toBe(10)
        ->and($tapu['left_emergency'])->toBe(5)
        ->and($tapu['left_personal'])->toBe(3)
        ->and($tapu['requests'])->toBe(0)
        ->and($tapu['days'])->toBe(0);

    expect($result->totals['left_annual'])->toBe(75)
        ->and($result->totals['days'])->toBe(0);

    // The two uncapped types have no balance to report, so they are not columns.
    expect(lmcColumnKeys($result))
        ->toBe(['employee', 'requests', 'days', 'approved', 'awaiting', 'left_annual', 'left_sick', 'left_emergency', 'left_personal']);
})->group('phase10', 'reports');

it('counts an approved leave request in the days the application itself worked out', function () {
    $leave = app(LeaveService::class);
    $yaseen = Employee::where('employee_number', 'GT-004')->firstOrFail();
    $annual = LeaveType::where('name', 'Annual')->firstOrFail();

    // Future dates on purpose: WorkSeeder has already written attendance for the past, and an
    // approval writes an attendance row per working day.
    $from = Carbon::today()->addDays(7);
    $to = Carbon::today()->addDays(9);
    $expected = lmcLeaveDays($yaseen, $from, $to);

    $request = $leave->apply($this->yaseen, $yaseen, $annual, $from, $to, 'A long weekend.');
    $leave->approve($this->admin, $request);

    $result = lmcBuild(ReportKey::Leave, $this->admin, [
        'from' => $from->toDateString(),
        'to' => $to->toDateString(),
    ]);

    $row = lmcRow($result, 'employee', 'Yaseen');

    expect($row['requests'])->toBe(1)
        ->and($row['days'])->toBe($expected)
        ->and($row['approved'])->toBe($expected)
        ->and($row['awaiting'])->toBe(0)
        // The balance the approval actually spent, read back from the balance itself.
        ->and($row['left_annual'])->toBe(15 - $expected);

    // Nobody else moved.
    expect(lmcRow($result, 'employee', 'Tapu')['days'])->toBe(0);
    expect($result->totals['days'])->toBe($expected);

    // "Of what kind" is the donut, and it says Annual.
    expect($result->charts[0]->title)->toBe('Leave days by type');
    expect($result->charts[0]->series)->toBe([['label' => 'Annual', 'value' => $expected]]);
})->group('phase10', 'reports');

it('counts a pending request beside the approved days and never inside them', function () {
    $leave = app(LeaveService::class);
    $yaseen = Employee::where('employee_number', 'GT-004')->firstOrFail();
    $sick = LeaveType::where('name', 'Sick')->firstOrFail();

    $from = Carbon::today()->addDays(14);
    $to = Carbon::today()->addDays(15);
    $expected = lmcLeaveDays($yaseen, $from, $to);

    $leave->apply($this->yaseen, $yaseen, $sick, $from, $to, 'Not feeling right.');

    $row = lmcRow(lmcBuild(ReportKey::Leave, $this->admin, [
        'from' => $from->toDateString(),
        'to' => $to->toDateString(),
    ]), 'employee', 'Yaseen');

    expect($row['days'])->toBe($expected)
        ->and($row['awaiting'])->toBe($expected)
        ->and($row['approved'])->toBe(0)
        // A pending request has spent nothing yet.
        ->and($row['left_sick'])->toBe(10);
})->group('phase10', 'reports');

it('gives a viewer without leave.approve only their own line', function () {
    // `Employee::leaveVisibleTo()` is the scope, and it is the LEAVE cell of Part C §1 rather
    // than the attendance one. Tapu holds `leave.apply` only, so the report is one row — his.
    $result = lmcBuild(ReportKey::Leave, $this->tapu, lmcWindow());

    expect(array_column($result->rows, 'employee'))->toBe(['Tapu']);

    foreach (['Shahadat Hossain', 'Faruk Ahmed', 'Yaseen', 'Accountant'] as $name) {
        expect(json_encode($result, JSON_THROW_ON_ERROR))->not->toContain($name);
    }
})->group('phase10', 'reports');

/*
|--------------------------------------------------------------------------
| Meeting
|--------------------------------------------------------------------------
*/

it('registers every seeded meeting with its room, its length and its status', function () {
    $result = lmcMeetingReport($this->admin);

    // MeetingSeeder's four, oldest first: the kick-off three days ago, tomorrow's review, the
    // stand-up, and the walkthrough that was called off.
    expect(array_column($result->rows, 'meeting'))->toBe([
        'Heat Gap — retainer kick-off',
        'Buffalo Modular — quarterly review',
        'Weekly team stand-up',
        'APH — design walkthrough',
    ]);

    expect(array_column($result->rows, 'minutes'))->toBe([45, 60, 30, 45]);
    // The organiser has a seat, so the room is participants plus them.
    expect(array_column($result->rows, 'people'))->toBe([2, 4, 4, 2]);
    expect(array_column($result->rows, 'status'))->toBe(['waiting', 'waiting', 'waiting', 'cancelled']);

    // Nothing has been written up and nothing has become a task yet, which is a real nought.
    expect(array_unique(array_column($result->rows, 'notes')))->toBe(['—']);
    expect($result->totals['action_items'])->toBe(0);

    // A cancelled meeting keeps the length it was scheduled for; the status is what says it
    // did not happen, and the footer is the sum of the column above it.
    expect($result->totals['minutes'])->toBe(180)
        ->and($result->totals['people'])->toBe(12)
        ->and($result->totals['meeting'])->toBe('4 meetings');

    // The stand-up has no project at all.
    expect(lmcRow($result, 'meeting', 'Weekly team stand-up')['project'])->toBe('—');
    expect(lmcRow($result, 'meeting', 'Heat Gap — retainer kick-off')['project'])
        ->toBe('Heat Gap — SEO Retainer');
})->group('phase10', 'reports');

it('says what came out of a meeting once notes and an action item exist', function () {
    $meetings = app(MeetingService::class);
    $meeting = Meeting::where('title', 'Heat Gap — retainer kick-off')->firstOrFail();

    $meetings->recordNotes($this->admin, $meeting, 'Scope agreed.', 'Reporting monthly.');
    $meetings->convertActionItem($this->admin, $meeting, [
        'title' => 'Send the Search Console invitation',
        'due_date' => Carbon::today()->addDays(3)->toDateString(),
    ]);

    $row = lmcRow(lmcMeetingReport($this->admin), 'meeting', 'Heat Gap — retainer kick-off');

    expect($row['notes'])->toBe('Recorded')
        ->and($row['action_items'])->toBe(1)
        // The count opens the meeting, which is where the item itself is.
        ->and($row['meeting_href'])->toBe('/meetings/'.$meeting->getKey());
})->group('phase10', 'reports');

it('shows a participant the meeting and never the project they are not on', function () {
    // MeetingSeeder seeds this case on purpose: Yaseen is in the Buffalo review and is not on
    // the Buffalo SEO project. `MeetingService::linkedContextFor()` is what decides, so the
    // cell is an em dash — the same answer the meeting page gives him.
    $result = lmcMeetingReport($this->yaseen);

    expect(array_column($result->rows, 'meeting'))->toBe([
        'Buffalo Modular — quarterly review',
        'Weekly team stand-up',
        'APH — design walkthrough',
    ]);

    expect(lmcRow($result, 'meeting', 'Buffalo Modular — quarterly review')['project'])->toBe('—');

    // He is on APH, so that one he may be told about.
    expect(lmcRow($result, 'meeting', 'APH — design walkthrough')['project'])
        ->toBe('APH — Website Maintenance');

    expect(json_encode($result, JSON_THROW_ON_ERROR))->not->toContain('Buffalo Modular — SEO');

    // And the kick-off, which he is not in, is not in his register at all.
    expect(json_encode($result, JSON_THROW_ON_ERROR))->not->toContain('retainer kick-off');
})->group('phase10', 'reports');

it('gives the Accountant no meetings at all, because they hold no meetings.use', function () {
    // `Meeting::visibleTo()`'s first line, asked here rather than restated.
    expect(lmcMeetingReport($this->accountant)->rows)->toBe([]);
})->group('phase10', 'reports');

/*
|--------------------------------------------------------------------------
| In-app coordination
|--------------------------------------------------------------------------
*/

it('counts the seeded task discussions per project, and the channels beside them', function () {
    $result = lmcBuild(ReportKey::InAppCoordination, $this->admin, lmcWindow());

    // Seven projects, then the two company-wide channels. No DM row: the Admin is in none.
    expect(array_column($result->rows, 'where'))->toBe([
        'APH — Website Maintenance',
        'Buffalo Modular — SEO',
        'Buffalo Modular — Website Development',
        'Buffalo Modular — Website Maintenance',
        'GoodTechies HQ — Internal',
        'Heat Gap — SEO Retainer',
        'abc.com — Monthly Maintenance',
        'Team channel',
        'Announcements',
    ]);

    // TaskSeeder's three threads: 3 + 2 messages on two Buffalo SEO tasks, 2 on a Buffalo
    // maintenance task. Seven messages, all of them in task discussions.
    expect(lmcRow($result, 'where', 'Buffalo Modular — SEO')['tasks'])->toBe(5);
    expect(lmcRow($result, 'where', 'Buffalo Modular — Website Maintenance')['tasks'])->toBe(2);
    expect($result->totals['tasks'])->toBe(7)
        ->and($result->totals['channel'])->toBe(0)
        ->and($result->totals['messages'])->toBe(7);

    // A project nobody has talked about is a row of noughts on purpose — that silence is the
    // finding AC6 is about, and dropping the row would hide it.
    expect(lmcRow($result, 'where', 'Heat Gap — SEO Retainer')['messages'])->toBe(0);

    // Per week, in the application's own week vocabulary, and adding up to the same seven.
    $weekly = $result->charts[0];
    expect($weekly->title)->toBe('Messages per week')
        ->and(array_sum(array_column($weekly->series, 'value')))->toBe(7);

    foreach ($weekly->series as $bar) {
        expect($bar['label'])->toMatch('/^Week \d{1,2}, \d{4}$/');
    }

    // And the split the report exists to show, as a count and never as a percentage.
    expect($result->charts[1]->series)->toBe([
        ['label' => 'Task and project conversations', 'value' => 7],
    ]);
})->group('phase10', 'reports');

it('counts a project channel message under the project it belongs to', function () {
    $conversation = Conversation::query()
        ->forProject(Project::where('name', 'Heat Gap — SEO Retainer')->firstOrFail())
        ->firstOrFail();

    Message::factory()->count(2)->create([
        'conversation_id' => $conversation->getKey(),
        'author_id' => $this->admin->getKey(),
    ]);

    $row = lmcRow(
        lmcBuild(ReportKey::InAppCoordination, $this->admin, lmcWindow()),
        'where',
        'Heat Gap — SEO Retainer',
    );

    expect($row['channel'])->toBe(2)
        ->and($row['tasks'])->toBe(0)
        ->and($row['messages'])->toBe(2)
        ->and($row['where_href'])->toBe('/messages/'.$conversation->getKey());
})->group('phase10', 'reports');

it('never counts or names a direct message between two other people, not even for an Admin', function () {
    $dm = app(ConversationService::class)->dmBetween($this->tapu, $this->yaseen);

    Message::factory()->count(3)->create([
        'conversation_id' => $dm?->getKey(),
        'author_id' => $this->tapu->getKey(),
    ]);

    $admin = lmcBuild(ReportKey::InAppCoordination, $this->admin, lmcWindow());

    // The Admin sees every project, every task and every meeting in the agency — and not this.
    expect($admin->totals['messages'])->toBe(7);
    expect(array_column($admin->rows, 'where'))->not->toContain('Direct messages');
    expect(json_encode($admin, JSON_THROW_ON_ERROR))->not->toContain('/messages/'.$dm?->getKey());

    // Tapu, who is in it, gets the row — and only his own two projects beside it.
    $tapu = lmcBuild(ReportKey::InAppCoordination, $this->tapu, lmcWindow());

    expect(array_column($tapu->rows, 'where'))->toBe([
        'Buffalo Modular — SEO',
        'Heat Gap — SEO Retainer',
        'Team channel',
        'Announcements',
        'Direct messages',
    ]);

    expect(lmcRow($tapu, 'where', 'Direct messages'))
        ->toBe([
            'where' => 'Direct messages',
            'where_href' => '/messages',
            'kind' => 'Team and DMs',
            'tasks' => 0,
            'channel' => 3,
            'messages' => 3,
        ]);

    // His five task-discussion messages are the ones on his own tasks, and no project he
    // cannot open is named.
    expect($tapu->totals['messages'])->toBe(8);
    expect(json_encode($tapu, JSON_THROW_ON_ERROR))->not->toContain('APH');
})->group('phase10', 'reports');

it('counts only messages posted inside the window', function () {
    $before = lmcBuild(ReportKey::InAppCoordination, $this->admin, [
        'from' => Carbon::today()->subDays(45)->toDateString(),
        'to' => Carbon::today()->subDays(10)->toDateString(),
    ]);

    // TaskSeeder dates its threads three, two and one day before today, so a window that ends
    // ten days ago contains none of them — and the rows are still there, saying nought.
    expect($before->totals['messages'])->toBe(0);
    expect($before->rows)->not->toBe([]);
    expect($before->charts)->toHaveCount(2);

    // An empty chart is dropped on the way out rather than drawn as a legend with no slices.
    $encoded = json_decode(json_encode($before, JSON_THROW_ON_ERROR), true);
    expect($encoded['charts'])->toBe([]);
})->group('phase10', 'reports');
