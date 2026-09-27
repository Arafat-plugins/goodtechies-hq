<?php

use App\Models\Client;
use App\Models\Employee;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\ReportService;
use App\Support\ReportChart;
use App\Support\ReportColumn;
use App\Support\ReportFilters;
use App\Support\ReportFormat;
use App\Support\ReportKey;
use App\Support\ReportResult;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Scoping — what a report must never contain
|--------------------------------------------------------------------------
|
| docs/report-contract.md §4 rule 1: every query is scoped by the model's
| existing visibleTo(). No report writes a new access rule, so the test for
| each one is the same test the list screens already have: a viewer whose
| scope excludes a project or a person sees no row for it AND NO TRACE OF
| ITS NAME OR ID anywhere in the encoded payload.
|
| The recursive-string check is deliberate and is borrowed from
| tests/Feature/Search/SearchScopingTest.php: a check for a named field
| passes the day the value arrives under a different name — inside a chart
| legend, inside a note, inside an empty-state sentence. Encoding the whole
| body and searching it catches all three.
|
| The other half of the file is the rule that is easiest to get wrong in the
| opposite direction: a filter id the viewer may not see is NOT an error. A
| 404 there would confirm that the row exists (Part C §1).
|
| Prefixed SCOPE_ / scope*, because Pest declares constants and functions
| globally (AGENTS.md).
|
*/

/** Names a viewer outside those projects must never be shown, whatever the column is called. */
const SCOPE_HIDDEN_FROM_TAPU = [
    'APH — Website Maintenance',
    'abc.com — Monthly Maintenance',
    'GoodTechies HQ — Internal',
    'Buffalo Modular — Website Development',
    'Buffalo Modular — Website Maintenance',
];

/** The client rows Part C §2 replaces with a domain for anybody without `clients.view_full`. */
const SCOPE_CLIENT_NAMES = [
    'Buffalo Modular Homes',
    'Heat Gap Heating & Plumbing',
    'APH St Albans',
    'ABC Ltd',
];

function scopeBuild(ReportKey $key, User $viewer, array $input = []): ReportResult
{
    return app(ReportService::class)->build($key, $viewer, ReportFilters::for($key, $input, Carbon::today()));
}

/** The whole payload as one string, so a value hiding under any key at any depth is found. */
function scopeEncoded(ReportResult $result): string
{
    return json_encode($result, JSON_THROW_ON_ERROR);
}

/** @return list<string> */
function scopeColumnKeys(ReportResult $result): array
{
    return array_map(fn (ReportColumn $column): string => $column->key, $result->columns);
}

function scopeWindow(): array
{
    return [
        'from' => Carbon::today()->subDays(45)->toDateString(),
        'to' => Carbon::today()->addDays(35)->toDateString(),
    ];
}

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
});

/*
|--------------------------------------------------------------------------
| A project outside the scope leaves no trace
|--------------------------------------------------------------------------
*/

it('gives Tapu only his own two projects, and names none of the others anywhere', function () {
    $result = scopeBuild(ReportKey::Project, $this->tapu, scopeWindow());

    expect(array_column($result->rows, 'project'))
        ->toBe(['Buffalo Modular — SEO', 'Heat Gap — SEO Retainer']);

    $body = scopeEncoded($result);

    foreach (SCOPE_HIDDEN_FROM_TAPU as $name) {
        expect($body)->not->toContain($name);
    }

    // The ids are as telling as the names: an "overdue on project 6" figure would leak the
    // same fact one indirection away.
    foreach (Project::whereIn('name', SCOPE_HIDDEN_FROM_TAPU)->pluck('id') as $id) {
        expect($result->rows)->not->toContain(['project_id' => $id]);
    }
})->group('phase10', 'reports');

it('drops the client column entirely for a viewer without clients.view_full', function () {
    // Part C §2 and the contract's §5: a field they may not see is ABSENT, not null and not
    // masked. The domain stands in its place, which is the positive half of the same rule.
    $result = scopeBuild(ReportKey::Project, $this->tapu, scopeWindow());

    expect(scopeColumnKeys($result))->not->toContain('client');
    expect(scopeColumnKeys($result))->toContain('domain');
    expect($result->rows[0]['domain'])->toBe('buffalomodular.com');

    foreach ($result->rows as $row) {
        expect($row)->not->toHaveKey('client');
    }

    foreach (SCOPE_CLIENT_NAMES as $client) {
        expect(scopeEncoded($result))->not->toContain($client);
    }

    // And the Admin, who may, still gets it — otherwise this test would pass on a build that
    // had simply dropped the column for everybody.
    expect(scopeColumnKeys(scopeBuild(ReportKey::Project, $this->admin, scopeWindow())))
        ->toContain('client');
})->group('phase10', 'reports');

