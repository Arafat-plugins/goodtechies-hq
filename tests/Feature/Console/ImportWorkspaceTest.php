<?php

use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\ClientService;
use App\Services\Import\WorkspaceImporter;
use App\Services\TaskService;
use App\Support\ProjectStatus;
use App\Support\TaskStatus;
use App\Support\TimeEntryType;
use Illuminate\Support\Facades\Artisan;

/*
|--------------------------------------------------------------------------
| hq:import — the cutover (Phase 12)
|--------------------------------------------------------------------------
|
| Three properties, and they are the ones the client's data depends on:
|
|   1. A DRY RUN CHANGES NOTHING AND SAYS THE SAME THING. It is the real
|      import inside a rolled-back transaction, so the two reports are
|      compared here line for line rather than trusted.
|   2. IT RUNS TWICE. Every key is identity — a name, a title, (task,
|      employee, manual, the reason) — never prose. Decision 8-21 is why.
|   3. IT REFUSES RATHER THAN GUESSES. An unmapped status is a skip, an
|      unknown assignee is a report, and nobody is ever created.
|
| The fixture is SYNTHETIC. The client's real export is still a GATE A item,
| so these tests prove the command's behaviour, not that their file imports.
|
*/

const IMPORT_CLICKUP = 'tests/fixtures/import/clickup-export.csv';

const IMPORT_ASANA = 'tests/fixtures/import/asana-export.csv';

/** The four top-level rows in the fixture that become clients. */
const IMPORT_CLIENTS = ['Buffalo Modular', 'APH', 'Abbey Heating', 'Woodfordoil'];

beforeEach(function () {
    $this->seed();

    $this->countEverything = fn (): array => [
        'clients' => Client::count(),
        'projects' => Project::count(),
        'tasks' => Task::withTrashed()->count(),
        'time' => TimeEntry::count(),
    ];
});

function importClickUp(array $options = []): string
{
    Artisan::call('hq:import', ['--from' => 'clickup', '--file' => IMPORT_CLICKUP] + $options);

    return Artisan::output();
}

function importAsana(array $options = []): string
{
    Artisan::call('hq:import', ['--from' => 'asana', '--file' => IMPORT_ASANA] + $options);

    return Artisan::output();
}

/*
|--------------------------------------------------------------------------
| 1. The dry run
|--------------------------------------------------------------------------
*/

it('changes nothing at all on a dry run', function () {
    $before = ($this->countEverything)();

    $output = importClickUp(['--dry-run' => true]);

    expect(($this->countEverything)())->toBe($before)
        ->and($output)->toContain('DRY RUN')
        ->and($output)->toContain('the transaction was rolled back');
})->group('phase12');

it('prints the same report on a dry run as on the real run that follows it', function () {
    $dry = importClickUp(['--dry-run' => true]);
    $real = importClickUp();

    // The two differ in exactly the places they must: the header says DRY RUN, the created
    // heading changes tense, and the closing paragraph is different advice. Everything that
    // is a FACT about the import — what is created, what is skipped, what could not be
    // mapped — is identical, because it came from the same run of the same code.
    $facts = fn (string $report): array => array_values(array_filter(
        explode(PHP_EOL, $report),
        fn (string $line): bool => str_starts_with($line, '  line ')
            || str_starts_with($line, '  Clients')
            || str_starts_with($line, '  Projects')
            || str_starts_with($line, '  Tasks')
            || str_starts_with($line, '  Time entries'),
    ));

    expect($facts($dry))->toBe($facts($real))
        ->and($dry)->toContain('WOULD CREATE')
        ->and($real)->toContain(PHP_EOL.'CREATED');
})->group('phase12');

/*
|--------------------------------------------------------------------------
| 2. The mapping
|--------------------------------------------------------------------------
*/

it('turns each top-level ClickUp row into a client and a project of the same name', function () {
    importClickUp();

    foreach (IMPORT_CLIENTS as $name) {
        $client = Client::where('name', $name)->first();

        expect($client)->not->toBeNull()
            ->and(Project::where('client_id', $client->id)->where('name', $name)->exists())->toBeTrue();
    }
})->group('phase12');

