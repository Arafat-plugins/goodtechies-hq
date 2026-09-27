<?php

namespace App\Services\Import;

use App\Events\ProjectCancelled;
use App\Events\TaskAssigned;
use App\Events\TaskCompleted;
use App\Events\TaskReassigned;
use App\Events\TaskStatusChanged;
use App\Events\TaskSubmittedForReview;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\ClientService;
use App\Services\Import\Draft\ProjectDraft;
use App\Services\Import\Draft\TaskDraft;
use App\Services\Import\Draft\WorkspaceDraft;
use App\Services\ProjectService;
use App\Services\TaskService;
use App\Services\TimerService;
use App\Support\BillingType;
use App\Support\ProjectStatus;
use App\Support\ProjectType;
use App\Support\TaskStatus;
use App\Support\TimeEntryType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

/**
 * The one thing that writes an import to the database.
 *
 * ## Nothing here writes a row
 *
 * Every client goes through `ClientService`, every project through `ProjectService`, every task
 * through `TaskService`, every time entry through `TimerService::manualEntry()` — the same call
 * `POST /employee/time-entries` makes. That is Phase 12's rule 1, and it is not about tidiness:
 * an importer with its own INSERT is a second definition of what a valid task is, and the day
 * the state machine gains a rule, the import is the place that does not have it.
 *
 * It follows that the import obeys the policies. The actor must be allowed to create a client, a
 * project and a task, and must be the reviewer of what it completes — which is why the command
 * insists on an Admin. The import cannot do anything a person at a keyboard could not do.
 *
 * ## The dry run IS the run
 *
 * `run()` opens a transaction, does the entire import, fills the report, and — when `$dryRun` —
 * throws DryRunComplete to roll it back. There is no second code path and no estimator, so a dry
 * run cannot describe something the real run would not do. The report object survives the
 * rollback because it is a PHP object, not a row.
 *
 * ## Idempotence: keyed on identity, never on prose
 *
 * Decision 8-21 is the memory this is built on. `FinanceSeeder` keyed its idempotence on a NOTE;
 * two notes were edited until they matched, two rows merged, and **$560 of September's income
 * silently vanished**. So:
 *
 *   - a **client** is `lower(name)` — the only identity a ClickUp row carries into this app
 *   - a **project** is `(client_id, lower(name))`
 *   - a **task** is `(project_id, lower(title))`, including archived and soft-deleted ones: a
 *     task somebody deleted after the first run stays deleted
 *   - a **time entry** is `(task_id, employee_id, entry_type = manual, reason = TIME_REASON)` —
 *     the reason is a CONSTANT in this file, dictated by Part E, not free text somebody types
 *
 * A second run therefore creates nothing. Two things follow that the report says out loud:
 * renaming a task in ClickUp between runs makes it a NEW task here, and editing the reason on an
 * imported time entry makes it importable again. Both are visible in the dry run.
 *
 * ## Why imported work does not ring anybody's bell
 *
 * `Event::forget()` on the six events an import can fire, unless `--notify` is passed. A cutover
 * is a restatement of work people have had for months: three hundred "you have been assigned a
 * task" notifications at nine in the morning is not news, it is an outage of the bell. It also
 * keeps the dry run honest — `TaskStatusChanged` is `ShouldBroadcast`, and a queued broadcast
 * job does not roll back with the transaction that dispatched it.
 *
 * Nothing else is suppressed: `activity_logs` and `audit_logs` are written by the services on
 * every row, exactly as they would be for work done by hand.
 */
final class WorkspaceImporter
{
    /** Part E's words, verbatim, and the second half of the time entries' idempotence key. */
    public const TIME_REASON = 'imported from ClickUp';

    /**
     * A task cannot reach Completed without one (`TaskService::assertCompletable`), and an export
     * has nothing to put in it. It says where it came from rather than describing work nobody
     * here did.
     */
    public const WORK_SUMMARY = 'Imported from the previous workspace; this task was already finished there.';

