<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\DailyWorkSummary;
use App\Models\Employee;
use App\Models\Task;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\ProjectService;
use App\Services\TaskReviewers;
use App\Services\TaskService;
use App\Support\AttendanceStatus;
use App\Support\TaskBucket;
use App\Support\TaskStatus;
use App\Support\TrackingMode;
use App\Support\UserStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * The four task questions the plan puts on the Company dashboard, each with the label it
     * uses there and the list it opens.
     *
     * These are the AGENCY's numbers, not this Admin's: no `mine` filter, so the scope is
     * whatever `Task::visibleTo()` gives them, which for an Admin is every task. Their own
     * plate is /admin/my-tasks, and that it is a different screen is the point — an Admin
     * opens this one to find out what is late across the agency.
     *
     * Each link carries the same `?bucket=` the count was made with, so "Overdue: 4" opens
     * exactly those four on the Tasks List. The fifth card, Active projects, is a question
     * about projects and is ProjectService's to answer.
     *
     * @var list<array{bucket: TaskBucket, label: string}>
     */
    private const TASK_CARDS = [
        ['bucket' => TaskBucket::DueToday, 'label' => 'Tasks due today'],
        ['bucket' => TaskBucket::Overdue, 'label' => 'Overdue'],
        ['bucket' => TaskBucket::InReview, 'label' => 'Awaiting review'],
        ['bucket' => TaskBucket::CompletedToday, 'label' => 'Completed today'],
    ];

    /**
     * How many rows the attention panel shows per source.
     *
     * It is a shortlist, not a list: the panel answers "what should I open first", and the
     * cards directly above it already lead to the whole of each bucket. Five and five is about
     * a screenful on a phone.
     */
    private const ATTENTION_PER_SOURCE = 5;

    /**
     * How far down the review queue the reviewer filter is allowed to look.
     *
     * "Awaiting review" is one query (TaskBucket::InReview over Task::visibleTo()); "and I am
     * its reviewer" is TaskReviewers' rule, which is about a task's project and is asked per
     * task rather than restated as SQL here — decision 2-37's reasoning applied to a rule that
     * is not a bucket. That means taking a bounded window and filtering it, so the window is
     * named: in an agency of fifteen the whole In-review bucket is the number on the card two
     * rows up, and this is an order of magnitude above it.
     */
    private const REVIEW_SCAN = 50;

    public function __construct(
        private readonly TaskService $tasks,
        private readonly ProjectService $projects,
        private readonly TaskReviewers $reviewers,
        private readonly AttendanceService $attendance,
    ) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $asOf = Carbon::today();
        $maySeeTasks = Gate::forUser($user)->allows('viewAny', Task::class);

        return Inertia::render('Admin/Dashboard', [
            'greetingName' => Str::before(trim($user->name), ' '),
            'today' => now(config('app.timezone'))->toDateString(),
            'stats' => [
                'activeEmployees' => Employee::where('status', UserStatus::Active)->count(),
            ],
            'attendance' => $this->attendanceToday($user, $asOf),
            'workStats' => $this->workStats($user, $asOf, $maySeeTasks),
            'attention' => $maySeeTasks ? $this->attention($user, $asOf) : [],
            'taskStatuses' => $maySeeTasks ? $this->taskStatuses($user, $asOf) : [],
        ]);
    }

    /**
     * The Company dashboard's attendance block (Phase 4, Part D §8 and AC2).
     *
     * Three real numbers and one honest blank:
     *
     *   - **Present today** and **Absent** — counts of people, from the same roster the
     *     Attendance screen draws, so the card and the screen it opens cannot disagree. Present
     *     folds in Late, because somebody who arrived at ten past nine is in the office: the
     *     roster breaks the two apart and this card links to it. The predicates are
     *     `AttendanceStatus`', asked once in `AttendanceService` and never restated here
     *     (decision 2-37).
     *   - **Remote time today**, one line per remote-timer employee — AC2's *"Tapu 4h 18m / 5h"*.
     *     It is read from the `daily_work_summary` VIEW, which is what Part C rule 6 built it
     *     for: reporting. The target beside it is the employee's own
     *     `schedules.working_hours_per_day` and nothing is divided by it — no percentage, no bar
     *     presented as a grade, no comparison between the people on the list (Part H §1).
     *   - **On leave** stays a placeholder naming Phase 5. `leave_requests` does not exist, so a
     *     card reading "0" would be a measurement nobody has taken — and would read as *nobody
     *     is on leave*, which is a different and possibly false statement.
     *
     * Empty for somebody who may not manage other people's attendance: the whole block is
     * absent from the payload rather than sent with zeroes (Part C §1 — a field the requester
     * may not see is absent, not null).
     *
     * @return array<string, mixed>
     */
    private function attendanceToday(User $user, Carbon $asOf): array
    {
        if (! Gate::forUser($user)->allows('viewAny', AttendanceRecord::class)) {
            return [];
        }

        $roster = $this->attendance->roster($user, $asOf);

        $counts = $roster->countBy(fn (array $row): string => (string) ($row['status'] ?? 'none'));
        $present = (int) $counts->get(AttendanceStatus::Present->value, 0)
            + (int) $counts->get(AttendanceStatus::Late->value, 0);

        return [
            'present' => $present,
            'absent' => (int) $counts->get(AttendanceStatus::Absent->value, 0),
            'href' => '/admin/attendance',
            'remote' => $this->remoteTimeToday($user, $asOf),
        ];
    }

    /**
     * "Tapu 4h 18m / 5h" — one row per remote-timer employee, for today.
     *
     * The minutes come from `daily_work_summary`, the reporting view, in one query for everybody
     * on the list. The view's `tracked_minutes` asks `approved_at is not null` — decision 4-7's
     * single predicate, the same one `AttendanceService::trackedMinutes()` asks for the roster —
     * so the card and the roster row give the same figure, which is what Part C rule 6 exists to
     * guarantee. `tests/Feature/Admin/DailyWorkSummaryTest.php` asserts that they agree.
     *
     * An employee with nothing tracked yet is **on the list at 0m**. Zero is an answer at nine
     * in the morning; a name that appears only once somebody starts working is a list that
     * cannot be read twice.
     *
     * @return list<array{id: int, name: string, tracked_minutes: int, target_minutes: int|null, pending_minutes: int}>
     */
    private function remoteTimeToday(User $user, Carbon $asOf): array
    {
        $employees = Employee::query()
            ->attendanceVisibleTo($user)
            ->where('tracking_mode', TrackingMode::RemoteTimer)
            ->with(['user:id,name', 'schedule'])
            ->join('users', 'users.id', '=', 'employees.user_id')
            ->orderBy('users.name')
            ->select('employees.*')
            ->get();

        if ($employees->isEmpty()) {
            return [];
        }

        $summary = DailyWorkSummary::query()
            ->forEmployees($employees->modelKeys())
            ->forDate($asOf)
            ->get()
            ->keyBy('employee_id');

        return $employees->map(function (Employee $employee) use ($summary): array {
            $row = $summary->get($employee->getKey());
            $hours = $employee->schedule?->working_hours_per_day;

            return [
                'id' => (int) $employee->getKey(),
                'name' => $employee->user?->name ?? 'Unknown',
                'tracked_minutes' => (int) ($row?->tracked_minutes ?? 0),
                // Null when they have no schedule: the card then shows a duration and no target,
                // rather than counting against a number nobody set.
                'target_minutes' => $hours === null ? null : (int) round(((float) $hours) * 60),
                // Reported beside the total and never inside it. An afternoon waiting for a
                // sign-off is not counted, but it must not be invisible either — the Admin
                // reading this card is the person who can sign it off.
                'pending_minutes' => (int) ($row?->pending_minutes ?? 0),
            ];
        })->values()->all();
    }

    /**
     * The five task cards, counted in the database and each one clickable.
     *
     * Not one of them is computed in Vue and not one of them comes from a list: a paginated
     * list would under-count, and a number nobody can act on is a number that should not be
     * on a dashboard.
     *
     * @return list<array{key: string, label: string, count: int, href: string}>
     */
    private function workStats(User $user, Carbon $asOf, bool $maySeeTasks): array
    {
        if (! $maySeeTasks) {
            return [];
        }

        $counts = $this->tasks->bucketCounts(
            $user,
            array_map(fn (array $card): TaskBucket => $card['bucket'], self::TASK_CARDS),
            ['as_of' => $asOf],
        );

        $cards = array_map(fn (array $card): array => [
            'key' => $card['bucket']->value,
            'label' => $card['label'],
            'count' => $counts[$card['bucket']->value],
            'href' => '/admin/tasks?bucket='.$card['bucket']->value,
        ], self::TASK_CARDS);

        // The fifth. `/admin/projects?status=active` applies the same two predicates
        // activeCount() does, so the card and the list it opens hold the same projects.
        $cards[] = [
            'key' => 'active_projects',
            'label' => 'Active projects',
            'count' => $this->projects->activeCount($user),
            'href' => '/admin/projects?status=active',
        ];

        return $cards;
    }

    /**
     * "Needs your attention": the task half of it, which is the half that is real (decision
     * 2-50).
     *
     * Two sources, in the order somebody should deal with them:
     *
     *   1. **Waiting on YOUR verdict.** Nobody else can move these — the assignee has finished
     *      and is blocked until a reviewer rules. It is the only genuinely personal queue on
     *      this screen, which is why it leads a panel titled "your attention" on a dashboard
     *      whose five cards are otherwise the agency's.
     *   2. **Overdue.** Late across the agency, soonest-due first, because the oldest miss is
     *      the one that has been ignored longest.
     *
     * Approvals (Phases 8–9) and leave decisions (Phase 5) are the panel's other two sources
     * and are not built. The screen still names them — see Dashboard.vue's `note` — rather than
     * quietly shipping a panel that looks complete and is two thirds of one.
     *
     * Every row carries the task's id, so the screen links to the task itself. A thing that
     * needs you and cannot be opened is worse than no panel.
     *
     * @return list<array{id: string, kind: string, title: string, meta: string, href: string, tone: string}>
     */
    private function attention(User $user, Carbon $asOf): array
    {
        $items = [];

        foreach ($this->awaitingMyReview($user, $asOf) as $task) {
            $items[] = [
                'id' => 'review-'.$task->getKey(),
                'kind' => 'review',
                'title' => (string) $task->title,
                'meta' => $this->meta('Waiting on your review', $task),
                'href' => '/admin/tasks/'.$task->getKey(),
                'tone' => 'default',
            ];
        }

        foreach ($this->overdue($user, $asOf) as $task) {
            $items[] = [
                'id' => 'overdue-'.$task->getKey(),
                'kind' => 'overdue',
                // The state is in the WORDS, not only in the red medallion. An overdue row
                // that is merely tinted says nothing in greyscale and nothing to a screen
                // reader — DESIGN.md §6 rule 6, and a bug this repo has fixed twice.
                'title' => (string) $task->title,
                'meta' => $this->meta($this->lateness($task, $asOf), $task),
                'href' => '/admin/tasks/'.$task->getKey(),
                'tone' => 'urgent',
            ];
        }

        return $items;
    }

    /**
     * The In-review tasks this person may actually rule on.
     *
     * Scoped by `Task::visibleTo()` and narrowed by `TaskBucket::InReview`, exactly like the
     * "Awaiting review" card above it — so a row here is always one of the tasks that card
     * counted. The extra half, "and I am its reviewer", is TaskReviewers' single statement of
     * the rule asked per task; see REVIEW_SCAN for why that is a bounded scan and not SQL.
     *
     * @return EloquentCollection<int, Task>
     */
    private function awaitingMyReview(User $user, Carbon $asOf): EloquentCollection
    {
        $tasks = $this->scoped($user, TaskBucket::InReview, $asOf)
            ->limit(self::REVIEW_SCAN)
            ->get()
            ->filter(fn (Task $task): bool => $this->reviewers->isReviewer($user, $task))
            ->take(self::ATTENTION_PER_SOURCE)
            ->values();

        /** @var EloquentCollection<int, Task> $tasks */
        return $tasks;
    }

    /**
     * @return EloquentCollection<int, Task>
     */
    private function overdue(User $user, Carbon $asOf): EloquentCollection
    {
        return $this->scoped($user, TaskBucket::Overdue, $asOf)
            ->limit(self::ATTENTION_PER_SOURCE)
            ->get();
    }

    /**
     * One bucket of the tasks this person may see, soonest-due first.
     *
     * `visibleTo()` is the scope and `TaskBucket` is the predicate — the two rules decision
     * 2-37 says are stated once. `notArchived()` is the same default every list applies; an
     * archived task is not work anybody has to do today.
     *
     * The ordering is the List view's leading column, so "the first thing in the panel" and
     * "the first row of the list the card opens" are the same task.
     *
     * @return Builder<Task>
     */
    private function scoped(User $user, TaskBucket $bucket, Carbon $asOf): Builder
    {
        return $bucket->apply(
            Task::query()->visibleTo($user)->notArchived()->with('project'),
            $asOf,
        )->orderByRaw('due_date asc nulls last')->orderBy('id');
    }

    /**
     * A row's one line of context: why it is here, and which project it is in.
     */
    private function meta(string $why, Task $task): string
    {
        $project = $task->project?->name;

        return $project === null ? $why : $why.' · '.$project;
    }

    /**
     * How late, in words. "6 days late" is the second encoding of the red medallion, and it is
     * also the only part a screen reader gets.
     */
    private function lateness(Task $task, Carbon $asOf): string
    {
        $due = $task->due_date;

        if ($due === null) {
            return 'Overdue';
        }

        $days = (int) $due->copy()->startOfDay()->diffInDays($asOf->copy()->startOfDay());

        return $days === 1 ? '1 day late' : $days.' days late';
    }

    /**
     * "Tasks by status": the open work, split by the column that says what state it is in.
     *
     * A straight `group by status` over the same scoped, non-archived set every other number on
     * this page is counted from — narrowed to the OPEN statuses because the ring's centre reads
     * "Open tasks" and a breakdown whose slices do not sum to the number in the middle is a
     * chart nobody can reconcile. `TaskBucket::Open` is that predicate and the only statement
     * of it (decision 2-37); the split itself is the `status` column, not a bucket, which is
     * why this is one query and not six.
     *
     * Every open status ships, including the ones sitting at zero: zero is an answer, and a
     * breakdown whose slices appear and disappear as the week goes on is one whose legend
     * cannot be read twice. The tone is `TaskStatus::tone()`, so the ring agrees with the
     * status badges everywhere else rather than re-deriving the mapping in Vue.
     *
     * @return list<array{key: string, label: string, tone: string, count: int}>
     */
    private function taskStatuses(User $user, Carbon $asOf): array
    {
        $counts = TaskBucket::Open
            ->apply(Task::query()->visibleTo($user)->notArchived(), $asOf)
            ->groupBy('status')
            ->selectRaw('status, count(*) as total')
            ->pluck('total', 'status')
            ->all();

        return array_values(array_map(fn (TaskStatus $status): array => [
            'key' => $status->value,
            'label' => $status->label(),
            'tone' => $status->tone(),
            'count' => (int) ($counts[$status->value] ?? 0),
        ], array_filter(
            TaskStatus::boardOrder(),
            fn (TaskStatus $status): bool => $status->isOpen(),
        )));
    }
}