it('maps the three statuses the client actually uses, on the task and on the project', function () {
    importClickUp();

    expect(Task::where('title', 'Optimise the Home Model pages')->sole()->status)->toBe(TaskStatus::InProgress)
        ->and(Task::where('title', 'Monthly maintenance report')->sole()->status)->toBe(TaskStatus::Backlog)
        ->and(Task::where('title', 'Fix the sitemap')->sole()->status)->toBe(TaskStatus::Completed)
        // A completed task carries a real completion, not a status string: the columns
        // TaskService writes on the way through the machine are all there.
        ->and(Task::where('title', 'Fix the sitemap')->sole()->completed_at)->not->toBeNull()
        ->and(Project::where('name', 'Buffalo Modular')->sole()->status)->toBe(ProjectStatus::Active)
        ->and(Project::where('name', 'Abbey Heating')->sole()->status)->toBe(ProjectStatus::OnHold)
        ->and(Project::where('name', 'Woodfordoil')->sole()->status)->toBe(ProjectStatus::Completed);
})->group('phase12');

it('walks a completed task through the state machine rather than writing its status', function () {
    importClickUp();

    $task = Task::where('title', 'Fix the sitemap')->sole();

    // Every step is in the activity log, which is only written by TaskService::transition().
    // If anything had written `tasks.status` directly the model guard would have thrown, and
    // if it had gone around the service these lines would not exist.
    $trail = ActivityLog::query()
        ->where('object_type', $task->getMorphClass())
        ->where('object_id', $task->getKey())
        ->orderBy('id')
        ->pluck('description')
        ->all();

    expect($trail)->toContain('Status changed from To do to In progress')
        ->and($trail)->toContain('Status changed from In progress to In review')
        ->and($trail)->toContain('Status changed from In review to Completed');
})->group('phase12');

it('skips a row whose status is not mapped, and says which status and which line', function () {
    $output = importClickUp();

    expect(Task::where('title', 'Legacy redirect map')->exists())->toBeFalse()
        ->and($output)->toContain('status "ARCHIVED" is not mapped')
        // And its parent, whose PROJECT status is not mapped, takes its children with it.
        ->and(Project::where('name', 'Archived Retainer')->exists())->toBeFalse()
        ->and(Client::where('name', 'Archived Retainer')->exists())->toBeFalse()
        ->and($output)->toContain('status "PENDING SIGN-OFF" is not mapped to a project status')
        ->and(Task::where('title', 'Renew the contract')->exists())->toBeFalse()
        ->and($output)->toMatch('/its parent "Archived Retainer" \(line \d+\) was skipped/');
})->group('phase12');

it('refuses to create a top-level row that has no subtasks', function () {
    $output = importClickUp();

    expect(Client::where('name', 'SEO sheet Global template')->exists())->toBeFalse()
        ->and(Project::where('name', 'SEO sheet Global template')->exists())->toBeFalse()
        ->and($output)->toContain('a top-level row with no subtasks');
})->group('phase12');

/*
|--------------------------------------------------------------------------
| 3. Assignees — reported, never invented
|--------------------------------------------------------------------------
*/

it('never creates a user for an assignee it does not recognise', function () {
    $before = User::count();

    $output = importClickUp();

    expect(User::count())->toBe($before)
        ->and(User::where('name', 'Sadia')->exists())->toBeFalse()
        ->and($output)->toContain('"Sadia" matches no employee')
        // The task is still imported. An unknown assignee costs the assignment, not the work.
        ->and(Task::where('title', 'Quarterly backlink audit')->sole()->assignees)->toBeEmpty();
})->group('phase12');

it('matches an assignee by name and by e-mail', function () {
    importClickUp();

    $tapu = User::where('email', 'tapu@goodtechies.test')->sole();
    $yaseen = User::where('email', 'yaseen@goodtechies.test')->sole();

    // "Tapu" in the export is a display name; "yaseen@goodtechies.test" is an address.
    expect(Task::where('title', 'Optimise the Home Model pages')->sole()->assignees->pluck('id')->all())
        ->toBe([$tapu->employee->id])
        ->and(Task::where('title', 'Update the plugin stack')->sole()->assignees->pluck('id')->all())
        ->toBe([$yaseen->employee->id]);
})->group('phase12');

/*
|--------------------------------------------------------------------------
| 4. Time tracked
|--------------------------------------------------------------------------
*/

it('writes tracked time as a manual entry with Part E\'s reason, pending approval', function () {
    importClickUp();

    $task = Task::where('title', 'Optimise the Home Model pages')->sole();
    $entry = TimeEntry::where('task_id', $task->id)->sole();

    expect($entry->entry_type)->toBe(TimeEntryType::Manual)
        ->and($entry->reason)->toBe('imported from ClickUp')
        ->and($entry->duration_seconds)->toBe(11400)
        ->and($entry->project_id)->toBe($task->project_id)
        // `manual_time_requires_approval` is seeded on, and the import does not special-case
        // itself out of it: imported hours are a claim and wait for an Admin like any other.
        ->and($entry->approved_at)->toBeNull()
        ->and($entry->approved_by)->toBeNull();
})->group('phase12');