    /** A cancelled task needs a reason, for the same reason a cancelled one always does. */
    public const CANCEL_REASON = 'Imported as cancelled from the previous workspace.';

    /**
     * The longest single imported time entry: one working day.
     *
     * A ClickUp task can carry months of tracked time, and `TimerService` refuses anything over
     * 24 hours because a single session that long is a bug. Truncating would lose hours silently
     * — the thing decision 8-21 is about — so a big total is split into working-day entries on
     * consecutive days instead. The TOTAL is exactly what the export said; the days it is spread
     * over are this importer's arithmetic and the report says so.
     */
    private const CHUNK_SECONDS = 8 * 3600;

    /** A year of working days. Past this, the number is not a duration, it is a parse error. */
    private const MAX_CHUNKS = 260;

    private bool $muted = false;

    /** @var array<int, Employee>|null */
    private ?array $assignable = null;

    public function __construct(
        private readonly ClientService $clients,
        private readonly ProjectService $projects,
        private readonly TaskService $tasks,
        private readonly TimerService $timer,
        private readonly EmployeeDirectory $directory,
    ) {}

    /**
     * @param  array<string, list<string>>  $assumedColumns
     */
    public function run(
        WorkspaceDraft $draft,
        User $actor,
        bool $dryRun,
        string $path,
        array $assumedColumns,
        bool $notify = false,
    ): ImportReport {
        $report = new ImportReport($draft, $dryRun, $path, (string) $actor->email, $assumedColumns);

        if (! $notify) {
            $this->mute();
            $report->note('Notifications and realtime broadcasts were suppressed for this run '
                .'(pass --notify to send them). Activity and audit rows were written as usual.');
        }

        if (! $this->carriesTime($draft)) {
            $report->note(sprintf(
                'Not one row in this export carries tracked time, so no time entries were written. '
                .'An %s CSV has no time column at all unless the workspace adds one — the '
                .'"Time tracked" half of the mapping has nothing to read.',
                $draft->source,
            ));
        }

        try {
            DB::transaction(function () use ($draft, $actor, $report, $dryRun): void {
                foreach ($draft->projects as $project) {
                    $this->project($project, $actor, $report);
                }

                if ($dryRun) {
                    throw new DryRunComplete;
                }
            });
        } catch (DryRunComplete) {
            // The whole point: everything above happened, and none of it is there any more.
        }

        return $report;
    }

    private function carriesTime(WorkspaceDraft $draft): bool
    {
        foreach ($draft->projects as $project) {
            foreach ($project->tasks as $task) {
                if ($task->trackedSeconds > 0) {
                    return true;
                }
            }
        }

        return false;
    }

    private function project(ProjectDraft $draft, User $actor, ImportReport $report): void
    {
        if ($draft->clientName === null) {
            $report->note('This export has nothing above a project, so no clients were created and the '
                .'imported projects have none. Link them in Admin → Projects if they belong to one.');
        }

        $client = $draft->clientName === null ? null : $this->client($draft->clientName, $actor, $report);

        $project = Project::query()
            ->when($client !== null, fn ($query) => $query->where('client_id', $client?->getKey()))
            ->when($client === null, fn ($query) => $query->whereNull('client_id'))
            ->whereRaw('lower(name) = ?', [mb_strtolower($draft->name)])
            ->orderBy('id')
            ->first();

        if ($project !== null) {
            // Deliberately left exactly as it is, including its status: a re-run restates what
            // the export said the first time, it does not undo six weeks of work done here.
            $report->projectMatched($draft->name);
        } else {
            $project = $this->projects->create($actor, [
                'client_id' => $client?->getKey(),
                'name' => $draft->name,
                // Neither export carries either of these and both columns are NOT NULL. They are
                // the neutral values, not a reading of their business, and the note says so.
                'project_type' => ProjectType::Other->value,
                'billing_type' => BillingType::OneTime->value,
            ]);

            $report->projectCreated($draft->name);
            $report->note('Imported projects get project type "Other", billing type "One-Time" and no '
                .'domain — no export carries any of the three, and the first two columns are NOT NULL. '
                .'An employee sees a project\'s DOMAIN in place of the client name (Part C §2), so fill '
                .'it in Admin → Projects before the employee surface is used.');

            if ($draft->status !== null && $draft->status !== ProjectStatus::Active) {
                $this->projects->changeStatus($actor, $project, $draft->status, sprintf(
                    'Imported with status "%s"',
                    $draft->rawStatus,
                ));
            }
        }

        foreach ($draft->tasks as $task) {
            $this->task($task, $project, $draft->name, $actor, $report);
        }
    }