it('counts only Tapu\'s own tasks in the Task and Overdue reports', function () {
    $task = scopeBuild(ReportKey::Task, $this->tapu, scopeWindow());
    $overdue = scopeBuild(ReportKey::Overdue, $this->tapu);

    // Nine dated tasks are assigned to him; the agency has twenty-four.
    expect($task->totals['tasks'])->toBe(9);
    expect($overdue->rows)->toHaveCount(4);

    foreach ([$task, $overdue] as $result) {
        $body = scopeEncoded($result);

        foreach (SCOPE_HIDDEN_FROM_TAPU as $name) {
            expect($body)->not->toContain($name);
        }
    }

    // Nobody else's name reaches his overdue list either — he is the only assignee on it.
    expect(array_unique(array_column($overdue->rows, 'assignees')))->toBe(['Tapu']);
})->group('phase10', 'reports');

it('shows a task outside the scope to nobody, even when its project is filtered for by id', function () {
    $aph = Project::where('name', 'APH — Website Maintenance')->firstOrFail();

    $result = scopeBuild(ReportKey::Task, $this->tapu, scopeWindow() + ['project' => $aph->getKey()]);

    // Not a refusal and not a 404 — an empty report. A refusal would confirm the project is
    // real (Part C §1).
    expect($result->rows)->toBe([])
        ->and($result->totals)->not->toBeNull();

    expect(scopeEncoded($result))->not->toContain('APH');
})->group('phase10', 'reports');

/*
|--------------------------------------------------------------------------
| A person outside the scope is absent, not refused
|--------------------------------------------------------------------------
*/

it('gives a viewer without attendance.manage_others only themselves in the workforce reports', function () {
    // `Employee::attendanceVisibleTo()` is the scope. Tapu holds no manage-others key, so the
    // list is one row — his. The ROUTE is 403 for him in practice; this is the service's own
    // answer behind it, which is what stops a future caller widening the list.
    $result = scopeBuild(ReportKey::EmployeeWork, $this->tapu, scopeWindow());

    expect(array_column($result->rows, 'employee'))->toBe(['Tapu']);

    foreach (['Shahadat Hossain', 'Faruk Ahmed', 'Yaseen', 'Accountant'] as $name) {
        expect(scopeEncoded($result))->not->toContain($name);
    }
})->group('phase10', 'reports');

it('returns nothing, not an error, for an employee id the viewer may not see', function () {
    $yaseen = Employee::where('employee_number', 'GT-004')->firstOrFail();

    $result = scopeBuild(ReportKey::EmployeeWork, $this->tapu, scopeWindow() + ['employee' => $yaseen->getKey()]);

    expect($result->rows)->toBe([]);
    expect(scopeEncoded($result))->not->toContain('Yaseen');
})->group('phase10', 'reports');

it('answers 200 with an empty report for a filter id the viewer may not see, never 404 or 403', function () {
    $aph = Project::where('name', 'APH — Website Maintenance')->firstOrFail();
    $abcClient = Client::where('name', 'ABC Ltd')->firstOrFail();
    $yaseen = Employee::where('employee_number', 'GT-004')->firstOrFail();

    // On the admin surface the Admin sees everything, so the sharp version of this test is the
    // one above at the service. This is the HTTP half: a nonsense id and an id that exists but
    // is out of reach have to answer the same way as each other — 200.
    foreach ([['project' => $aph->getKey()], ['client' => $abcClient->getKey()], ['employee' => $yaseen->getKey()], ['project' => 999999]] as $query) {
        $this->actingAs($this->admin)
            ->get('/admin/reports/task?'.http_build_query($query))
            ->assertOk();
    }
})->group('phase10', 'reports');

it('keeps another employee\'s tracked time out of the Time report', function () {
    $tapu = Employee::where('employee_number', 'GT-003')->firstOrFail();
    $yaseen = Employee::where('employee_number', 'GT-004')->firstOrFail();
    $day = Carbon::today()->subDays(2);

    $tapuTask = Task::where('title', 'Optimize Home Model pages')->firstOrFail();
    $yaseenTask = Task::where('title', 'Investigate the slow gallery page load')->firstOrFail();

    // The three totals below are hand-computed from the two entries this test writes. Phase
    // 10's `WorkSeeder` now seeds two real months of Tapu's tracked time inside this window —
    // what every Phase 4 screen needed — so the table starts empty here and the scoping rule,
    // which is what this test is about, is read off numbers a human added up. The seeded rows
    // are asserted in `tests/Feature/Database/WorkSeederTest.php`.
    TimeEntry::query()->delete();

    TimeEntry::factory()->forEmployee($tapu)->onTask($tapuTask)->on($day)->create(['duration_seconds' => 3600]);
    TimeEntry::factory()->forEmployee($yaseen)->onTask($yaseenTask)->on($day)->create(['duration_seconds' => 7200]);

    $window = ['from' => $day->toDateString(), 'to' => Carbon::today()->toDateString()];

    // The Admin sees both — three hours.
    expect(scopeBuild(ReportKey::Time, $this->admin, $window)->totals['tracked'])->toBe(180);

    // Tapu sees only his own hour, and Yaseen's project never appears.
    $mine = scopeBuild(ReportKey::Time, $this->tapu, $window);
    expect($mine->totals['tracked'])->toBe(60);
    expect(scopeEncoded($mine))->not->toContain('Buffalo Modular — Website Maintenance');
})->group('phase10', 'reports');