it('splits more than a working day of tracked time instead of losing the excess', function () {
    importClickUp();

    $task = Task::where('title', 'Site Push Strategy Doc')->sole();
    $entries = TimeEntry::where('task_id', $task->id)->orderBy('work_date')->get();

    // Ten hours. TimerService refuses a single entry longer than a day and this one is longer
    // than a working day, so it is two entries — and the TOTAL is exactly what the export said.
    expect($entries)->toHaveCount(2)
        ->and($entries->sum('duration_seconds'))->toBe(36000)
        ->and($entries->pluck('work_date')->map->toDateString()->all())
        ->toBe(['2026-08-19', '2026-08-20']);
})->group('phase12');

it('does not write time for somebody the app would not let run a timer', function () {
    $output = importClickUp();

    $yaseen = User::where('email', 'yaseen@goodtechies.test')->sole();
    $task = Task::where('title', 'Update the plugin stack')->sole();

    // Yaseen is office-attendance, not remote-timer. TimeEntryPolicy::create says no, so the
    // import says no — and reports the hour it dropped rather than swallowing it.
    expect(TimeEntry::where('task_id', $task->id)->where('employee_id', $yaseen->employee->id)->exists())
        ->toBeFalse()
        ->and($output)->toContain('1h 00m of tracked time was dropped')
        ->and($output)->toContain('does not use the timer');
})->group('phase12');

it('reports tracked time that has nobody to belong to', function () {
    $output = importClickUp();

    expect(TimeEntry::where('reason', 'imported from ClickUp')->count())->toBe(4)
        ->and($output)->toContain('2h 00m of tracked time has no employee to belong to');
})->group('phase12');

/*
|--------------------------------------------------------------------------
| 5. Idempotence
|--------------------------------------------------------------------------
*/

it('creates nothing on a second run', function () {
    importClickUp();
    $after = ($this->countEverything)();

    $output = importClickUp();

    expect(($this->countEverything)())->toBe($after)
        ->and($output)->toContain('Nothing was created')
        ->and($output)->toContain('ALREADY THERE');
})->group('phase12');

it('creates nothing on a third run either, including the split time entries', function () {
    importClickUp();
    importClickUp();
    $after = ($this->countEverything)();

    importClickUp();

    expect(($this->countEverything)())->toBe($after)
        // The ten-hour task is the one a naive "does an entry exist?" would get wrong twice.
        ->and(TimeEntry::where('task_id', Task::where('title', 'Site Push Strategy Doc')->sole()->id)->count())
        ->toBe(2);
})->group('phase12');

it('attaches to a client that is already here rather than creating a second one', function () {
    $admin = User::where('email', 'shahadat@goodtechies.test')->sole();

    app(ClientService::class)->create($admin, ['name' => 'buffalo modular']);

    $output = importClickUp();

    // Keyed on lower(name): a client typed in by hand in a different case is the same client.
    expect(Client::whereRaw('lower(name) = ?', ['buffalo modular'])->count())->toBe(1)
        ->and($output)->toContain('ALREADY THERE');
})->group('phase12');

it('leaves a task that was deleted here deleted, and writes no time onto it', function () {
    importClickUp();

    $task = Task::where('title', 'Optimise the Home Model pages')->sole();
    $admin = User::where('email', 'shahadat@goodtechies.test')->sole();

    TimeEntry::where('task_id', $task->id)->delete();
    app(TaskService::class)->delete($admin, $task);

    $output = importClickUp();

    expect(Task::where('title', 'Optimise the Home Model pages')->exists())->toBeFalse()
        ->and(TimeEntry::where('task_id', $task->id)->exists())->toBeFalse()
        ->and($output)->toContain('has since been deleted here');
})->group('phase12');

/*
|--------------------------------------------------------------------------
| 6. Asana — the second source, and the same writer
|--------------------------------------------------------------------------
*/

it('imports an Asana export into projects with no client', function () {
    $output = importAsana();

    expect(Project::where('name', 'Abbey Heating Website')->sole()->client_id)->toBeNull()
        ->and(Project::where('name', 'Woodfordoil Maintenance')->exists())->toBeTrue()
        ->and(Client::where('name', 'Abbey Heating Website')->exists())->toBeFalse()
        ->and($output)->toContain('nothing above a project, so no clients were created');
})->group('phase12');