    private function client(string $name, User $actor, ImportReport $report): Client
    {
        $existing = Client::query()
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->orderBy('id')
            ->first();

        if ($existing !== null) {
            $report->clientMatched($name);

            return $existing;
        }

        $report->clientCreated($name);

        return $this->clients->create($actor, ['name' => $name]);
    }

    private function task(TaskDraft $draft, Project $project, string $projectName, User $actor, ImportReport $report): void
    {
        // withTrashed: a task deleted here after the first run must stay deleted. Resurrecting it
        // is the one thing a second run of an import must never do.
        $existing = Task::withTrashed()
            ->where('project_id', $project->getKey())
            ->whereRaw('lower(title) = ?', [mb_strtolower($draft->title)])
            ->orderBy('id')
            ->first();

        if ($existing !== null) {
            $report->taskMatched($projectName);

            if ($existing->trashed()) {
                $report->unmapped($draft->line, $draft->title, 'a task with this title was imported '
                    .'before and has since been deleted here — it was left deleted, and no time was written');

                return;
            }

            $this->time($draft, $existing, $this->assignee($draft, $report, quiet: true), $actor, $report, $projectName);

            return;
        }

        $employee = $this->assignee($draft, $report);

        $task = $this->tasks->create($actor, [
            'project_id' => $project->getKey(),
            'title' => $draft->title,
            'description' => $draft->description,
            'priority' => $draft->priority->value,
            'start_date' => $draft->startDate?->toDateString(),
            'due_date' => $draft->dueDate?->toDateString(),
            // Every task is born in one of the two birth statuses and walked from there, because
            // TaskService::create() refuses to start one anywhere else and the state machine is
            // the only way a status moves.
            'status' => ($draft->status === TaskStatus::Backlog ? TaskStatus::Backlog : TaskStatus::Todo)->value,
        ], $employee === null ? [] : [(int) $employee->getKey()]);

        $report->taskCreated($projectName);

        $this->walk($task, $draft, $employee, $actor, $report);
        $this->time($draft, $task->refresh(), $employee, $actor, $report, $projectName);
    }

    /**
     * Move the new task to the status the export said it was in, one legal transition at a time.
     *
     * The path is the state machine's, not a shortcut: BACKLOG / TO DO are where a task is born,
     * everything else is walked to. A COMPLETED ClickUp task therefore passes through In progress
     * and In review, and that walk is visible in its activity log, which is the honest record of
     * how it got here.
     *
     * **Submit-for-review is done AS THE ASSIGNEE where it can be.** `assertCompletable()`
     * requires the work summary to be the primary assignee's, which is exactly the rule that
     * stops one person signing off another's work; satisfying it by attributing the summary to
     * the person who did the work is the honest way to satisfy it, and acting as the Admin and
     * then overriding the check is not.
     *
     * A move that cannot be made does not lose the task: it is left where it got to and the
     * report says where, with the reason.
     */
    private function walk(Task $task, TaskDraft $draft, ?Employee $employee, User $actor, ImportReport $report): void
    {
        $target = $draft->status;

        if ($target === null || $task->status === $target) {
            return;
        }

        try {
            match ($target) {
                TaskStatus::Backlog, TaskStatus::Todo => null,
                TaskStatus::Waiting => $this->tasks->transition($actor, $task, TaskStatus::Waiting),
                TaskStatus::Cancelled => $this->tasks->transition($actor, $task, TaskStatus::Cancelled, null, self::CANCEL_REASON),
                TaskStatus::InProgress => $this->tasks->transition($actor, $task, TaskStatus::InProgress),
                TaskStatus::InReview => $this->review($task, $employee, $actor),
                TaskStatus::ChangesRequested => $this->afterReview($task, $employee, $actor, TaskStatus::ChangesRequested),
                TaskStatus::Completed => $this->afterReview($task, $employee, $actor, TaskStatus::Completed),
            };
        } catch (\Throwable $e) {
            $report->unmapped($draft->line, $draft->title, sprintf(
                'status "%s" could not be reached — the task was imported and left at %s (%s)',
                $draft->rawStatus,
                $task->refresh()->status?->label() ?? 'its starting status',
                $e->getMessage(),
            ));
        }
    }