it('keeps another employee\'s payroll line out of the Payroll report', function () {
    // `PayrollItem::visibleTo()` is the scope. Tapu holds `payroll.view_own` only, so the
    // aggregate is his own line — 1200, not the agency's 7400. The route refuses him; this is
    // the scope underneath, which is what makes the refusal belt-and-braces rather than sole.
    $result = scopeBuild(ReportKey::Payroll, $this->tapu, ['from' => '2026-09-01', 'to' => '2026-09-30']);

    expect($result->rows[0]['employees'])->toBe(1)
        ->and($result->rows[0]['net'])->toBe('1200.00');

    expect(scopeBuild(ReportKey::Payroll, $this->admin, ['from' => '2026-09-01', 'to' => '2026-09-30'])->rows[0]['net'])
        ->toBe('7400.00');
})->group('phase10', 'reports');

/*
|--------------------------------------------------------------------------
| No score, anywhere
|--------------------------------------------------------------------------
*/

it('offers no column and no chart that is a score, a rating or a ranking', function () {
    // Part H §1, asserted over the things a report PRINTS AS A FIGURE — the column headings
    // and the chart titles. Deliberately not over the whole body: TaskSeeder has a task called
    // "Monthly rankings report — Buffalo Modular", and a test that failed on a client's own
    // SEO deliverable would be a test nobody could keep.
    $forbidden = ['score', 'productivity', 'efficiency', 'rating', 'ranking', 'leaderboard', 'target'];

    foreach (ReportKey::cases() as $key) {
        $result = scopeBuild($key, $this->admin, scopeWindow());

        $printed = [
            ...array_map(fn (ReportColumn $column): string => $column->label, $result->columns),
            ...array_map(fn (ReportChart $chart): string => $chart->title, $result->charts),
        ];

        foreach ($printed as $label) {
            foreach ($forbidden as $word) {
                // Whole words. "Operating result" contains "rating", and a substring match
                // would have made the Finance report's own heading a Part H violation.
                expect(strtolower($label))->not->toMatch('/\b'.$word.'\b/');
            }
        }
    }
})->group('phase10', 'reports');

it('divides nobody by anybody — no report puts a percentage beside a person', function () {
    // Part H §1 and Part B §3 rule 12: no productivity score anywhere. The rule that actually
    // matters is not "percentages are rare", it is **what a percentage is next to**. A share of
    // task STATES is a fact about the work; the same number beside somebody's name is a target,
    // whatever the heading says — and it is a target they did not agree to and cannot argue
    // with.
    //
    // So this asserts two things, and the first is the one with teeth:
    //
    //   1. a report whose rows are PEOPLE carries no `Percent` column at all; and
    //   2. the full inventory of percentage columns in the catalogue is exactly this list,
    //      so a new one arriving is a decision somebody makes on purpose rather than a diff
    //      nobody read.
    //
    // Part D §2 requires completion rate and overdue rate on **Performance**, which is why that
    // report has two of them — and is also why §2 says in the same sentence that Performance is
    // per project and never per person.
    $expected = [
        'task' => ['share'],
        'performance' => ['completed_share', 'overdue_share'],
    ];

    $found = [];

    foreach (ReportKey::cases() as $key) {
        $result = scopeBuild($key, $this->admin, scopeWindow());

        $percentColumns = array_values(array_map(
            fn (ReportColumn $column): string => $column->key,
            array_filter(
                $result->columns,
                fn (ReportColumn $column): bool => $column->format === ReportFormat::Percent,
            ),
        ));

        if ($percentColumns !== []) {
            $found[$key->value] = $percentColumns;

            // Rule 1. A row about a person is a row with a person's name in it, which in this
            // catalogue is always the column keyed `employee`.
            $namesAPerson = array_filter(
                $result->columns,
                fn (ReportColumn $column): bool => $column->key === 'employee',
            );

            expect($namesAPerson)->toBe([], $key->value.' puts a percentage on a row that names a person');
        }
    }

    // Rule 2.
    expect($found)->toBe($expected);
})->group('phase10', 'reports');