it('reads completion from a date in Asana and the board column otherwise', function () {
    importAsana();

    // "Collect the brand assets" sits in the To Do column and has Completed At set. Done is a
    // date in Asana, and it outranks where the card happens to be sitting.
    expect(Task::where('title', 'Collect the brand assets')->sole()->status)->toBe(TaskStatus::Completed)
        ->and(Task::where('title', 'Draft the new service pages')->sole()->status)->toBe(TaskStatus::InProgress);
})->group('phase12');

it('flattens an Asana subtask and says so, and skips a row with no project', function () {
    $output = importAsana();

    $parent = Task::where('title', 'Draft the new service pages')->sole();
    $child = Task::where('title', 'Compress the hero images')->sole();

    expect($child->project_id)->toBe($parent->project_id)
        ->and($output)->toContain('this application does not nest tasks')
        ->and(Task::where('title', 'Orphan with no project')->exists())->toBeFalse()
        ->and($output)->toContain('the row names no project');
})->group('phase12');

it('writes no time entries from an Asana export and says why', function () {
    $output = importAsana();

    expect(TimeEntry::where('reason', WorkspaceImporter::TIME_REASON)->count())->toBe(0)
        ->and($output)->toContain('Not one row in this export carries tracked time');
})->group('phase12');

it('runs an Asana import twice without doubling anything', function () {
    importAsana();
    $after = ($this->countEverything)();

    importAsana();

    expect(($this->countEverything)())->toBe($after);
})->group('phase12');

/*
|--------------------------------------------------------------------------
| 7. Refusals
|--------------------------------------------------------------------------
*/

it('refuses an unknown source, a missing file and a missing --from', function () {
    expect(Artisan::call('hq:import', ['--from' => 'trello', '--file' => IMPORT_CLICKUP]))->toBe(1)
        ->and(Artisan::output())->toContain('Unknown source "trello"');

    expect(Artisan::call('hq:import', ['--file' => IMPORT_CLICKUP]))->toBe(1)
        ->and(Artisan::output())->toContain('--from=clickup or --from=asana');

    expect(Artisan::call('hq:import', ['--from' => 'clickup']))->toBe(1)
        ->and(Artisan::output())->toContain('--file=path/to/export.csv');

    expect(Artisan::call('hq:import', ['--from' => 'clickup', '--file' => 'tests/fixtures/import/nope.csv']))->toBe(1)
        ->and(Artisan::output())->toContain('Could not read');
})->group('phase12');

it('refuses a file that is not the source it was told it is', function () {
    expect(Artisan::call('hq:import', ['--from' => 'asana', '--file' => IMPORT_CLICKUP]))->toBe(1)
        ->and(Artisan::output())->toContain('does not look like a Asana export');
})->group('phase12');

it('refuses to import as somebody who is not an active Admin', function () {
    expect(Artisan::call('hq:import', [
        '--from' => 'clickup',
        '--file' => IMPORT_CLICKUP,
        '--as' => 'tapu@goodtechies.test',
    ]))->toBe(1)
        ->and(Artisan::output())->toContain('is not an active Admin')
        ->and(Client::where('name', 'Buffalo Modular')->exists())->toBeFalse();
})->group('phase12');

/*
|--------------------------------------------------------------------------
| 8. The report is checkable
|--------------------------------------------------------------------------
*/

it('prints the columns it assumed beside the columns the file really had', function () {
    $output = importClickUp(['--dry-run' => true]);

    expect($output)->toContain('ASSUMED COLUMNS')
        ->and($output)->toContain('has NOT been through this command')
        ->and($output)->toContain('Time Logged  |  Time Logged Text')
        ->and($output)->toContain('COLUMNS THIS FILE ACTUALLY HAS')
        ->and($output)->toContain('Task ID, Task Name, Task Content');
})->group('phase12');

it('points every skip and every unmappable at a line in the file', function () {
    $lines = array_values(array_filter(
        explode(PHP_EOL, importClickUp(['--dry-run' => true])),
        fn (string $line): bool => str_starts_with($line, '  line '),
    ));

    expect($lines)->toHaveCount(14);

    foreach ($lines as $line) {
        expect($line)->toMatch('/^  line \d+ {2,}\S.*\S/');
    }
})->group('phase12');