    private function review(Task $task, ?Employee $employee, User $actor): void
    {
        $this->tasks->transition($actor, $task, TaskStatus::InProgress);

        $author = $employee?->user;

        $by = $author !== null && Gate::forUser($author)->allows('transition', [$task, TaskStatus::InReview])
            ? $author
            : $actor;

        $this->tasks->transition($by, $task, TaskStatus::InReview, self::WORK_SUMMARY);
    }

    private function afterReview(Task $task, ?Employee $employee, User $actor, TaskStatus $to): void
    {
        $this->review($task, $employee, $actor);
        $this->tasks->transition($actor, $task, $to);
    }

    /**
     * The employee the export's assignee text names, or null with a line in the report.
     *
     * Never creates anybody (Phase 12 rule 4), and never assigns somebody this application would
     * not offer in the assignee picker — `TaskService::assignableEmployees()` is that list, so an
     * import cannot put work on a deactivated account or on the Accountant.
     */
    private function assignee(TaskDraft $draft, ImportReport $report, bool $quiet = false): ?Employee
    {
        if ($draft->assignee === null) {
            return null;
        }

        $employee = $this->directory->find($draft->assignee, $why);

        if ($employee === null) {
            if (! $quiet) {
                $report->unmapped($draft->line, $draft->title, (string) $why);
            }

            return null;
        }

        $this->assignable ??= $this->tasks->assignableEmployees()
            ->keyBy(fn (Employee $one): int => (int) $one->getKey())
            ->all();

        if (! array_key_exists((int) $employee->getKey(), $this->assignable)) {
            if (! $quiet) {
                $report->unmapped($draft->line, $draft->title, sprintf(
                    '"%s" is %s and cannot be given tasks here, so the task is unassigned',
                    $draft->assignee,
                    $employee->status?->value === 'active' ? 'not allowed to see tasks' : 'not an active employee',
                ));
            }

            return null;
        }

        return $employee;
    }

