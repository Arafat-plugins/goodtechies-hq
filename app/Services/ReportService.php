<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\DailyWorkSummary;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Meeting;
use App\Models\Message;
use App\Models\PayrollItem;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Support\AttendanceStatus;
use App\Support\ConversationType;
use App\Support\LeaveStatus;
use App\Support\MeetingStatus;
use App\Support\PayrollStatus;
use App\Support\Permission;
use App\Support\ProjectStatus;
use App\Support\ProjectType;
use App\Support\RecurrenceRule;
use App\Support\ReportChart;
use App\Support\ReportColumn;
use App\Support\ReportFilters;
use App\Support\ReportFormat;
use App\Support\ReportKey;
use App\Support\ReportResult;
use App\Support\TaskBucket;
use App\Support\TaskStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Every report, built in one place (report contract §4, master prompt Part D §15).
 *
 * `build()` dispatches to one private method per `ReportKey` and **owns no SQL of its own**.
 * Three rules govern every one of those methods, and each of them is a test:
 *
 * ## 1. Every query is scoped by the model's existing `visibleTo()`
 *
 * `Task::visibleTo()`, `Project::visibleTo()`, `TimeEntry::visibleTo()`,
 * `PayrollItem::visibleTo()`, `Employee::attendanceVisibleTo()` — and, for the eight Part D §15
 * adds, `Employee::leaveVisibleTo()`, `LeaveRequest::visibleTo()` and `Meeting::visibleTo()`.
 *
 * **One kind of row has no scope to use, and it is the interesting case.** `Conversation` has no
 * `visibleTo()`, because a conversation's audience is computed rather than stored (2-24): the
 * policy delegates to the linked task, the linked project, the messaging permission, or the two
 * ids on a DM row. The In-app coordination report therefore counts each kind through the thing
 * that already decides it — `Task::visibleTo()`, `Project::visibleTo()`, `Gate::allows('view')`
 * on the two company channels, and `Conversation::dmsFor()` for a DM. That is the rule this
 * paragraph exists to defend: where there was no scope, the answer was to ask the existing
 * policy, never to write a predicate here.
 *
 * Not one report writes a new access rule, and no role name appears anywhere in this file. A report that needed an access
 * question nobody has asked before would be a finding, not a predicate invented here — because
 * a rule written in a report is a rule the policies do not know about, and it will be the one
 * that is forgotten when the matrix changes.
 *
 * The consequence to keep in mind while reading: a filter id the viewer may not see is **not**
 * an error. It is dropped into an already-scoped query, matches nothing, and the report is
 * empty. A 404 there would confirm that the row exists (Part C §1).
 *
 * ## 2. Every figure the application already states elsewhere is read from the thing that states it
 *
 * The Finance report's months are `FinanceService::monthlyRollup()` — so the Reports screen and
 * the Finance screen cannot say two different things about September. Task counts go through
 * `TaskService::count()`, so a report's "12 overdue" is the same predicate, the same window and
 * the same scope as the Tasks List's. Buckets are `TaskBucket`; attendance days are the
 * `daily_work_summary` view; tracked minutes are `TimeEntry::scopeCounted()`. **A report is a
 * new cut, never a second opinion.**
 *
 * Where that costs a query per row it is paid — `count()` per project is N small `COUNT`s
 * rather than one clever `GROUP BY` with the window predicate re-typed. The agency has tens of
 * projects and five employees; the price of the alternative is two definitions of "overdue".
 *
 * ## 3. No score, no ranking, no per-person target
 *
 * Part H §1. The Employee Work report is ordered **by name** — a table sorted by output is a
 * league table whatever the column is called — and it carries no chart, because a bar chart of
 * people is a ranking drawn sideways. Nothing here divides one person's number by another's,
 * and nothing compares a person's total with a target.
 *
 * ## Money
 *
 * Decimal strings, straight out of PostgreSQL (`::numeric(12,2)::text`). Never a float and
 * never summed in PHP as one: where a range has to be added up, it is added in **integer
 * cents**, the same way `FinanceService` does it, and for the single-month case the string is
 * passed through untouched so the Finance report and the rollup are byte-identical.
 */
class ReportService
{
    /** Rows beyond this are not a report, they are an export — and there is no export (spec §44). */
    private const MAX_ROWS = 500;

    /** The two windows `GET /employee/reports?period=` offers. */
    public const PERIOD_WEEK = 'week';

    public const PERIOD_MONTH = 'month';

    public function __construct(
        private readonly TaskService $tasks,
        private readonly FinanceService $finance,
        private readonly AttendanceService $attendance,
        // Slice B. `LeaveService::daysByDate()` is the one statement of which dates a request
        // actually covers, and `MeetingService::linkedContextFor()` is the one statement of
        // whether a viewer may be told a meeting's project — neither is restated here.
        private readonly LeaveService $leave,
        private readonly MeetingService $meetings,
    ) {}

    /**
     * The one door in.
     *
     * The permission is **not** asked here: the controller asks it against
     * `ReportKey::permission()` before calling, because a service that refused would have to
     * throw, and a report that a viewer may not open is a 403 about the route rather than an
     * exception about the data. What this method does guarantee is that even a caller who
     * skipped that check gets only rows the scopes allow.
     */
    public function build(ReportKey $key, User $viewer, ReportFilters $filters): ReportResult
    {
        return match ($key) {
            ReportKey::Task => $this->task($viewer, $filters),
            ReportKey::EmployeeWork => $this->employeeWork($viewer, $filters),
            ReportKey::Project => $this->project($viewer, $filters),
            ReportKey::Overdue => $this->overdue($viewer, $filters),
            ReportKey::Attendance => $this->attendance($viewer, $filters),
            ReportKey::Time => $this->time($viewer, $filters),
            ReportKey::Finance => $this->financeReport($viewer, $filters),
            ReportKey::Payroll => $this->payroll($viewer, $filters),
            ReportKey::Completion => $this->completion($viewer, $filters),
            ReportKey::Leave => $this->leaveReport($viewer, $filters),
            // One builder, two keys. Maintenance and SEO are the same question asked of two
            // `ProjectType`s, so they are the same method: two copies would be two places for
            // "what a period is" to drift apart, which is the whole reason the retainer rows
            // are defined once.
            ReportKey::Maintenance, ReportKey::Seo => $this->retainer($viewer, $filters, $key),
            ReportKey::WebsiteProject => $this->websiteProject($viewer, $filters),
            ReportKey::Meeting => $this->meeting($viewer, $filters),
            ReportKey::Performance => $this->performance($viewer, $filters),
            ReportKey::InAppCoordination => $this->inAppCoordination($viewer, $filters),
        };
    }

    /**
     * The catalogue this viewer may open — the Reports index, and the guard on every card.
     *
     * A capability decides, never a name: a report is on the menu exactly when the viewer holds
     * the permission of the data it reads.
     *
     * @return list<ReportKey>
     */
    public function catalogueFor(User $viewer): array
    {
        return array_values(array_filter(
            ReportKey::inDisplayOrder(),
            fn (ReportKey $key): bool => $this->may($viewer, $key),
        ));
    }

    /** May this viewer open this report at all? The one statement of it. */
    public function may(User $viewer, ReportKey $key): bool
    {
        return $viewer->isActive() && $viewer->hasPermission($key->permission());
    }

    /*
    |--------------------------------------------------------------------------
    | The self-scoped screen
    |--------------------------------------------------------------------------
    */

    /**
     * `GET /employee/reports` — *my* reports (Part D §15, report contract §5).
     *
     * A different screen, not a filtered copy of the admin one: Part D §15 names a different
     * list for it, and there is **no employee filter anywhere in it**. The counts are
     * `TaskService` with `mine => true`, so the narrowing is "assigned to the signed-in person"
     * and this payload cannot be made to show somebody else's numbers by any query string —
     * there is nothing to pass.
     *
     * ## Absent, not zero
     *
     * The **Time** block is present only for somebody the timer actually tracks, and the
     * clocked-hours figure only for somebody the office clock tracks. Both questions are asked
     * of the existing policy and the existing service — `TimeEntryPolicy::viewAny()` (which is
     * `timer.use` **and** `tracking_mode = remote_timer`) and `AttendanceService::clocks()` —
     * so no report invents a rule about who has hours.
     *
     * An office employee's `0h 0m tracked` would be a fact about their tracking mode wearing
     * the clothes of a fact about their work, which is the whole of Part C §1's field rule
     * applied to a section instead of a column.
     *
     * @return array<string, mixed>
     */
    public function forEmployee(User $viewer, string $period = self::PERIOD_WEEK): array
    {
        $period = $period === self::PERIOD_MONTH ? self::PERIOD_MONTH : self::PERIOD_WEEK;
        $asOf = Carbon::today();

        [$from, $to] = $period === self::PERIOD_MONTH
            ? [$asOf->copy()->startOfMonth(), $asOf->copy()->endOfMonth()]
            // Sunday to Saturday — the week the seeded schedules run (Sunday to Thursday), and
            // the same seven days Admin → Workforce → Time calls "this week".
            : [$asOf->copy()->startOfWeek(Carbon::SUNDAY), $asOf->copy()->startOfWeek(Carbon::SUNDAY)->addDays(6)];

        $mine = ['mine' => true, 'as_of' => $asOf];
        $window = [...$mine, 'date_from' => $from, 'date_to' => $to];

        $payload = [
            'buckets' => [
                $this->myBucket($viewer, 'all', 'My tasks', null, $mine),
                $this->myBucket($viewer, TaskBucket::Completed->value, 'Completed', TaskBucket::Completed, $mine),
                $this->myBucket($viewer, TaskBucket::Open->value, 'Pending', TaskBucket::Open, $mine),
                $this->myBucket($viewer, TaskBucket::Overdue->value, 'Overdue', TaskBucket::Overdue, $mine),
            ],
            'period' => $period,
            'periods' => [self::PERIOD_WEEK, self::PERIOD_MONTH],
            'range' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'label' => $period === self::PERIOD_MONTH
                    ? $from->format('F Y')
                    : $from->format('j M').' – '.$to->format('j M Y'),
            ],
            'summary' => [
                'completed' => $this->tasks->count($viewer, [...$window, 'bucket' => TaskBucket::Completed->value]),
                'due' => $this->tasks->count($viewer, $window),
                'overdue' => $this->tasks->count($viewer, [...$window, 'bucket' => TaskBucket::Overdue->value]),
            ],
        ];

        $employee = $viewer->employee;

        // The office clock, for somebody the office clock tracks. `AttendanceService::clocks()`
        // is the one statement of that — tracking mode decides, never a role.
        if ($this->attendance->clocks($employee) && $employee !== null) {
            $payload['clocked'] = [
                'minutes' => (int) DailyWorkSummary::query()
                    ->forEmployees([(int) $employee->getKey()])
                    ->between($from, $to)
                    ->sum('worked_minutes'),
                'days' => DailyWorkSummary::query()
                    ->forEmployees([(int) $employee->getKey()])
                    ->between($from, $to)
                    ->whereNotNull('attendance_status')
                    ->count(),
            ];
        }

        // The Time block — ABSENT for anybody the timer does not track. Not null, not zero.
        if (Gate::forUser($viewer)->allows('viewAny', TimeEntry::class)) {
            $base = fn (): Builder => TimeEntry::query()
                ->visibleTo($viewer)
                ->countsTowardHours()
                ->whereBetween('time_entries.work_date', [$from->toDateString(), $to->toDateString()]);

            $payload['time'] = [
                'tracked_minutes' => (int) floor(((int) $base()->counted()->sum('duration_seconds')) / 60),
                'pending_minutes' => (int) floor(((int) $base()->awaitingDecision()->sum('duration_seconds')) / 60),
                'entries' => $base()->stopped()->count(),
                'href' => '/employee/time',
            ];
        }