    /**
     * ClickUp's "Time tracked" → a manual `time_entries` row, the same call the Add-manual-entry
     * form makes, with Part E's reason.
     *
     * **Approval is not decided here.** `TimerService::manualEntry()` reads
     * `settings.manual_time_requires_approval` exactly as it does for a typed entry, so with the
     * seeded default (on) imported hours arrive with `approved_at` and `approved_by` null and
     * show up in the Admin's approval queue. That is the right answer rather than a convenient
     * one: these hours are a claim carried over from another system, and an Admin signing them
     * off is the moment they become this application's numbers. Until then they are visible and
     * do not count toward anybody's day — which is also why `tasks.tracked_seconds` stays 0 for
     * them until they are approved.
     */
    private function time(
        TaskDraft $draft,
        Task $task,
        ?Employee $employee,
        User $actor,
        ImportReport $report,
        string $projectName,
    ): void {
        if ($draft->trackedSeconds <= 0) {
            return;
        }

        $amount = ImportReport::duration($draft->trackedSeconds);

        if ($employee === null) {
            $report->unmapped($draft->line, $draft->title, sprintf(
                '%s of tracked time has no employee to belong to (no assignee matched), so no time entry was written',
                $amount,
            ));

            return;
        }

        $user = $employee->user;

        if ($user === null || ! Gate::forUser($user)->allows('create', TimeEntry::class)) {
            $report->unmapped($draft->line, $draft->title, sprintf(
                '%s of tracked time was dropped — %s does not use the timer, and a time entry for '
                .'somebody the app would refuse one from is not a time entry',
                $amount,
                (string) ($user?->name ?? $draft->assignee),
            ));

            return;
        }

        $already = TimeEntry::query()
            ->where('task_id', $task->getKey())
            ->where('employee_id', $employee->getKey())
            ->where('entry_type', TimeEntryType::Manual->value)
            ->where('reason', self::TIME_REASON)
            ->count();

        if ($already > 0) {
            $report->timeMatched($projectName, $already);

            return;
        }

        $windows = $this->windows($draft);

        if ($windows === []) {
            $report->unmapped($draft->line, $draft->title, sprintf(
                'tracked time of %s is longer than %d working days — that is a misread column, not a duration, so nothing was written',
                $amount,
                self::MAX_CHUNKS,
            ));

            return;
        }

        if (count($windows) > 1) {
            $report->unmapped($draft->line, $draft->title, sprintf(
                '%s of tracked time is more than one working day, so it was written as %d entries of at '
                .'most %s on consecutive days ending %s — the TOTAL is the export\'s, the days are this import\'s',
                $amount,
                count($windows),
                ImportReport::duration(self::CHUNK_SECONDS),
                $windows[0][0]->toDateString(),
            ));
        }

        foreach ($windows as [$start, $end, $seconds]) {
            $this->timer->manualEntry($employee, $task, $start, $end, self::TIME_REASON, $actor);
            $report->timeCreated($projectName, $seconds);
        }
    }

    /**
     * Where to put the hours. Neither export says WHEN the work happened — only how much of it
     * there was — so the entries are anchored to the task's due date (or yesterday, whichever is
     * earlier) and walk backwards a working day at a time.
     *
     * Yesterday rather than today because `TimerService` refuses an entry that ends in the
     * future, and a run at nine in the morning would.
     *
     * @return list<array{0: Carbon, 1: Carbon, 2: int}>
     */
    private function windows(TaskDraft $draft): array
    {
        $anchor = Carbon::now(config('app.timezone'))->startOfDay()->subDay();

        if ($draft->dueDate !== null && $draft->dueDate->copy()->startOfDay()->lessThan($anchor)) {
            $anchor = $draft->dueDate->copy()->setTimezone(config('app.timezone'))->startOfDay();
        }

        if ($draft->trackedSeconds > self::CHUNK_SECONDS * self::MAX_CHUNKS) {
            return [];
        }

        $windows = [];
        $remaining = $draft->trackedSeconds;
        $day = 0;

        while ($remaining > 0) {
            $chunk = min($remaining, self::CHUNK_SECONDS);
            $start = $anchor->copy()->subDays($day)->setTime(9, 0);

            $windows[] = [$start, $start->copy()->addSeconds($chunk), $chunk];

            $remaining -= $chunk;
            $day++;
        }

        return $windows;
    }

    /**
     * The six events an import can fire, forgotten for the rest of the process.
     *
     * Not a bypass of anything: the services still fire them, nothing is listening. Audit and
     * activity rows are untouched — they are written by the services directly, not through an
     * event — so the trail of what the import did is complete either way.
     */
    private function mute(): void
    {
        if ($this->muted) {
            return;
        }

        foreach ([
            TaskAssigned::class,
            TaskReassigned::class,
            TaskStatusChanged::class,
            TaskSubmittedForReview::class,
            TaskCompleted::class,
            ProjectCancelled::class,
        ] as $event) {
            Event::forget($event);
        }

        $this->muted = true;
    }
}