        return $payload;
    }

    /**
     * One of the four counts on that screen, with the link to the plate it came from.
     *
     * The count and the destination are the same bucket, so the number and the list it opens
     * cannot come from two different predicates — the reason `TaskBucket` exists at all.
     *
     * @param  array<string, mixed>  $mine
     * @return array{key: string, label: string, count: int, href: string}
     */
    private function myBucket(User $viewer, string $key, string $label, ?TaskBucket $bucket, array $mine): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'count' => $this->tasks->count($viewer, $bucket === null ? $mine : [...$mine, 'bucket' => $bucket->value]),
            'href' => $bucket === null || $bucket === TaskBucket::Open
                ? '/employee/my-tasks'
                : '/employee/my-tasks?bucket='.$bucket->value,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Work
    |--------------------------------------------------------------------------
    */

    /**
     * Task — *how much work is there, and what state is it in?*
     *
     * One row per status that has work in it. A status with no tasks is **absent**, not a zero
     * row: this is a report of what there is, and `FinanceService::monthlyRollup()` settled the
     * same question the same way — inventing rows to make a layout tidy is how a report starts
     * lying.
     *
     * Every number is `TaskService::count()`, which means the window is the application's own
     * overlap predicate (`coalesce(due_date, start_date)` within the range) and the scope is
     * `Task::visibleTo()`. Opening `/admin/tasks` with the same filters gives the same rows.
     */
    private function task(User $viewer, ReportFilters $filters): ReportResult
    {
        $rows = [];
        $total = 0;
        $overdue = 0;

        foreach (TaskStatus::boardOrder() as $status) {
            $count = $this->countTasks($viewer, $filters, ['status' => $status->value]);

            if ($count === 0) {
                continue;
            }

            $late = $this->countTasks($viewer, $filters, ['status' => $status->value, 'overdue' => true]);

            $rows[] = [
                'status' => $status->tone(),
                'tasks' => $count,
                'overdue' => $late,
                'share' => 0,
            ];

            $total += $count;
            $overdue += $late;
        }

        // The share is filled in afterwards because it needs the total, and the total is the
        // sum of the rows that exist rather than a second query that might disagree with them.
        $rows = array_map(static function (array $row) use ($total): array {
            $row['share'] = $total === 0 ? 0 : (int) round($row['tasks'] / $total * 100);

            return $row;
        }, $rows);

        return new ReportResult(
            columns: [
                ReportColumn::status('status', 'Status', self::taskStatusLabels()),
                ReportColumn::number('tasks', 'Tasks'),
                ReportColumn::number('overdue', 'Overdue'),
                ReportColumn::percent('share', 'Share'),
            ],
            rows: $rows,
            totals: ['status' => null, 'tasks' => $total, 'overdue' => $overdue, 'share' => $total === 0 ? 0 : 100],
            charts: [
                ReportChart::donut('Tasks by status', array_map(static fn (array $row): array => [
                    'label' => self::statusLabelForTone((string) $row['status']),
                    'value' => (int) $row['tasks'],
                    'tone' => (string) $row['status'],
                ], $rows)),
            ],
            notes: [
                'A task is in the window when its start or due date falls inside it — the same '
                .'window the Tasks List uses. Archived tasks are not counted.',
            ],
            empty: 'No tasks fall in this window.',
        );
    }

    /**
     * Project — *where does each project stand?*
     *
     * One row per project the viewer may see, whether or not it has work in the window: the row
     * is about the project, and a project with a quiet month has not stopped existing.
     *
     * **The client column is absent for a viewer without `clients.view_full`**, and the domain
     * stands in its place — Part C §2's field rule, asked here rather than hidden in the
     * renderer, because a renderer that hides a cell has already been sent the value.
     */
    private function project(User $viewer, ReportFilters $filters): ReportResult
    {
        $mayReadClients = $viewer->hasPermission(Permission::ClientsViewFull);

        $projects = Project::query()
            ->visibleTo($viewer)
            ->notArchived()
            ->when($filters->projectId, fn (Builder $q, int $id) => $q->where('projects.id', $id))
            ->when($filters->clientId, fn (Builder $q, int $id) => $q->where('projects.client_id', $id))
            ->with($mayReadClients ? ['client'] : [])
            ->orderBy('projects.name')
            ->limit(self::MAX_ROWS)
            ->get();

        $base = $this->taskFilters($filters);
        $rows = [];
        $totals = ['tasks' => 0, 'open' => 0, 'overdue' => 0, 'completed' => 0];

        foreach ($projects as $project) {
            $scoped = [...$base, 'project_id' => $project->getKey()];
            $status = $project->status instanceof ProjectStatus ? $project->status : null;

            $row = ['project' => (string) $project->name];

            if ($mayReadClients) {
                $row['client'] = $project->client?->name ?? '—';
            }

            $row += [
                'domain' => (string) ($project->domain ?? '—'),
                'status' => $status?->label() ?? '—',
                'deadline' => $project->deadline?->toDateString(),
                'tasks' => $this->tasks->count($viewer, $scoped),
                'open' => $this->tasks->count($viewer, [...$scoped, 'bucket' => TaskBucket::Open->value]),
                'overdue' => $this->tasks->count($viewer, [...$scoped, 'bucket' => TaskBucket::Overdue->value]),
                'completed' => $this->tasks->count($viewer, [...$scoped, 'bucket' => TaskBucket::Completed->value]),
            ];

            foreach (array_keys($totals) as $key) {
                $totals[$key] += (int) $row[$key];
            }

            $rows[] = $row;
        }

        $columns = [ReportColumn::text('project', 'Project')];

        if ($mayReadClients) {
            $columns[] = ReportColumn::text('client', 'Client');
        }

        $columns = [
            ...$columns,
            ReportColumn::text('domain', 'Domain'),
            ReportColumn::text('status', 'Status'),
            ReportColumn::date('deadline', 'Deadline'),
            ReportColumn::number('tasks', 'Tasks'),
            ReportColumn::number('open', 'Open'),
            ReportColumn::number('overdue', 'Overdue'),
            ReportColumn::number('completed', 'Completed'),
        ];

        return new ReportResult(
            columns: $columns,
            rows: $rows,
            totals: ['project' => 'All projects', ...$totals],
            charts: [
                ReportChart::donut('Projects by status', $this->countBy(
                    $projects->map(fn (Project $p): string => $p->status?->label() ?? '—')->all(),
                )),
            ],
            notes: [
                'Task counts are for the chosen window; the project rows are every project you '
                .'can open, archived ones excluded.',
            ],
            empty: 'No projects to report on.',
        );
    }

    /**
     * Overdue — *what is late, and by how long?*
     *
     * One row per late task, most overdue first. Ordering tasks by lateness is ordering work,
     * not people: the assignee's name travels on the row it belongs to and nothing aggregates
     * it.
     *
     * The window filter is absent by design (`ReportKey::filters()`): "what is late" is a
     * question about today, and `TaskBucket::Overdue` is the application's one definition of
     * it.
     */
    private function overdue(User $viewer, ReportFilters $filters): ReportResult
    {
        $asOf = $filters->asOf();

        $tasks = $this->tasks
            ->query($viewer, [
                'bucket' => TaskBucket::Overdue->value,
                'as_of' => $asOf,
                'project_id' => $filters->projectId,
                'assignee_id' => $filters->employeeId,
            ])
            ->when($filters->clientId, fn (Builder $q, int $id) => $q->whereHas(
                'project',
                fn (Builder $p) => $p->where('projects.client_id', $id),
            ))
            ->limit(self::MAX_ROWS)
            ->get();

        $rows = $tasks
            ->map(fn (Task $task): array => [
                'task' => (string) $task->title,
                'project' => (string) ($task->project?->name ?? '—'),
                'assignees' => $this->assigneeNames($task),
                'status' => $task->status?->tone() ?? TaskStatus::Todo->tone(),
                'due' => $task->due_date?->toDateString(),
                'days_late' => $task->due_date === null ? 0 : (int) $task->due_date->diffInDays($asOf),
            ])
            ->sortByDesc('days_late')
            ->values()
            ->all();

        return new ReportResult(
            columns: [
                ReportColumn::text('task', 'Task'),
                ReportColumn::text('project', 'Project'),
                ReportColumn::text('assignees', 'Assigned to'),
                ReportColumn::status('status', 'Status', self::taskStatusLabels()),
                ReportColumn::date('due', 'Due'),
                ReportColumn::number('days_late', 'Days late'),
            ],
            rows: $rows,
            totals: [
                'task' => count($rows).' overdue',
                'project' => null,
                'assignees' => null,
                'status' => null,
                'due' => null,
                'days_late' => null,
            ],
            charts: [
                ReportChart::bar('Overdue by project', $this->countBy(
                    array_map(static fn (array $row): string => (string) $row['project'], $rows),
                )),
            ],
            notes: [
                'Overdue is a due date before '.$asOf->toDateString().' on a task that is not '
                .'completed or cancelled — the same definition the Tasks List and the dashboard use.',
            ],
            empty: 'Nothing is overdue.',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Workforce
    |--------------------------------------------------------------------------
    */

    /**
     * Employee Work — *what did each person work on in this window?*
     *
     * Counts, one row per person, **in name order** (Part H §1, and the contract's rule 3). No
     * chart: a bar chart of people is a ranking drawn sideways, and no column divides one
     * person's number by anything.
     *
     * Hours are deliberately **not** here. They are the Time report's, which is grouped by
     * project and can therefore honour this report's project filter without quietly widening
     * it — a tracked-hours column beside a project filter that only narrowed the task counts
     * would be a figure that means something different from the column next to it.
     */
    private function employeeWork(User $viewer, ReportFilters $filters): ReportResult
    {
        $people = $this->people($viewer, $filters);
        $base = $this->taskFilters($filters);
        $rows = [];
        $totals = ['tasks' => 0, 'completed' => 0, 'open' => 0, 'overdue' => 0];

        foreach ($people as $employee) {
            $scoped = [...$base, 'assignee_id' => $employee->getKey()];

            $row = [
                'employee' => (string) ($employee->user?->name ?? 'Unknown'),
                'tasks' => $this->tasks->count($viewer, $scoped),
                'completed' => $this->tasks->count($viewer, [...$scoped, 'bucket' => TaskBucket::Completed->value]),
                'open' => $this->tasks->count($viewer, [...$scoped, 'bucket' => TaskBucket::Open->value]),
                'overdue' => $this->tasks->count($viewer, [...$scoped, 'bucket' => TaskBucket::Overdue->value]),
            ];

            foreach (array_keys($totals) as $key) {
                $totals[$key] += (int) $row[$key];
            }

            $rows[] = $row;
        }

        return new ReportResult(
            columns: [
                ReportColumn::text('employee', 'Employee'),
                ReportColumn::number('tasks', 'Tasks in window'),
                ReportColumn::number('completed', 'Completed'),
                ReportColumn::number('open', 'Open'),
                ReportColumn::number('overdue', 'Overdue'),
            ],
            rows: $rows,
            totals: ['employee' => 'Everyone', ...$totals],
            charts: [],
            notes: [
                'Listed by name. These are counts of work, not a measure of a person — there is '
                .'no ranking and no target here, by design.',
                'A task with more than one assignee is counted for each of them, so the total '
                .'can exceed the number of tasks.',
            ],
            empty: 'Nobody has work in this window.',
        );
    }

    /**
     * Attendance — *who was in, late, absent or away?*
     *
     * Read from the `daily_work_summary` view, which is the reporting join of the office clock
     * and the remote timer (Part C rule 6). It is not a third place worked hours are stored —
     * it has no rows of its own — so this report cannot disagree with the roster or the month
     * grid about a day.
     *
     * **A day with no record is not counted.** An off day, a public holiday, and a day the
     * nightly sweep has not yet reached are absent rather than zero; guessing "present" or
     * "absent" for them would put a word on somebody's record that no job has written.
     *
     * ## Why **Remote** is one of the five buckets and not a gap
     *
     * A remote-timer employee has no `attendance_records` row at all (decision 4-11), so their
     * day arrives here through the view's other half — a tracked day with `attendance_status`
     * NULL. Counting only the four clock statuses made *"Days recorded"* and the donut's total
     * disagree on screen: 72 in the table's footer, 54 in the ring above it, with Tapu holding
     * eighteen days that sat in no column. Both figures were right and the pair was unreadable.
     *
     * So a tracked day with no clock status is counted as **Remote**, which is what it is —
     * `AttendanceStatus::Remote` is already the derived state the roster gives those days, and
     * it is derived there for this same reason. The five buckets now sum to `days`, the ring
     * sums to the footer, and Part C rule 6's two tables read as one answer instead of as a
     * subtraction the reader has to do themselves.
     */
    private function attendance(User $viewer, ReportFilters $filters): ReportResult
    {
        $people = $this->people($viewer, $filters);
        $ids = $people->map(fn (Employee $e): int => (int) $e->getKey())->all();
        [$from, $to] = $filters->dateStrings();

        $summaries = $ids === []
            ? collect()
            : DailyWorkSummary::query()
                ->forEmployees($ids)
                ->between($filters->from, $filters->to)
                ->get()
                ->groupBy('employee_id');

        // The four the office clock writes, then the one the timer implies. Order is the order
        // of the columns and of the ring's slices, so the table and the chart read the same way
        // round.
        $counted = [
            AttendanceStatus::Present,
            AttendanceStatus::Late,
            AttendanceStatus::HalfDay,
            AttendanceStatus::Absent,
            AttendanceStatus::Remote,
        ];

        $rows = [];
        $totals = ['days' => 0, 'leave' => 0, 'worked' => 0, 'tracked' => 0];
        $mix = [];

        foreach ($counted as $status) {
            $totals[$status->value] = 0;
            $mix[$status->value] = ['label' => $status->label(), 'tone' => $status->tone(), 'value' => 0];
        }

        foreach ($people as $employee) {
            /** @var Collection<int, DailyWorkSummary> $days */
            $days = $summaries->get($employee->getKey()) ?? collect();

            $row = [
                'employee' => (string) ($employee->user?->name ?? 'Unknown'),
                'days' => $days->count(),
            ];

            foreach ($counted as $status) {
                $row[$status->value] = $days
                    ->filter(fn (DailyWorkSummary $day): bool => $status === AttendanceStatus::Remote
                        // A tracked day with no clock status. See the docblock: this is the
                        // remote half of the view, not a row that lost its status.
                        ? $day->attendance_status === null
                        : $day->attendance_status === $status->value)
                    ->count();
                $mix[$status->value]['value'] += (int) $row[$status->value];
            }

            $row['leave'] = $days->filter(fn (DailyWorkSummary $day): bool => (bool) $day->on_leave)->count();
            $row['worked'] = (int) $days->sum('worked_minutes');
            $row['tracked'] = (int) $days->sum('tracked_minutes');

            // Somebody with nothing recorded in the window is **absent from the table**, not a
            // row of zeroes. A zero row here would be a claim that they were neither present
            // nor absent that month, which is the one thing the data does not say — the same
            // reason the note below exists, applied to the row instead of the cell.
            if ($row['days'] === 0 && $row['leave'] === 0) {
                continue;
            }

            foreach (array_keys($totals) as $key) {
                $totals[$key] += (int) $row[$key];
            }

            $rows[] = $row;
        }

        $columns = [
            ReportColumn::text('employee', 'Employee'),
            ReportColumn::number('days', 'Days recorded'),
        ];

        foreach ($counted as $status) {
            $columns[] = ReportColumn::number($status->value, $status->label());
        }

        $columns = [
            ...$columns,
            ReportColumn::number('leave', 'On leave'),
            ReportColumn::minutes('worked', 'Clocked'),
            ReportColumn::minutes('tracked', 'Tracked'),
        ];

        return new ReportResult(
            columns: $columns,
            rows: $rows,
            totals: ['employee' => 'Everyone', ...$totals],
            charts: [
                // Every slice carries its status tone, so Late is amber and Absent is red
                // rather than whatever position they happened to take in the series. A
                // categorical palette here had put Late in green — colour saying the opposite
                // of the word beside it, which DESIGN.md §5.6 is written to prevent.
                ReportChart::donut('Recorded days by status', array_values($mix)),
            ],
            notes: [
                'Counted from recorded attendance between '.$from.' and '.$to.'. A day with no '
                .'record — an off day, a public holiday, or a day the nightly sweep has not '
                .'reached — is not counted rather than counted as zero.',
                '"On leave" counts days covered by an approved leave request, which can overlap '
                .'a recorded day.',
            ],
            empty: 'No attendance was recorded in this window.',
        );
    }

    /**
     * Time — *where did the tracked hours go?*
     *
     * Grouped by project, from `time_entries` through the model's own scopes: `counted()` is
     * `approved_at is not null` — the single predicate the `daily_work_summary` view is also
     * written against (decision 4-7) — and `awaitingDecision()` is the approval queue's.
     * Neither is re-typed here.
     *
     * Time on a project the viewer cannot open is grouped under **Unassigned** rather than
     * dropped: dropping it would make the total disagree with the timesheet, and naming it
     * would name a project they may not see.
     */
    private function time(User $viewer, ReportFilters $filters): ReportResult
    {
        $names = Project::query()
            ->visibleTo($viewer)
            ->when($filters->projectId, fn (Builder $q, int $id) => $q->where('projects.id', $id))
            ->pluck('projects.name', 'projects.id');

        $tracked = $this->minutesByProject($viewer, $filters, fn (Builder $q) => $q->counted());
        $pending = $this->minutesByProject($viewer, $filters, fn (Builder $q) => $q->awaitingDecision());
        $entries = $this->entriesByProject($viewer, $filters);

        $keys = array_values(array_unique([
            ...array_keys($tracked),
            ...array_keys($pending),
            ...array_keys($entries),
        ]));

        $rows = [];
        $totals = ['entries' => 0, 'tracked' => 0, 'pending' => 0];

        foreach ($keys as $key) {
            $rows[] = [
                'project' => $key === 0 ? 'Unassigned' : (string) ($names[$key] ?? 'Unassigned'),
                'entries' => $entries[$key] ?? 0,
                'tracked' => $tracked[$key] ?? 0,
                'pending' => $pending[$key] ?? 0,
            ];
        }

        // Entries on projects outside the viewer's scope collapse into one Unassigned row, so
        // the rows are merged by their LABEL rather than by their project id.
        $rows = $this->mergeRowsByLabel($rows, 'project', ['entries', 'tracked', 'pending']);
        usort($rows, static fn (array $a, array $b): int => (int) $b['tracked'] <=> (int) $a['tracked']);

        foreach ($rows as $row) {
            foreach (array_keys($totals) as $key) {
                $totals[$key] += (int) $row[$key];
            }
        }

        return new ReportResult(
            columns: [
                ReportColumn::text('project', 'Project'),
                ReportColumn::number('entries', 'Entries'),
                ReportColumn::minutes('tracked', 'Tracked'),
                ReportColumn::minutes('pending', 'Awaiting approval'),
            ],
            rows: $rows,
            totals: ['project' => 'All time in window', ...$totals],
            charts: [
                ReportChart::donut('Tracked time by project', array_map(static fn (array $row): array => [
                    'label' => (string) $row['project'],
                    'value' => (int) $row['tracked'],
                ], array_values(array_filter($rows, static fn (array $row): bool => (int) $row['tracked'] > 0)))),
            ],
            notes: [
                'Tracked is approved time — the same "counted" rule the timesheet and the '
                .'daily work summary use. Time awaiting a decision is shown beside it and '
                .'never added into it.',
                'Time not linked to a project you can open is grouped as Unassigned, so the '
                .'total still matches the timesheet.',
            ],
            empty: 'No time was tracked in this window.',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Money
    |--------------------------------------------------------------------------
    */

    /**
     * Finance — *what came in and what went out?*
     *
     * One row per calendar month the window touches, and **every figure on it is
     * `FinanceService::monthlyRollup()`'s**, not a second `SUM` over the same tables. That is
     * the whole reason this method looks thin: two statements about September is precisely the
     * failure the contract's rule 2 exists to prevent, and the suite asserts that a window of
     * exactly one calendar month equals the rollup for that month, string for string.
     *
     * The footer adds the months up in **integer cents**, the same arithmetic the rollup uses
     * internally. For a single-month window the sum is one term, so the round trip is the
     * identity and the footer is literally the rollup's own strings.
     */
    private function financeReport(User $viewer, ReportFilters $filters): ReportResult
    {
        $rows = [];
        $income = 0;
        $expenses = 0;
        $incomeCategories = [];
        $expenseCategories = [];

        foreach ($filters->months() as $month) {
            $rollup = $this->finance->monthlyRollup($viewer, (int) $month->year, (int) $month->month);

            $rows[] = [
                'month' => (string) $rollup['label'],
                'income' => (string) $rollup['income']['total'],
                'expenses' => (string) $rollup['expenses']['total'],
                'net' => (string) $rollup['net'],
            ];

            $income += self::toCents((string) $rollup['income']['total']);
            $expenses += self::toCents((string) $rollup['expenses']['total']);

            foreach ($rollup['income']['categories'] as $line) {
                $incomeCategories[$line['name']] = ($incomeCategories[$line['name']] ?? 0) + self::toCents($line['total']);
            }

            foreach ($rollup['expenses']['categories'] as $line) {
                $expenseCategories[$line['name']] = ($expenseCategories[$line['name']] ?? 0) + self::toCents($line['total']);
            }
        }

        return new ReportResult(
            columns: [
                ReportColumn::text('month', 'Month'),
                ReportColumn::money('income', 'Income'),
                ReportColumn::money('expenses', 'Expenses'),
                ReportColumn::money('net', 'Operating result'),
            ],
            rows: $rows,
            totals: [
                'month' => count($rows) === 1 ? 'Month total' : 'Range total',
                'income' => self::fromCents($income),
                'expenses' => self::fromCents($expenses),
                'net' => self::fromCents($income - $expenses),
            ],
            charts: [
                ReportChart::donut('Income by category', self::moneySeries($incomeCategories)),
                ReportChart::donut('Expenses by category', self::moneySeries($expenseCategories)),
            ],
            notes: [
                'Every figure here is the same monthly rollup the Finance screens show — this '
                .'is a different cut of it, never a second calculation.',
                'A category with nothing in it this month is absent rather than shown as zero.',
            ],
            empty: 'Nothing was recorded in this window.',
        );
    }

    /**
     * Payroll — *what did each period cost?*
     *
     * One row per payroll period whose month falls in the window, aggregated over
     * `PayrollItem::visibleTo()` — the same scope a payslip is fetched through, so this report
     * can never widen who a figure is about. `admin_notes` is not read here and has no column:
     * it is the one field the Accountant never receives (Part D §14) and a report is not the
     * place it starts arriving.
     *
     * The money is summed by PostgreSQL and cast to text in the same query that produced the
     * rows — it is never a float and never added up in PHP.
     */
    private function payroll(User $viewer, ReportFilters $filters): ReportResult
    {
        $rows = $this->payrollAggregate($viewer, $filters, grouped: true);
        $totals = $this->payrollAggregate($viewer, $filters, grouped: false);
        $footer = $totals[0] ?? null;

        return new ReportResult(
            columns: [
                ReportColumn::text('month', 'Period'),
                ReportColumn::text('status', 'Status'),
                ReportColumn::number('employees', 'Lines'),
                ReportColumn::money('gross', 'Gross'),
                ReportColumn::money('deductions', 'Deductions'),
                ReportColumn::money('net', 'Net'),
            ],
            rows: $rows,
            totals: $footer === null ? null : [
                'month' => 'All periods in window',
                'status' => null,
                'employees' => (int) $footer['employees'],
                'gross' => (string) $footer['gross'],
                'deductions' => (string) $footer['deductions'],
                'net' => (string) $footer['net'],
            ],
            charts: [
                ReportChart::bar('Net cost by period', array_map(static fn (array $row): array => [
                    'label' => (string) $row['month'],
                    'value' => (string) $row['net'],
                ], $rows)),
            ],
            notes: [
                'Gross is base salary, allowance and bonus. Deductions are deduction, advance '
                .'and the leave impact Calculate worked out. Net is the column PostgreSQL '
                .'computes on every line.',
                'A draft period is a draft: its figures change until it is approved.',
            ],
            empty: 'No payroll period falls in this window.',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Work — the four project cuts Part D §15 adds
    |--------------------------------------------------------------------------
    */

    /**
     * Completion — *how much of what was started got finished, and how long did it take?*
     *
     * One row per project that has work in the window. A project with a quiet month is **absent**
     * rather than a row of zeroes with a meaningless average beside it — the Task report settled
     * the same question the same way, and "0 days" under "Avg days" would be a claim about work
     * nobody did.
     *
     * ## "How long" is measured from the START DATE to the completion
     *
     * The spec does not say against what, and there are only three candidates: the task's start
     * date, its creation, or its due date. `due_date` would measure lateness, which the Overdue
     * report already states. `created_at` would measure how long a task sat in the system — and
     * on a reseeded demo it is *later* than `completed_at`, because the seeder writes history
     * onto rows it creates today, so the column would print zeroes for every seeded task and
     * nobody would notice until real data arrived.
     *
     * So it is `start_date → completed_at`: the work's own span, in whole days. A completed task
     * with no start date cannot be measured and is **not** counted as zero — the **Measured**
     * column is how many of the completed tasks the average is actually over, stated beside it
     * rather than buried in a footnote, because an average whose denominator is invisible is the
     * easiest figure in a report to misread.
     *
     * Every count is `TaskService`'s: the rows are `TaskService::query()` with the same filters
     * `count()` would have run, fetched once for the whole report and grouped here rather than
     * asked per project — so the Completed column and the list its link opens are one predicate,
     * and the window is the application's own overlap rule.
     */
    private function completion(User $viewer, ReportFilters $filters): ReportResult
    {
        $projects = $this->projectsFor($viewer, $filters);
        $all = $this->tasksByProject($this->windowTasks($viewer, $filters));
        $done = $this->tasksByProject($this->windowTasks($viewer, $filters, TaskBucket::Completed));
        $open = $this->tasksByProject($this->windowTasks($viewer, $filters, TaskBucket::Open));

        $rows = [];
        $totals = ['tasks' => 0, 'completed' => 0, 'open' => 0, 'measured' => 0];
        $everyDay = [];

        foreach ($projects as $project) {
            $id = (int) $project->getKey();
            $tasks = count($all[$id] ?? []);

            if ($tasks === 0) {
                continue;
            }

            $days = $this->daysToComplete($done[$id] ?? []);
            $everyDay = [...$everyDay, ...$days];

            $row = [
                'project' => (string) $project->name,
                'project_href' => '/admin/projects/'.$id,
                'tasks' => $tasks,
                'tasks_href' => $this->taskHref($filters, ['project_id' => $id]),
                'completed' => count($done[$id] ?? []),
                'completed_href' => $this->taskHref($filters, ['project_id' => $id, 'bucket' => TaskBucket::Completed]),
                'open' => count($open[$id] ?? []),
                'open_href' => $this->taskHref($filters, ['project_id' => $id, 'bucket' => TaskBucket::Open]),
                'measured' => count($days),
                'average_days' => self::wholeAverage($days),
                'longest_days' => $days === [] ? 0 : max($days),
            ];

            foreach (array_keys($totals) as $key) {
                $totals[$key] += (int) $row[$key];
            }

            $rows[] = $row;
        }

        return new ReportResult(
            columns: [
                ReportColumn::text('project', 'Project')->linkedBy('project_href'),
                ReportColumn::number('tasks', 'Tasks in window')->linkedBy('tasks_href'),
                ReportColumn::number('completed', 'Completed')->linkedBy('completed_href'),
                ReportColumn::number('open', 'Still open')->linkedBy('open_href'),
                ReportColumn::number('measured', 'Measured'),
                ReportColumn::number('average_days', 'Avg days to finish'),
                ReportColumn::number('longest_days', 'Longest'),
            ],
            rows: $rows,
            totals: [
                'project' => 'All projects',
                ...$totals,
                // The average over every measured task, NOT the average of the per-project
                // averages: a project with one task would otherwise weigh as much as one with
                // twenty.
                'average_days' => self::wholeAverage($everyDay),
                'longest_days' => $everyDay === [] ? 0 : max($everyDay),
            ],
            charts: [
                ReportChart::donut('Completed work by project', array_values(array_filter(array_map(
                    static fn (array $row): array => ['label' => (string) $row['project'], 'value' => (int) $row['completed']],
                    $rows,
                ), static fn (array $slice): bool => $slice['value'] > 0))),
                ReportChart::bar('Days from start to finish', array_values(array_filter(array_map(
                    static fn (array $row): array => ['label' => (string) $row['project'], 'value' => (int) $row['average_days']],
                    $rows,
                ), static fn (array $slice): bool => $slice['value'] > 0))),
            ],
            notes: [
                'A task is in the window when its start or due date falls inside it — the same '
                .'window the Tasks List uses, and every count here opens that list.',
                'Days to finish is whole days from a task\'s start date to the moment it was '
                .'completed. A completed task with no start date cannot be measured and is left '
                .'out of the average rather than counted as nought — "Measured" is how many the '
                .'average is over.',
            ],
            empty: 'No work was started or finished in this window.',
        );
    }

    /**
     * Maintenance and SEO — *what happened on the retainers this period?*
     *
     * ## What a "period" row is, and why
     *
     * **One row per project per retainer period, and the period is the one the recurring engine
     * stamped on the work** — `tasks.recurring_period`, read through
     * `RecurrenceRule::labelForPeriod()`. `2026-10` prints *October 2026* and `2026-W41` prints
     * *Week 41, 2026*, which is the same word the task detail screen uses for the same task.
     *
     * The alternative was a calendar month of my own, computed from the due date. That would be
     * a **second definition of a period** in an application whose whole recurring engine exists
     * to own the first — and it would be wrong for any template that is not monthly, which
     * `RecurrenceFrequency` already allows two of. A retainer's period is whatever cycle
     * generated its work, not whatever month the work happened to land in.
     *
     * Work on a retainer that no template generated has no period, and it is not forced into
     * one: it is grouped as **Ad hoc** and sorted last. That is most of what a retainer month
     * actually contains — the breakage tickets and the extra requests — and folding it into a
     * period would have been an invention, while dropping it would have made the rows disagree
     * with the project's task list.
     *
     * Tracked time is the approved time logged in the window against that period's tasks, from
     * `time_entries` through `scopeCounted()` — never `tasks.tracked_seconds`, which is a cache
     * carrying Phase 2's fabricated figures (the reason `WorkloadService` refuses it too).
     */
    private function retainer(User $viewer, ReportFilters $filters, ReportKey $key): ReportResult
    {
        $isSeo = $key === ReportKey::Seo;
        $projects = $this->projectsFor($viewer, $filters, $isSeo
            ? [ProjectType::Seo]
            : [ProjectType::WebsiteMaintenance]);

        $all = $this->tasksByPeriod($this->windowTasks($viewer, $filters));
        $done = $this->tasksByPeriod($this->windowTasks($viewer, $filters, TaskBucket::Completed));
        $late = $this->tasksByPeriod($this->windowTasks($viewer, $filters, TaskBucket::Overdue));
        $tracked = $this->trackedMinutesByPeriod($viewer, $filters);

        $rows = [];
        $totals = ['tasks' => 0, 'completed' => 0, 'overdue' => 0, 'tracked' => 0];

        foreach ($projects as $project) {
            $id = (int) $project->getKey();

            foreach (self::periodsOf($all, $id) as $period) {
                $cell = self::cellKey($id, $period);

                $row = [
                    'project' => (string) $project->name,
                    'project_href' => '/admin/projects/'.$id,
                    'period' => self::periodLabel($period),
                    'tasks' => count($all[$cell] ?? []),
                    'tasks_href' => $this->taskHref($filters, ['project_id' => $id]),
                    'completed' => count($done[$cell] ?? []),
                    'completed_href' => $this->taskHref($filters, ['project_id' => $id, 'bucket' => TaskBucket::Completed]),
                    'overdue' => count($late[$cell] ?? []),
                    'overdue_href' => $this->taskHref($filters, ['project_id' => $id, 'bucket' => TaskBucket::Overdue]),
                    'tracked' => $tracked[$cell] ?? 0,
                ];

                foreach (array_keys($totals) as $totalKey) {
                    $totals[$totalKey] += (int) $row[$totalKey];
                }

                $rows[] = $row;
            }
        }

        $what = $isSeo ? 'SEO' : 'maintenance';

        return new ReportResult(
            columns: [
                ReportColumn::text('project', 'Retainer')->linkedBy('project_href'),
                ReportColumn::text('period', 'Period'),
                ReportColumn::number('tasks', 'Tasks')->linkedBy('tasks_href'),
                ReportColumn::number('completed', 'Completed')->linkedBy('completed_href'),
                ReportColumn::number('overdue', 'Overdue')->linkedBy('overdue_href'),
                ReportColumn::minutes('tracked', 'Tracked'),
            ],
            rows: $rows,
            totals: ['project' => 'All '.$what.' retainers', ...$totals],
            charts: [
                ReportChart::donut('Work by retainer', $this->countBy(
                    array_map(static fn (array $row): string => (string) $row['project'], $rows),
                )),
                ReportChart::bar('Tracked time by period', array_values(array_filter(array_map(
                    static fn (array $row): array => [
                        'label' => $row['project'].' · '.$row['period'],
                        'value' => (int) $row['tracked'],
                    ],
                    $rows,
                ), static fn (array $slice): bool => $slice['value'] > 0)), ReportFormat::Minutes),
            ],
            notes: [
                'A period is the cycle the recurring engine generated the work for — the same '
                .'period the task itself carries. Work no template generated has no period and '
                .'is grouped as Ad hoc rather than filed under a month it did not belong to.',
                'Tracked is approved time logged in this window against that period\'s tasks. '
                .'Time you may not see is not counted, so somebody who can open a project '
                .'without being able to read other people\'s hours sees only their own here.',
            ],
            empty: 'No '.$what.' retainer had work in this window.',
        );
    }

    /**
     * Website Project — *where does each website build stand?*
     *
     * A build is not a retainer: there is no cycle, so there is no period row. One row per build
     * project the viewer may open — every one of them, whether or not it has work in the window,
     * because the row is about the build and a build with a quiet fortnight has not stopped
     * existing (the Project report's rule, for the same reason).
     *
     * A build is `ProjectType::WebsiteDevelopment`, `WooCommerce` or `WebApplication` — the three
     * types that are somebody delivering a site. `Marketing`, `Internal` and `Other` are not
     * builds and `Seo`/`WebsiteMaintenance` have their own two reports.
     *
     * **The client column is absent for a viewer without `clients.view_full`** and the domain
     * stands in its place (Part C §2), asked here rather than hidden in the renderer.
     */
    private function websiteProject(User $viewer, ReportFilters $filters): ReportResult
    {
        $mayReadClients = $viewer->hasPermission(Permission::ClientsViewFull);

        $projects = $this->projectsFor($viewer, $filters, [
            ProjectType::WebsiteDevelopment,
            ProjectType::WooCommerce,
            ProjectType::WebApplication,
        ], $mayReadClients);

        $all = $this->tasksByProject($this->windowTasks($viewer, $filters));
        $done = $this->tasksByProject($this->windowTasks($viewer, $filters, TaskBucket::Completed));
        $late = $this->tasksByProject($this->windowTasks($viewer, $filters, TaskBucket::Overdue));
        $tracked = $this->minutesByProject($viewer, $filters, fn (Builder $q) => $q->counted());

        $rows = [];
        $totals = ['tasks' => 0, 'completed' => 0, 'overdue' => 0, 'tracked' => 0];

        foreach ($projects as $project) {
            $id = (int) $project->getKey();
            $status = $project->status instanceof ProjectStatus ? $project->status : null;

            $row = [
                'project' => (string) $project->name,
                'project_href' => '/admin/projects/'.$id,
            ];

            if ($mayReadClients) {
                $row['client'] = $project->client?->name ?? '—';
            }

            $row += [
                'domain' => (string) ($project->domain ?? '—'),
                'status' => $status?->label() ?? '—',
                'deadline' => $project->deadline?->toDateString(),
                'tasks' => count($all[$id] ?? []),
                'tasks_href' => $this->taskHref($filters, ['project_id' => $id]),
                'completed' => count($done[$id] ?? []),
                'completed_href' => $this->taskHref($filters, ['project_id' => $id, 'bucket' => TaskBucket::Completed]),
                'overdue' => count($late[$id] ?? []),
                'overdue_href' => $this->taskHref($filters, ['project_id' => $id, 'bucket' => TaskBucket::Overdue]),
                'tracked' => $tracked[$id] ?? 0,
            ];

            foreach (array_keys($totals) as $key) {
                $totals[$key] += (int) $row[$key];
            }

            $rows[] = $row;
        }

        $columns = [ReportColumn::text('project', 'Build')->linkedBy('project_href')];

        if ($mayReadClients) {
            $columns[] = ReportColumn::text('client', 'Client');
        }

        return new ReportResult(
            columns: [
                ...$columns,
                ReportColumn::text('domain', 'Domain'),
                ReportColumn::text('status', 'Status'),
                ReportColumn::date('deadline', 'Deadline'),
                ReportColumn::number('tasks', 'Tasks in window')->linkedBy('tasks_href'),
                ReportColumn::number('completed', 'Completed')->linkedBy('completed_href'),
                ReportColumn::number('overdue', 'Overdue')->linkedBy('overdue_href'),
                ReportColumn::minutes('tracked', 'Tracked'),
            ],
            rows: $rows,
            totals: ['project' => 'All builds', ...$totals],
            charts: [
                ReportChart::donut('Builds by status', $this->countBy(
                    $projects->map(fn (Project $p): string => $p->status?->label() ?? '—')->all(),
                )),
                ReportChart::bar('Tracked time by build', array_values(array_filter(array_map(
                    static fn (array $row): array => ['label' => (string) $row['project'], 'value' => (int) $row['tracked']],
                    $rows,
                ), static fn (array $slice): bool => $slice['value'] > 0)), ReportFormat::Minutes),
            ],
            notes: [
                'A build is a website development, WooCommerce or web application project. SEO '
                .'and maintenance retainers have their own two reports, and the task counts here '
                .'are for the chosen window while the rows are every build you can open.',
            ],
            empty: 'No website build to report on.',
        );
    }

    /**
     * Performance — **PROJECT** performance (Part D §2's table, and nothing else).
     *
     * Estimated against tracked hours per project, the share of the window's work that is
     * finished, and the share that is late. Part D §2 defines the report in exactly those terms
     * and adds the sentence that matters most: *"per project, never per person (spec §30 forbids
     * scoring)"*.
     *
     * So there is **no employee row, no employee column and no employee filter** here, and that
     * is not an omission to be helpfully corrected later: a per-person version of this table is
     * the single thing Part H §1 forbids outright, and the two rates below are exactly what would
     * turn into a target the moment a name was put beside them.
     *
     * A project with no work in the window is absent rather than a row of zero rates: nought
     * completed of nought tasks is `0/0`, and printing it as 0 % would be a judgement invented
     * by a division.
     *
     * Estimated minutes are `tasks.estimated_minutes` summed over the window's tasks; a task with
     * no estimate contributes nothing, which is why **Unestimated** is a column rather than a
     * silence. Tracked minutes come from `time_entries` through `scopeCounted()`, never from
     * `tasks.tracked_seconds` — `WorkloadService` records why that cache is not a source. The two
     * columns sit side by side and are never combined into a variance: the spec asks for the
     * comparison, and the reader is the one who makes it.
     */
    private function performance(User $viewer, ReportFilters $filters): ReportResult
    {
        $projects = $this->projectsFor($viewer, $filters);
        $all = $this->tasksByProject($this->windowTasks($viewer, $filters));
        $done = $this->tasksByProject($this->windowTasks($viewer, $filters, TaskBucket::Completed));
        $late = $this->tasksByProject($this->windowTasks($viewer, $filters, TaskBucket::Overdue));
        $tracked = $this->minutesByProject($viewer, $filters, fn (Builder $q) => $q->counted());

        $rows = [];
        $totals = ['tasks' => 0, 'completed' => 0, 'overdue' => 0, 'unestimated' => 0, 'estimated' => 0, 'tracked' => 0];

        foreach ($projects as $project) {
            $id = (int) $project->getKey();
            $tasks = $all[$id] ?? [];

            if ($tasks === []) {
                continue;
            }

            $count = count($tasks);
            $completed = count($done[$id] ?? []);
            $overdue = count($late[$id] ?? []);

            $row = [
                'project' => (string) $project->name,
                'project_href' => '/admin/projects/'.$id,
                'tasks' => $count,
                'tasks_href' => $this->taskHref($filters, ['project_id' => $id]),
                'completed' => $completed,
                'completed_href' => $this->taskHref($filters, ['project_id' => $id, 'bucket' => TaskBucket::Completed]),
                'completed_share' => (int) round($completed / $count * 100),
                'overdue' => $overdue,
                'overdue_href' => $this->taskHref($filters, ['project_id' => $id, 'bucket' => TaskBucket::Overdue]),
                'overdue_share' => (int) round($overdue / $count * 100),
                'estimated' => array_sum(array_map(
                    static fn (Task $task): int => (int) ($task->estimated_minutes ?? 0),
                    $tasks,
                )),
                'unestimated' => count(array_filter(
                    $tasks,
                    static fn (Task $task): bool => $task->estimated_minutes === null,
                )),
                'tracked' => $tracked[$id] ?? 0,
            ];

            foreach (array_keys($totals) as $key) {
                $totals[$key] += (int) $row[$key];
            }

            $rows[] = $row;
        }

        $tasks = $totals['tasks'];

        return new ReportResult(
            columns: [
                ReportColumn::text('project', 'Project')->linkedBy('project_href'),
                ReportColumn::number('tasks', 'Tasks in window')->linkedBy('tasks_href'),
                ReportColumn::number('completed', 'Completed')->linkedBy('completed_href'),
                ReportColumn::percent('completed_share', 'Completed of window'),
                ReportColumn::number('overdue', 'Overdue')->linkedBy('overdue_href'),
                ReportColumn::percent('overdue_share', 'Overdue of window'),
                ReportColumn::minutes('estimated', 'Estimated'),
                ReportColumn::number('unestimated', 'No estimate'),
                ReportColumn::minutes('tracked', 'Tracked'),
            ],
            rows: $rows,
            totals: [
                'project' => 'All projects with work',
                ...$totals,
                'completed_share' => $tasks === 0 ? 0 : (int) round($totals['completed'] / $tasks * 100),
                'overdue_share' => $tasks === 0 ? 0 : (int) round($totals['overdue'] / $tasks * 100),
            ],
            charts: [
                ReportChart::bar('Estimated time by project', array_values(array_filter(array_map(
                    static fn (array $row): array => ['label' => (string) $row['project'], 'value' => (int) $row['estimated']],
                    $rows,
                ), static fn (array $slice): bool => $slice['value'] > 0)), ReportFormat::Minutes),
                ReportChart::bar('Tracked time by project', array_values(array_filter(array_map(
                    static fn (array $row): array => ['label' => (string) $row['project'], 'value' => (int) $row['tracked']],
                    $rows,
                ), static fn (array $slice): bool => $slice['value'] > 0)), ReportFormat::Minutes),
            ],
            notes: [
                'Every row is a project. There is no per-person row, column or filter here by '
                .'design (Part D §2, Part H §1): this report compares work with its estimate, '
                .'never one person with another.',
                'The two shares are of the window\'s own tasks — completed of them, and overdue '
                .'of them. A project with no work in the window is left out rather than shown as '
                .'nought per cent of nothing.',
                'Estimated is the sum of the task estimates; a task without one adds nothing and '
                .'is counted under "No estimate". Tracked is approved time from the timesheet, '
                .'never the cached figure on a task. The two are never subtracted from each other.',
            ],
            empty: 'No project had work in this window.',
        );
    }

    /**
     * Meeting — *what was met about, and what came out of it?*
     *
     * One row per meeting in the window, oldest first, through `Meeting::visibleTo()` — which is
     * `MeetingPolicy::view()` in SQL: an Admin sees every meeting, anybody else sees the ones
     * they organise or sit in.
     *
     * ## The project on the row is the one the viewer may be TOLD about
     *
     * `MeetingService::linkedContextFor()` decides it, not this file: Part D §12's case is a
     * participant who is in the room for a project they are not on, and the rule is that they see
     * the meeting and **never learn the project's name** (the seeded Buffalo review exists to
     * prove it). So the cell is an em dash both for a meeting with no project and for one whose
     * project is out of reach — the two are deliberately indistinguishable, which is Part C §1's
     * *absent, not null* applied to a cell.
     *
     * ## What "came out of it" is
     *
     * Notes and decisions were recorded, or they were not; and the action items that became
     * tasks — counted through `Task::visibleTo()`, so a meeting cannot report tasks the reader
     * may not open. Neither the notes nor the decisions are printed here: this report is a
     * register of meetings, and the text belongs on the meeting, which the title links to.
     *
     * A cancelled meeting is listed with the length it was scheduled for. The status column is
     * what says it did not happen, and dropping its minutes from the footer would make the
     * footer disagree with the column above it.
     */
    private function meeting(User $viewer, ReportFilters $filters): ReportResult
    {
        $meetings = Meeting::query()
            ->visibleTo($viewer)
            ->whereBetween('meetings.start_at', [
                $filters->from->copy()->startOfDay(),
                $filters->to->copy()->endOfDay(),
            ])
            // A project id the viewer cannot open narrows to nothing rather than refusing,
            // because the `whereIn` is built from `Project::visibleTo()` (10-26).
            ->when($filters->projectId, fn (Builder $q, int $id) => $q->whereIn(
                'meetings.project_id',
                Project::query()->visibleTo($viewer)->where('projects.id', $id)->select('projects.id'),
            ))
            ->with(['project', 'note'])
            ->withCount([
                'participants',
                'actionItemTasks as action_items_count' => fn (Builder $q) => $q->visibleTo($viewer),
            ])
            ->orderBy('meetings.start_at')
            ->limit(self::MAX_ROWS)
            ->get();

        $labels = [];

        foreach (MeetingStatus::cases() as $status) {
            $labels[$status->tone()] = $status->label();
        }

        $rows = [];
        $totals = ['people' => 0, 'minutes' => 0, 'action_items' => 0];
        $byStatus = [];

        foreach ($meetings as $meeting) {
            $status = $meeting->status instanceof MeetingStatus ? $meeting->status : MeetingStatus::Scheduled;
            $minutes = $meeting->start_at === null || $meeting->end_at === null
                ? 0
                : (int) $meeting->start_at->diffInMinutes($meeting->end_at);

            $note = $meeting->note;

            $row = [
                'date' => $meeting->start_at?->toDateString(),
                'meeting' => (string) $meeting->title,
                'meeting_href' => '/meetings/'.$meeting->getKey(),
                'project' => (string) ($this->meetings->linkedContextFor($viewer, $meeting)['project']['name'] ?? '—'),
                'people' => (int) $meeting->getAttribute('participants_count'),
                'minutes' => $minutes,
                'status' => $status->tone(),
                'notes' => $note !== null && (trim((string) $note->notes) !== '' || trim((string) $note->decisions) !== '')
                    ? 'Recorded'
                    : '—',
                'action_items' => (int) $meeting->getAttribute('action_items_count'),
            ];

            $byStatus[$status->value] = [
                'label' => $status->label(),
                'tone' => $status->tone(),
                'value' => ($byStatus[$status->value]['value'] ?? 0) + 1,
            ];

            foreach (array_keys($totals) as $key) {
                $totals[$key] += (int) $row[$key];
            }

            $rows[] = $row;
        }

        $byProject = [];

        foreach ($rows as $row) {
            $label = $row['project'] === '—' ? 'No project shown' : (string) $row['project'];
            $byProject[$label] = ($byProject[$label] ?? 0) + (int) $row['minutes'];
        }

        arsort($byProject);

        return new ReportResult(
            columns: [
                ReportColumn::date('date', 'Date'),
                ReportColumn::text('meeting', 'Meeting')->linkedBy('meeting_href'),
                ReportColumn::text('project', 'Project'),
                ReportColumn::number('people', 'In the room'),
                ReportColumn::minutes('minutes', 'Length'),
                ReportColumn::status('status', 'Status', $labels),
                ReportColumn::text('notes', 'Notes'),
                ReportColumn::number('action_items', 'Tasks created')->linkedBy('meeting_href'),
            ],
            rows: $rows,
            totals: ['meeting' => count($rows).' meetings', ...$totals],
            charts: [
                ReportChart::donut('Meeting time by project', array_values(array_map(
                    static fn (string $label): array => ['label' => $label, 'value' => $byProject[$label]],
                    array_keys(array_filter($byProject, static fn (int $minutes): bool => $minutes > 0)),
                )), ReportFormat::Minutes),
                ReportChart::donut('Meetings by status', array_values($byStatus)),
            ],
            notes: [
                'A meeting linked to a project you cannot open shows no project, the same way the '
                .'meeting page does — so an em dash here means either no project or not yours.',
                '"Tasks created" counts the action items that became tasks you can open. The '
                .'notes and decisions themselves live on the meeting, which the title opens.',
                'A cancelled meeting is listed with the length it was scheduled for; the status '
                .'is what says it did not happen.',
            ],
            empty: 'No meeting falls in this window.',
        );
    }

    /**
     * In-app coordination — *how much of the talking is happening in here?* (Part D §15, AC6.)
     *
     * Messages per week in task and project conversations against the team channel and DMs, per
     * project. **A count, not a score.** Spec AC6 is the client's judgement that 80 % of
     * coordination should happen in the app; this report exists to put the numbers in front of
     * that sign-off and makes no claim of its own — there is no percentage, no target and no
     * per-person row anywhere in it.
     *
     * ## Who is counted, and why there is no new access rule
     *
     * `Conversation` has no `visibleTo()` scope, because a conversation's audience is **computed,
     * never stored** — `ConversationPolicy` delegates to the linked task, the linked project, the
     * messaging permission, or the two ids on a DM row. Rather than write a report's own version
     * of that, each kind is counted through the thing that already decides it:
     *
     *   - task discussions — the conversation's task is in `Task::visibleTo()`;
     *   - project channels — the conversation's project is in `Project::visibleTo()`;
     *   - the team and announcement channels — `Gate::allows('view')`, the policy itself, asked
     *     once per channel because there are two of them;
     *   - DMs — `Conversation::dmsFor($viewer)`, the model's own statement of a DM's two people,
     *     which is the same comparison the policy makes. **A DM between two other people is not
     *     counted and never named**, for an Admin as much as for anybody else.
     *
     * ## The two dimensions
     *
     * The table is *per project* (the third dimension Part D §15 asks for), with the team channel,
     * the announcements channel and direct messages as their own rows — a project row and a DM
     * row cannot be added together, so the `kind` column says which is which. *Per week* is the
     * bar chart, bucketed by ISO week in the application's own timezone and labelled with the
     * same `RecurrenceRule::labelForPeriod()` vocabulary the recurring engine prints.
     */
    private function inAppCoordination(User $viewer, ReportFilters $filters): ReportResult
    {
        $projects = $this->projectsFor($viewer, $filters);

        // conversation id => the project its TASK belongs to.
        $taskChannels = Conversation::query()
            ->ofType(ConversationType::Task)
            ->join('tasks', 'tasks.id', '=', 'conversations.linked_task_id')
            ->whereIn('tasks.id', Task::query()->visibleTo($viewer)->select('tasks.id'))
            ->pluck('tasks.project_id', 'conversations.id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        // conversation id => the project it IS the channel of.
        $projectChannels = Conversation::query()
            ->ofType(ConversationType::Project)
            ->whereIn('conversations.linked_project_id', $projects->modelKeys())
            ->pluck('conversations.linked_project_id', 'conversations.id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        // The two company-wide channels, each asked of the policy rather than of a rule of mine.
        $channels = Conversation::query()
            ->whereIn('conversations.type', [ConversationType::Team->value, ConversationType::Announcement->value])
            ->get()
            ->filter(fn (Conversation $conversation): bool => Gate::forUser($viewer)->allows('view', $conversation));

        $dms = Conversation::query()->dmsFor($viewer)->pluck('conversations.id')->all();

        $counts = $this->messagesByConversation($filters, [
            ...array_keys($taskChannels),
            ...array_keys($projectChannels),
            ...$channels->modelKeys(),
            ...$dms,
        ]);

        $inTasks = [];
        $inChannels = [];

        foreach ($taskChannels as $conversationId => $projectId) {
            $inTasks[$projectId] = ($inTasks[$projectId] ?? 0) + ($counts[$conversationId] ?? 0);
        }

        foreach ($projectChannels as $conversationId => $projectId) {
            $inChannels[$projectId] = ($inChannels[$projectId] ?? 0) + ($counts[$conversationId] ?? 0);
        }

        $rows = [];
        $totals = ['tasks' => 0, 'channel' => 0, 'messages' => 0];
        $onTheWork = 0;
        $elsewhere = 0;

        foreach ($projects as $project) {
            $id = (int) $project->getKey();
            $channelId = array_search($id, $projectChannels, true);

            $row = [
                'where' => (string) $project->name,
                // The project's channel if it has one, and the project itself if it does not.
                // Nothing here creates a conversation: `ConversationService::forProject()` is a
                // firstOrCreate, and a report does not write.
                'where_href' => $channelId === false ? '/admin/projects/'.$id : '/messages/'.$channelId,
                'kind' => 'Project work',
                'tasks' => $inTasks[$id] ?? 0,
                'channel' => $inChannels[$id] ?? 0,
                'messages' => ($inTasks[$id] ?? 0) + ($inChannels[$id] ?? 0),
            ];

            $onTheWork += (int) $row['messages'];

            foreach (array_keys($totals) as $key) {
                $totals[$key] += (int) $row[$key];
            }

            $rows[] = $row;
        }

        foreach ($channels as $channel) {
            $messages = $counts[(int) $channel->getKey()] ?? 0;

            $rows[] = [
                'where' => $channel->type === ConversationType::Announcement ? 'Announcements' : 'Team channel',
                'where_href' => '/messages/'.$channel->getKey(),
                'kind' => 'Team and DMs',
                'tasks' => 0,
                'channel' => $messages,
                'messages' => $messages,
            ];

            $elsewhere += $messages;
            $totals['channel'] += $messages;
            $totals['messages'] += $messages;
        }

        // Absent rather than a row of zeroes: somebody with no DMs at all has no direct-message
        // line to report on, and a zero row would be a fact about their inbox dressed up as a
        // fact about the agency's habits.
        if ($dms !== []) {
            $messages = array_sum(array_map(static fn (int $id): int => $counts[$id] ?? 0, $dms));

            $rows[] = [
                'where' => 'Direct messages',
                'where_href' => '/messages',
                'kind' => 'Team and DMs',
                'tasks' => 0,
                'channel' => $messages,
                'messages' => $messages,
            ];

            $elsewhere += $messages;
            $totals['channel'] += $messages;
            $totals['messages'] += $messages;
        }

        return new ReportResult(
            columns: [
                ReportColumn::text('where', 'Where')->linkedBy('where_href'),
                ReportColumn::text('kind', 'Kind'),
                ReportColumn::number('tasks', 'In task discussions'),
                ReportColumn::number('channel', 'In the channel'),
                ReportColumn::number('messages', 'Messages'),
            ],
            rows: $rows,
            totals: ['where' => 'Everywhere you can read', 'kind' => null, ...$totals],
            charts: [
                ReportChart::bar('Messages per week', $this->messagesByWeek($filters, [
                    ...array_keys($taskChannels),
                    ...array_keys($projectChannels),
                    ...$channels->modelKeys(),
                    ...$dms,
                ])),
                ReportChart::donut('Where the talking happens', array_values(array_filter([
                    ['label' => 'Task and project conversations', 'value' => $onTheWork],
                    ['label' => 'Team channel and direct messages', 'value' => $elsewhere],
                ], static fn (array $slice): bool => $slice['value'] > 0))),
            ],
            notes: [
                'Counts, not a score. Spec AC6\'s 80 % is the client\'s judgement to make; this '
                .'report is the arithmetic underneath it and states no target of its own.',
                'Only conversations you can read are counted. A direct message between two other '
                .'people is not counted and not named, whoever is looking.',
                'Weeks are ISO weeks in the agency\'s own timezone, so the bars line up with the '
                .'same week numbers the recurring retainers use.',
            ],
            empty: 'There is nothing here to count — no project you can open, and no channel.',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Workforce — Leave
    |--------------------------------------------------------------------------
    */

    /**
     * Leave — *who took leave, of what kind, and how much is left?*
     *
     * One row per person in `Employee::leaveVisibleTo()`, **in name order** — the leave scope and
     * not the attendance one, because Part C §1 makes *Approve leave* and *Manage others'
     * attendance* two different cells and borrowing the wrong one would mean taking a key away
     * from a role silently moved this report's rows.
     *
     * Everybody in scope is a row, including somebody who took nothing: *how much is left* is an
     * answer about them whether or not they were away, and a balance of 15 is not an empty state.
     * That is the opposite of the Attendance report's rule and for the opposite reason — there,
     * a missing day is a day nobody recorded; here, an untouched balance is a fact.
     *
     * ## Which days count, and who decides
     *
     * `LeaveService::daysByDate()`, which expands each request into the days it actually covers
     * **on that employee's own schedule** and clips them to the window. A Friday on a
     * Sunday-to-Thursday week is not a leave day, and this report does not get to have its own
     * opinion about that — the balance it spent, the `unpaid_days` payroll reads and the
     * attendance rows the approval wrote all came from the same method.
     *
     * Only requests that **hold a place** are counted — `scopeHolding()`, pending and approved,
     * the same set the exclusion constraint is written against. A rejected request is not leave
     * anybody took, and counting it would make this report disagree with the calendar.
     */
    private function leaveReport(User $viewer, ReportFilters $filters): ReportResult
    {
        $people = Employee::query()
            ->leaveVisibleTo($viewer)
            ->when($filters->employeeId, fn (Builder $q, int $id) => $q->where('employees.id', $id))
            ->with('user')
            ->join('users', 'users.id', '=', 'employees.user_id')
            ->orderBy('users.name')
            ->select('employees.*')
            ->limit(self::MAX_ROWS)
            ->get();

        $requests = LeaveRequest::query()
            ->visibleTo($viewer)
            ->holding()
            ->overlapping($filters->from, $filters->to)
            ->when($filters->employeeId, fn (Builder $q, int $id) => $q->forEmployee($id))
            ->with(['employee.user', 'leaveType'])
            ->limit(self::MAX_ROWS)
            ->get();

        $days = collect($this->leave->daysByDate($requests, $filters->from, $filters->to))
            ->flatten(1);

        $capped = LeaveType::query()->capped()->inOrder()->get();
        $balanceKeys = [];

        foreach ($capped as $type) {
            $balanceKeys[(int) $type->getKey()] = 'left_'.Str::slug((string) $type->name, '_');
        }

        $rows = [];
        $totals = ['requests' => 0, 'days' => 0, 'approved' => 0, 'awaiting' => 0];

        foreach ($balanceKeys as $key) {
            $totals[$key] = 0;
        }

        foreach ($people as $employee) {
            $id = (int) $employee->getKey();
            $mine = $days->filter(fn (array $day): bool => (int) $day['employee_id'] === $id);

            $row = [
                'employee' => (string) ($employee->user?->name ?? 'Unknown'),
                'requests' => $requests->filter(
                    fn (LeaveRequest $request): bool => (int) $request->employee_id === $id,
                )->count(),
                // The count opens the queue it was counted from; the days open the month grid
                // that paints them, on the month the window is — when the window IS one month.
                'requests_href' => '/admin/leave',
                'days_href' => '/admin/leave/calendar'.($filters->isWholeMonth()
                    ? '?'.http_build_query(['month' => $filters->from->format('Y-m')])
                    : ''),
                'days' => $mine->count(),
                'approved' => $mine->where('status', LeaveStatus::Approved->value)->count(),
                'awaiting' => $mine->whereIn('status', [
                    LeaveStatus::Pending->value,
                    LeaveStatus::CorrectionRequested->value,
                ])->count(),
            ];

            foreach ($capped as $type) {
                $row[$balanceKeys[(int) $type->getKey()]] = $this->leave->balanceDays($employee, $type);
            }

            foreach (array_keys($totals) as $key) {
                $totals[$key] += (int) $row[$key];
            }

            $rows[] = $row;
        }

        $columns = [
            ReportColumn::text('employee', 'Employee'),
            ReportColumn::number('requests', 'Requests')->linkedBy('requests_href'),
            ReportColumn::number('days', 'Leave days')->linkedBy('days_href'),
            ReportColumn::number('approved', 'Approved'),
            ReportColumn::number('awaiting', 'Awaiting a decision'),
        ];

        foreach ($capped as $type) {
            $columns[] = ReportColumn::number(
                $balanceKeys[(int) $type->getKey()],
                $type->name.' left',
            );
        }

        return new ReportResult(
            columns: $columns,
            rows: $rows,
            totals: ['employee' => 'Everyone', ...$totals],
            charts: [
                ReportChart::donut('Leave days by type', $this->countBy(
                    $days->map(fn (array $day): string => (string) ($day['type'] ?? 'Unknown'))->all(),
                )),
                ReportChart::donut('Leave days by status', array_values($days
                    ->groupBy('status')
                    ->map(fn (Collection $group): array => [
                        'label' => (string) ($group->first()['status_label'] ?? ''),
                        'tone' => (string) ($group->first()['tone'] ?? ''),
                        'value' => $group->count(),
                    ])
                    ->all())),
            ],
            notes: [
                'Listed by name. A leave day is a working day on that person\'s own schedule '
                .'inside the window — the same expansion the calendar, the balance and payroll '
                .'all use, so a Friday on a Sunday-to-Thursday week is not counted.',
                'Only pending and approved requests are counted; a rejected one is not leave '
                .'anybody took. The balances are the days left today, not as at the end of the '
                .'window.',
            ],
            empty: 'Nobody\'s leave is yours to report on.',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | The shared plumbing
    |--------------------------------------------------------------------------
    */

    /**
     * The filters every task-backed report hands to `TaskService`.
     *
     * @return array<string, mixed>
     */
    private function taskFilters(ReportFilters $filters): array
    {
        return [
            'date_from' => $filters->from,
            'date_to' => $filters->to,
            'as_of' => $filters->asOf(),
            'project_id' => $filters->projectId,
            'assignee_id' => $filters->employeeId,
            // Decision 12-71: a report counts WORK, and a subtask is work somebody owes, so
            // reports include subtasks where the general Board and List leave them off.
            'subtasks' => true,
        ];
    }

    /**
     * One task count, through `TaskService` — including the client filter, which `TaskService`
     * has no key for.
     *
     * A client is a set of projects, so the filter is applied by asking the same question once
     * per project of that client and adding the answers: a task belongs to exactly one project,
     * so the sum is the count. The ids come from `Project::visibleTo()`, which is what makes
     * filtering by a client the viewer cannot see narrow to **nothing** rather than refuse —
     * a 404 there would confirm that the client exists (Part C §1).
     *
     * The alternative was to re-type the window predicate into a `GROUP BY` of my own, and two
     * spellings of "a task in this window" is exactly what the contract's rule 2 forbids.
     *
     * @param  array<string, mixed>  $extra
     */
    private function countTasks(User $viewer, ReportFilters $filters, array $extra = []): int
    {
        $base = [...$this->taskFilters($filters), ...$extra];

        if ($filters->clientId === null) {
            return $this->tasks->count($viewer, $base);
        }

        $projectIds = Project::query()
            ->visibleTo($viewer)
            ->where('projects.client_id', $filters->clientId)
            ->when(
                is_int($base['project_id'] ?? null),
                fn (Builder $q) => $q->where('projects.id', $base['project_id']),
            )
            ->pluck('projects.id')
            ->all();

        $count = 0;

        foreach ($projectIds as $projectId) {
            $count += $this->tasks->count($viewer, [...$base, 'project_id' => (int) $projectId]);
        }

        return $count;
    }

    /**
     * The people a report lists, narrowed by the employee filter, **in name order**.
     *
     * `Employee::attendanceVisibleTo()` is the scope, so somebody out of the viewer's reach is
     * absent from the list rather than refused — and an `?employee=` id outside it simply
     * matches nobody.
     *
     * @return EloquentCollection<int, Employee>
     */
    private function people(User $viewer, ReportFilters $filters)
    {
        return Employee::query()
            ->attendanceVisibleTo($viewer)
            ->when($filters->employeeId, fn (Builder $q, int $id) => $q->where('employees.id', $id))
            ->with('user')
            ->join('users', 'users.id', '=', 'employees.user_id')
            ->orderBy('users.name')
            ->select('employees.*')
            ->limit(self::MAX_ROWS)
            ->get();
    }

    /**
     * Minutes per project id (0 for "no project the viewer can open"), for one `TimeEntry`
     * scope.
     *
     * @param  callable(Builder): mixed  $narrow  the model scope that says which entries count
     * @return array<int, int>
     */
    private function minutesByProject(User $viewer, ReportFilters $filters, callable $narrow): array
    {
        $query = $this->timeEntries($viewer, $filters);
        $narrow($query);

        return $query
            ->selectRaw('coalesce(time_entries.project_id, 0) as project_key')
            ->selectRaw('floor(sum(time_entries.duration_seconds) / 60)::int as minutes')
            ->groupBy('project_key')
            ->pluck('minutes', 'project_key')
            ->map(fn (mixed $minutes): int => (int) $minutes)
            ->all();
    }

    /**
     * @return array<int, int>
     */
    private function entriesByProject(User $viewer, ReportFilters $filters): array
    {
        return $this->timeEntries($viewer, $filters)
            ->stopped()
            ->selectRaw('coalesce(time_entries.project_id, 0) as project_key')
            ->selectRaw('count(*)::int as entries')
            ->groupBy('project_key')
            ->pluck('entries', 'project_key')
            ->map(fn (mixed $entries): int => (int) $entries)
            ->all();
    }

    /**
     * The scoped, windowed base every Time query starts from.
     *
     * A project id the viewer cannot open narrows to nothing rather than refusing, because the
     * `whereIn` is built from `Project::visibleTo()`.
     *
     * @return Builder<TimeEntry>
     */
    private function timeEntries(User $viewer, ReportFilters $filters): Builder
    {
        return TimeEntry::query()
            ->visibleTo($viewer)
            // Hours reports are about people's hours: an office/Admin task-timer breakdown row
            // is not one, in any column — minutes, pending or the entry count (decision 12-73).
            ->countsTowardHours()
            ->whereBetween('time_entries.work_date', $filters->dateStrings())
            ->when($filters->employeeId, fn (Builder $q, int $id) => $q->where('time_entries.employee_id', $id))
            ->when($filters->projectId, fn (Builder $q, int $id) => $q->whereIn(
                'time_entries.project_id',
                Project::query()->visibleTo($viewer)->where('projects.id', $id)->select('projects.id'),
            ));
    }

    /**
     * The payroll aggregate, grouped by period or over the whole window.
     *
     * One query either way, and the money is cast to text by PostgreSQL inside it — the report
     * never sees a float and never adds two amounts together.
     *
     * @return list<array<string, string|int>>
     */
    private function payrollAggregate(User $viewer, ReportFilters $filters, bool $grouped): array
    {
        $query = PayrollItem::query()
            ->visibleTo($viewer)
            ->join('payroll_periods', 'payroll_periods.id', '=', 'payroll_items.payroll_period_id')
            ->whereBetween('payroll_periods.month', [
                $filters->from->copy()->startOfMonth()->toDateString(),
                $filters->to->copy()->endOfMonth()->toDateString(),
            ])
            ->selectRaw('count(*)::int as employees')
            ->selectRaw('sum(payroll_items.base_salary + payroll_items.allowance + payroll_items.bonus)::numeric(12,2)::text as gross')
            ->selectRaw('sum(payroll_items.deduction + payroll_items.advance + payroll_items.leave_impact)::numeric(12,2)::text as deductions')
            ->selectRaw('sum(payroll_items.net_salary)::numeric(12,2)::text as net');

        if ($grouped) {
            $query
                ->addSelect('payroll_periods.month', 'payroll_periods.status')
                ->groupBy('payroll_periods.month', 'payroll_periods.status')
                ->orderBy('payroll_periods.month');
        }

        return $query->get()->map(function (PayrollItem $row) use ($grouped): array {
            $line = [
                'employees' => (int) $row->getAttribute('employees'),
                'gross' => (string) ($row->getAttribute('gross') ?? '0.00'),
                'deductions' => (string) ($row->getAttribute('deductions') ?? '0.00'),
                'net' => (string) ($row->getAttribute('net') ?? '0.00'),
            ];

            if (! $grouped) {
                return $line;
            }

            $status = $row->getAttribute('status');

            return [
                'month' => Carbon::parse((string) $row->getAttribute('month'))->format('F Y'),
                'status' => is_string($status)
                    ? (PayrollStatus::tryFrom($status)?->label() ?? $status)
                    : '—',
                ...$line,
            ];
        })->all();
    }

    /**
     * A task's assignees as one cell, in the order the relation returns them.
     *
     * Names, not a count: the reader of an overdue list needs to know who to talk to, and the
     * task is already one they may see.
     */
    private function assigneeNames(Task $task): string
    {
        $names = $task->assignees
            ->map(fn (Employee $employee): string => (string) ($employee->user?->name ?? 'Unknown'))
            ->filter()
            ->values();

        return $names->isEmpty() ? 'Unassigned' : $names->implode(', ');
    }

    /**
     * `label => count`, in descending count order, as a chart series.
     *
     * @param  list<string>  $labels
     * @return list<array{label: string, value: int}>
     */
    private function countBy(array $labels): array
    {
        $counts = [];

        foreach ($labels as $label) {
            $counts[$label] = ($counts[$label] ?? 0) + 1;
        }

        arsort($counts);

        return array_values(array_map(
            static fn (string $label): array => ['label' => $label, 'value' => $counts[$label]],
            array_keys($counts),
        ));
    }

    /**
     * Fold rows that share a label, adding the named integer columns.
     *
     * @param  list<array<string, string|int>>  $rows
     * @param  list<string>  $sum
     * @return list<array<string, string|int>>
     */
    private function mergeRowsByLabel(array $rows, string $labelKey, array $sum): array
    {
        $merged = [];

        foreach ($rows as $row) {
            $label = (string) $row[$labelKey];

            if (! isset($merged[$label])) {
                $merged[$label] = $row;

                continue;
            }

            foreach ($sum as $key) {
                $merged[$label][$key] = (int) $merged[$label][$key] + (int) $row[$key];
            }
        }

        return array_values($merged);
    }

    /**
     * `name => cents` as a money series, biggest first.
     *
     * @param  array<string, int>  $cents
     * @return list<array{label: string, value: string}>
     */
    private static function moneySeries(array $cents): array
    {
        arsort($cents);

        return array_values(array_map(
            static fn (string $name): array => ['label' => $name, 'value' => self::fromCents($cents[$name])],
            array_keys($cents),
        ));
    }

    /**
     * Every task status's tone mapped to the word this application prints for it.
     *
     * Handed to `ReportColumn::status()` so that the **table** says what the enum says. Without
     * it the badge falls back to its own default, and the Task report then prints *"Waiting"*
     * in a cell directly under a donut legend reading *"Waiting / Blocked"* — two words for one
     * status, a few centimetres apart, which is how this was caught.
     *
     * Built once per column rather than carried on every row: the word is a property of the
     * status, and a row that repeated it would be a second place for it to drift.
     *
     * @return array<string, string>
     */
    private static function taskStatusLabels(): array
    {
        $labels = [];

        foreach (TaskStatus::cases() as $status) {
            $labels[$status->tone()] = $status->label();
        }

        return $labels;
    }

    /**
     * The label the application prints for the status a tone belongs to.
     *
     * `ReportFormat::Status` carries a tone and `StatusBadge` has its own default label for
     * each of the eight — which is not always the word `TaskStatus::label()` uses. The chart
     * legend therefore asks the enum rather than the badge, and {@see taskStatusLabels()}
     * makes the table ask it too, so the donut and the cells under it cannot disagree.
     */
    private static function statusLabelForTone(string $tone): string
    {
        foreach (TaskStatus::cases() as $status) {
            if ($status->tone() === $tone) {
                return $status->label();
            }
        }

        return $tone;
    }

    /**
     * A decimal string to whole cents. `round()` before the cast because `(int) (8.6 * 100)` is
     * 859 on a binary float — which is the entire reason money is never a float here. The same
     * two lines `FinanceService` uses, because this is the one place that adds its months up.
     */
    private static function toCents(string $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    private static function fromCents(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    /*
    |--------------------------------------------------------------------------
    | The shared plumbing — slice B
    |--------------------------------------------------------------------------
    */

    /**
     * The window's tasks, through `TaskService` and nothing else.
     *
     * Fetched **once per bucket for the whole report** and grouped in PHP, rather than counted
     * per project. It is the same predicate `TaskService::count()` runs — the same scope, the
     * same overlap window, the same `TaskBucket` — so a grouped count here and the list a row's
     * link opens cannot come from two definitions. The first slice paid a query per project per
     * bucket; the five project reports below would have made that twenty, and the saving costs
     * nothing but a `group by` written in PHP.
     *
     * @return EloquentCollection<int, Task>
     */
    private function windowTasks(User $viewer, ReportFilters $filters, ?TaskBucket $bucket = null): EloquentCollection
    {
        $scoped = $this->taskFilters($filters);

        if ($bucket !== null) {
            $scoped['bucket'] = $bucket->value;
        }

        return $this->tasks->query($viewer, $scoped)->limit(self::MAX_ROWS)->get();
    }

    /**
     * The projects a report's rows are about: the viewer's own scope, name order.
     *
     * The client filter lives here rather than in the task query, for the reason
     * `countTasks()` gives — a client is a set of projects, and a task belongs to exactly one —
     * so narrowing the project list narrows every figure on the row with it. A project or client
     * id the viewer may not see matches nothing and the report is empty, never an error (10-26).
     *
     * @param  list<ProjectType>  $types  the project types this report is about, or all of them
     * @return EloquentCollection<int, Project>
     */
    private function projectsFor(
        User $viewer,
        ReportFilters $filters,
        array $types = [],
        bool $withClient = false,
    ): EloquentCollection {
        return Project::query()
            ->visibleTo($viewer)
            ->notArchived()
            ->when($types !== [], fn (Builder $q) => $q->whereIn(
                'projects.project_type',
                array_map(static fn (ProjectType $type): string => $type->value, $types),
            ))
            ->when($filters->projectId, fn (Builder $q, int $id) => $q->where('projects.id', $id))
            ->when($filters->clientId, fn (Builder $q, int $id) => $q->where('projects.client_id', $id))
            ->with($withClient ? ['client'] : [])
            ->orderBy('projects.name')
            ->limit(self::MAX_ROWS)
            ->get();
    }

    /**
     * @param  EloquentCollection<int, Task>  $tasks
     * @return array<int, list<Task>>
     */
    private function tasksByProject(EloquentCollection $tasks): array
    {
        $grouped = [];

        foreach ($tasks as $task) {
            $grouped[(int) $task->project_id][] = $task;
        }

        return $grouped;
    }

    /**
     * The same tasks, grouped by project **and retainer period**.
     *
     * @param  EloquentCollection<int, Task>  $tasks
     * @return array<string, list<Task>>
     */
    private function tasksByPeriod(EloquentCollection $tasks): array
    {
        $grouped = [];

        foreach ($tasks as $task) {
            $grouped[self::cellKey((int) $task->project_id, (string) ($task->recurring_period ?? ''))][] = $task;
        }

        return $grouped;
    }

    /**
     * The periods one project has work in, oldest first, with **Ad hoc last**.
     *
     * Period keys sort lexicographically into chronological order by construction
     * (`RecurrenceRule`'s docblock says so, and the engine's previous-instance lookup depends on
     * it), so `sort()` is the chronology. The empty key is not a period at all, which is why it
     * is moved to the end rather than sorted to the front.
     *
     * @param  array<string, list<Task>>  $grouped
     * @return list<string>
     */
    private static function periodsOf(array $grouped, int $projectId): array
    {
        $periods = [];

        foreach (array_keys($grouped) as $cell) {
            [$id, $period] = explode('|', $cell, 2);

            if ((int) $id === $projectId) {
                $periods[] = $period;
            }
        }

        sort($periods);

        $adHoc = array_values(array_filter($periods, static fn (string $period): bool => $period === ''));

        return [
            ...array_values(array_filter($periods, static fn (string $period): bool => $period !== '')),
            ...$adHoc,
        ];
    }

    private static function cellKey(int $projectId, string $period): string
    {
        return $projectId.'|'.$period;
    }

    /**
     * A retainer period as a person reads it — `RecurrenceRule`'s own word for it, so the report
     * and the task detail screen call one period one thing.
     */
    private static function periodLabel(string $period): string
    {
        return $period === ''
            ? 'Ad hoc'
            : (RecurrenceRule::labelForPeriod($period) ?? $period);
    }

    /**
     * Approved minutes per project **and retainer period**, from `time_entries`.
     *
     * `scopeCounted()` is the approval predicate and `timeEntries()` is the scoped, windowed
     * base — neither is re-typed. The join to `tasks` is what carries the period, so time
     * tracked against no task at all is not in these figures; nothing in the application can
     * produce such an entry today, and counting it would mean inventing a period for it.
     *
     * @return array<string, int>
     */
    private function trackedMinutesByPeriod(User $viewer, ReportFilters $filters): array
    {
        $rows = $this->timeEntries($viewer, $filters)
            ->counted()
            ->join('tasks', 'tasks.id', '=', 'time_entries.task_id')
            ->groupBy('tasks.project_id', 'tasks.recurring_period')
            ->selectRaw('tasks.project_id as project_key')
            ->selectRaw("coalesce(tasks.recurring_period, '') as period_key")
            ->selectRaw('floor(sum(time_entries.duration_seconds) / 60)::int as minutes')
            ->get();

        $minutes = [];

        foreach ($rows as $row) {
            $key = self::cellKey(
                (int) $row->getAttribute('project_key'),
                (string) $row->getAttribute('period_key'),
            );

            $minutes[$key] = (int) $row->getAttribute('minutes');
        }

        return $minutes;
    }

    /**
     * How many messages each of these conversations carried inside the window.
     *
     * The ids are the caller's, and the caller built them out of the scopes and the policy — so
     * this method asks no access question and could not widen one if it tried.
     *
     * @param  list<int>  $conversationIds
     * @return array<int, int>
     */
    private function messagesByConversation(ReportFilters $filters, array $conversationIds): array
    {
        if ($conversationIds === []) {
            return [];
        }

        return Message::query()
            ->whereIn('messages.conversation_id', $conversationIds)
            ->whereBetween('messages.created_at', self::messageWindow($filters))
            ->groupBy('messages.conversation_id')
            ->selectRaw('messages.conversation_id as conversation_key')
            ->selectRaw('count(*)::int as messages')
            ->pluck('messages', 'conversation_key')
            ->map(fn (mixed $messages): int => (int) $messages)
            ->all();
    }

    /**
     * The same messages, per ISO week, as a chart series.
     *
     * Bucketed in the **agency's own timezone** rather than the database's, because a message
     * posted at nine on a Sunday morning in Dhaka belongs to the week the team worked, not to
     * the one UTC was still in. The week key is the same shape `RecurrenceRule` mints for a
     * weekly retainer (`2026-W41`), so `labelForPeriod()` prints it and the vocabulary is the
     * application's rather than this report's.
     *
     * @param  list<int>  $conversationIds
     * @return list<array{label: string, value: int}>
     */
    private function messagesByWeek(ReportFilters $filters, array $conversationIds): array
    {
        if ($conversationIds === []) {
            return [];
        }

        $counts = Message::query()
            ->whereIn('messages.conversation_id', $conversationIds)
            ->whereBetween('messages.created_at', self::messageWindow($filters))
            ->selectRaw('to_char(messages.created_at at time zone ?, \'IYYY-"W"IW\') as period_key', [
                (string) config('app.timezone'),
            ])
            ->selectRaw('count(*)::int as messages')
            ->groupBy('period_key')
            ->orderBy('period_key')
            ->pluck('messages', 'period_key')
            ->all();

        return array_values(array_map(
            static fn (string $period): array => [
                'label' => RecurrenceRule::labelForPeriod($period) ?? $period,
                'value' => (int) $counts[$period],
            ],
            array_keys($counts),
        ));
    }

    /**
     * The window as two timestamps. A message carries a time and the filter carries two dates,
     * so the last day has to be its whole self — a `whereBetween` on the bare dates would drop
     * everything said after midnight on the final day.
     *
     * @return array{Carbon, Carbon}
     */
    private static function messageWindow(ReportFilters $filters): array
    {
        return [$filters->from->copy()->startOfDay(), $filters->to->copy()->endOfDay()];
    }

    /**
     * Where a count of tasks goes when it is clicked.
     *
     * The keys are the ones `TaskService::filters()` reads, so the list opens under **exactly**
     * the filters the figure was counted under — the window, the project, the bucket, and the
     * employee if the report was narrowed to one. A link built anywhere else would be a second
     * spelling of the same question.
     *
     * @param  array{project_id?: int, bucket?: TaskBucket}  $extra
     */
    private function taskHref(ReportFilters $filters, array $extra = []): string
    {
        $query = array_filter([
            'project_id' => $extra['project_id'] ?? $filters->projectId,
            'assignee_id' => $filters->employeeId,
            'bucket' => ($extra['bucket'] ?? null)?->value,
            'date_from' => $filters->from->toDateString(),
            'date_to' => $filters->to->toDateString(),
        ], static fn (mixed $value): bool => $value !== null);

        return '/admin/tasks?'.http_build_query($query);
    }

    /**
     * Whole days from each completed task's start to its completion, for the tasks that carry
     * both. A task with no start date is not measurable and is **not** a zero.
     *
     * @param  list<Task>  $tasks
     * @return list<int>
     */
    private function daysToComplete(array $tasks): array
    {
        $days = [];

        foreach ($tasks as $task) {
            if ($task->start_date === null || $task->completed_at === null) {
                continue;
            }

            // Clamped at nought: a task completed before its planned start took no negative
            // time, and a negative day in an average is a figure nobody can read.
            $days[] = max(0, (int) floor($task->start_date->diffInDays($task->completed_at)));
        }

        return $days;
    }

    /**
     * The mean, in whole days — and **nought when there is nothing to average**, which is the
     * empty case rather than a division.
     *
     * @param  list<int>  $days
     */
    private static function wholeAverage(array $days): int
    {
        return $days === [] ? 0 : (int) round(array_sum($days) / count($days));
    }
}
