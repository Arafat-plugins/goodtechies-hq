<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\HolidayResource;
use App\Http\Resources\MeetingResource;
use App\Models\AttendanceRecord;
use App\Models\DailyWorkSummary;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Income;
use App\Models\Meeting;
use App\Models\PayrollItem;
use App\Models\PayrollPeriod;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\FinanceService;
use App\Services\HolidayService;
use App\Services\LeaveService;
use App\Services\ProjectService;
use App\Services\SettingsService;
use App\Services\TaskReviewers;
use App\Services\TaskService;
use App\Services\TaskTimerService;
use App\Services\WorkloadService;
use App\Support\AttendanceStatus;
use App\Support\ProjectStatus;
use App\Support\ProjectType;
use App\Support\TaskBucket;
use App\Support\TaskStatus;
use App\Support\TrackingMode;
use App\Support\UserStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
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
     * How many rows the "Upcoming meetings" card shows.
     *
     * The same five, for the same reason: it answers *"what is on today and tomorrow"*, and the
     * Meetings screen it links to is where the rest of the diary is. `Employee\
     * DashboardController` carries the same number.
     */
    private const UPCOMING_MEETINGS = 5;

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

    /**
     * How many rows "Upcoming deadlines" shows, and how far ahead it looks.
     *
     * Five, like every other shortlist on this screen. Thirty days is the window because a
     * project deadline is a planning horizon rather than a to-do: at a week the card is empty
     * most of the time and at a quarter it is a list of things nobody can act on yet. Both
     * numbers are named here rather than inlined, so a change to either is one edit and shows
     * up in the docblock the reader is already looking at.
     */
    private const UPCOMING_DEADLINES = 5;

    private const DEADLINE_WINDOW_DAYS = 30;

    public function __construct(
        private readonly TaskService $tasks,
        private readonly ProjectService $projects,
        private readonly TaskReviewers $reviewers,
        private readonly AttendanceService $attendance,
        private readonly HolidayService $holidays,
        private readonly LeaveService $leave,
        private readonly FinanceService $finance,
        private readonly SettingsService $settings,
        private readonly WorkloadService $workload,
        private readonly TaskTimerService $timers,
    ) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $asOf = Carbon::today();
        $maySeeTasks = Gate::forUser($user)->allows('viewAny', Task::class);
        $mayWatch = Gate::forUser($user)->allows('watchLive', TimeEntry::class);

        // Closures, so a live partial reload runs only the reads it names: the sixty-second poll
        // asks for six, and the Working now panel's ring on `task.changed` kind `timer` for one.
        // A full visit resolves every one, exactly as before.
        return Inertia::render('Admin/Dashboard', [
            'greetingName' => Str::before(trim($user->name), ' '),
            'today' => now(config('app.timezone'))->toDateString(),
            'stats' => [
                'activeEmployees' => Employee::where('status', UserStatus::Active)->count(),
            ],
            'attendance' => fn () => $this->attendanceToday($user, $asOf),
            'workStats' => fn () => $this->workStats($user, $asOf, $maySeeTasks),
            'attention' => fn () => $maySeeTasks ? $this->attention($user, $asOf) : [],
            'taskStatuses' => fn () => $maySeeTasks ? $this->taskStatuses($user, $asOf) : [],
            // Row 2's other three. `taskStatuses` above is the donut, these two are the bars,
            // and `upcomingDeadlines` is the LIST — Part D §3 names four things and budgets
            // three charts, so the fourth is not a chart. See each method.
            'tasksByEmployee' => fn () => $maySeeTasks ? $this->tasksByEmployee($user, $asOf) : [],
            'projectsByType' => fn () => $this->projectsByType($user),
            'upcomingDeadlines' => fn () => $this->upcomingDeadlines($user, $asOf),
            'upcomingHolidays' => fn () => $this->upcomingHolidays($request, $asOf),
            'upcomingMeetings' => fn () => $this->upcomingMeetings($request, $user),
            'finance' => fn () => $this->financeThisMonth($user, $asOf),
            // "Working now" (flow F3): who is timing which task, for how long — for a watcher
            // (`TimeEntryPolicy::watchLive`) and ABSENT for anybody else, never an empty list
            // that would read as "nobody is working".
            ...($mayWatch ? ['workingNow' => fn (): array => $this->timers->workingNow($user) ?? []] : []),
        ]);
    }

    /**
     * Row 3 — *"This month's income, expense, payroll, operating result"* (Part D §3, ADMIN
     * only; Phase 8).
     *
     * **The two real figures come from `FinanceService::monthlyRollup()`**, the same call the
     * Finance dashboard makes, so this card and `/finance` cannot disagree about September. No
     * total on this page is summed here and none is summed in Vue; `operating_result` is the
     * rollup's own `net`, computed in **integer cents** rather than by subtracting two decimal
     * strings cast to float.
     *
     * **`payroll` is real from Phase 9** — this month's `payroll_periods` row, its status and
     * the sum of its items' `net_salary`. It stays `null` when there is no period for the month
     * yet, which is the ordinary state of the 1st before `hq:create-payroll-draft` has run:
     * the ABSENCE is the thing the card has to render, because a zero would read as *"we paid
     * nobody this month"* — the one wrong answer. `payroll_note` carries the month, the status
     * and the line count in words, so what the em-dash means is never left to the screen.
     *
     * **`operating_result` is income − expense and nothing else**, which is worth saying out
     * loud because a figure called an operating result that quietly omitted the largest cost
     * would be worse than no figure. Expenses filed under the *Payroll* category ARE in it —
     * they are expenses, entered by the Accountant, and the seeded September has $1,400 of
     * them. What is **not** in it is the payroll RUN: `payroll_items` is a different table and
     * `FinanceService::monthlyRollup()` never reads it, which was true before Phase 9 and is
     * still true now that the run exists. The sentence on the card said *"the Phase 9 payroll
     * run is not in it"*; the fact has not changed, only the tense, so `Dashboard.vue` now says
     * *"the payroll run is not in it"* — a card that still pointed at a phase number would read
     * as unbuilt work rather than as the accounting statement it is.
     *
     * Empty for anybody without `finance.view` — absent from the payload rather than sent with
     * zeroes (Part C §1: a field the requester may not see is absent, not null). Today that is
     * nobody who can reach this route, since an Admin holds both finance keys; the gate is
     * asked anyway, because that is where the answer lives if it ever stops being true.
     *
     * @return array<string, mixed>
     */
    private function financeThisMonth(User $user, Carbon $asOf): array
    {
        if (! Gate::forUser($user)->allows('viewAny', Income::class)) {
            return [];
        }

        $rollup = $this->finance->monthlyRollup($user, (int) $asOf->year, (int) $asOf->month);
        $payroll = $this->payrollThisMonth($user, $asOf);

        return [
            'month' => $rollup['month'],
            'label' => $rollup['label'],
            'income' => $rollup['income']['total'],
            'expense' => $rollup['expenses']['total'],
            // Phase 9. See the docblock: a real total, or null when the month has no period
            // yet — never a zero, which would read as "we paid nobody".
            'payroll' => $payroll['total'],
            'payroll_note' => $payroll['note'],
            'payroll_href' => $payroll['href'],
            'operating_result' => $rollup['net'],
            'currency' => (string) $this->settings->get('currency'),
            'href' => '/finance?month='.$rollup['month'],
        ];
    }

    /**
     * Row 3's payroll card, made real (Part E, Phase 9: *"Admin dashboard Row 3 payroll card
     * real"*).
     *
     * Three facts and no arithmetic of this controller's own:
     *
     *   - **the total** is `SUM(payroll_items.net_salary)` for the month's period, summed **by
     *     PostgreSQL over the generated column** and handed back as an exact decimal string.
     *     Not `array_sum` over a collection, not a subtraction of two floats, and not a figure
     *     Vue adds up: every one of those is a second opinion about the agency's wage bill.
     *     The rows are scoped by `PayrollItem::scopeVisibleTo()` — a no-op for the Admin who
     *     holds `payroll.view_others`, and the reason this method can never quietly become a
     *     leak if the gate above it is ever loosened;
     *   - **the status**, from `PayrollStatus::label()`, so the card says *Draft* while a month
     *     is still being worked on rather than presenting a provisional wage bill as settled;
     *   - **the line count**, because a period with no items is a different thing from a period
     *     of zero — the first is a draft that has not run, the second would be a payroll of
     *     nothing.
     *
     * **`null` when the month has no period yet**, which is the state of every 1st before
     * `hq:create-payroll-draft` fires. The card then prints an em-dash and says so in words.
     *
     * The link is `Route::has()`-guarded rather than hard-coded: the payroll workbench is
     * another slice's, and a dashboard card pointing at a route that does not exist yet would
     * be a 404 somebody found by clicking rather than by reading a test.
     *
     * @return array{total: string|null, note: string, href: string|null}
     */
    private function payrollThisMonth(User $user, Carbon $asOf): array
    {
        $href = Route::has('payroll.index') ? route('payroll.index', absolute: false) : null;

        if (! Gate::forUser($user)->allows('viewAny', PayrollPeriod::class)) {
            return ['total' => null, 'note' => 'You do not have access to payroll.', 'href' => null];
        }

        $period = PayrollPeriod::query()->forMonth($asOf)->first();

        if ($period === null) {
            return [
                'total' => null,
                'note' => 'No payroll period for this month yet.',
                'href' => $href,
            ];
        }

        $lines = PayrollItem::query()
            ->visibleTo($user)
            ->where('payroll_items.payroll_period_id', $period->getKey())
            ->count();

        // The cast pins the scale, so an empty period reads "0.00" and not "0". The string
        // leaves PostgreSQL already formatted; nothing here parses it.
        $total = (string) PayrollItem::query()
            ->visibleTo($user)
            ->where('payroll_items.payroll_period_id', $period->getKey())
            ->selectRaw('COALESCE(SUM(payroll_items.net_salary), 0)::numeric(12,2)::text as total')
            ->value('total');

        return [
            'total' => $total,
            'note' => sprintf(
                '%s · %s · %s',
                $period->label(),
                $period->status?->label() ?? 'Unknown',
                $lines === 1 ? '1 person' : $lines.' people',
            ),
            'href' => $href,
        ];
    }

    /**
     * "Upcoming meetings" — Part D §12's dashboard card, Phase 7.
     *
     * **One query, scoped, capped.** `Meeting::visibleTo()` is `MeetingPolicy::view()` in SQL,
     * so a meeting this Admin is not in simply is not in the result — there is no list to
     * filter afterwards and nothing for Vue to drop. Cancelled meetings are out (`notCancelled`)
     * and so is anything already finished; what is left is ordered by when it starts and cut at
     * `UPCOMING_MEETINGS`. An Admin sees every meeting in the agency, which is what the scope
     * says and is the same answer the Meetings screen gives them.
     *
     * **`MeetingResource`, not a shape of this card's own** — the same move `upcomingHolidays()`
     * below makes with `HolidayResource`, and for the same reason: a meeting is the same meeting
     * on the card, on the list and on its own page, and a fourth shape would be a fourth place
     * for a state or a Join control to be worded differently. It also means the linked project
     * arrives already scoped per viewer (`linkedContextFor()`), whether or not this card prints
     * it — there is no shortcut here that could leak one.
     *
     * *Follow-up, in the shape decision 6-16 records:* `Employee\DashboardController` asks this
     * same question with this same query, because Phase 7's two slices could not both add a
     * method to `MeetingService`. It wants a `MeetingService::upcomingFor(User, int)` that both
     * call.
     *
     * @return list<array<string, mixed>>
     */
    private function upcomingMeetings(Request $request, User $user): array
    {
        if (! Gate::forUser($user)->allows('viewAny', Meeting::class)) {
            return [];
        }

        $meetings = Meeting::query()
            ->visibleTo($user)
            ->notCancelled()
            // Still to come, or happening right now. `start_at` would have dropped the meeting
            // somebody is five minutes late for, which is the one this card is most useful for.
            ->where('end_at', '>=', now())
            ->with(['organizer', 'participantSeats.user', 'project', 'task'])
            ->orderBy('start_at')
            ->limit(self::UPCOMING_MEETINGS)
            ->get();

        return MeetingResource::collection($meetings)->resolve($request);
    }

    /**
     * "Upcoming holidays" — Part D §3's card, recorded from the design references (Part I).
     *
     * Four rows, today included, straight out of `HolidayService`: the same service the
     * attendance derivation and the Holidays screen read, so this card and the grid cannot
     * disagree about whether Thursday is Victory Day.
     *
     * Empty for somebody who may not see the calendar, which today is nobody signed in — a
     * holiday has no employee, no project and no scope, so there is no holiday that is present
     * for one reader and absent for another (`HolidayPolicy`). The gate is asked anyway rather
     * than assumed, because that is where the answer lives if it ever stops being everybody.
     *
     * @return list<array<string, mixed>>
     */
    private function upcomingHolidays(Request $request, Carbon $asOf): array
    {
        if (! Gate::forUser($request->user())->allows('viewAny', Holiday::class)) {
            return [];
        }

        return HolidayResource::collection($this->holidays->upcoming($asOf))->resolve($request);
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
     *   - **On leave** (Phase 5) — how many DISTINCT PEOPLE have an approved leave request
     *     whose window covers today. It is counted off `leave_requests` and not off attendance
     *     rows, which matters: a remote-timer employee has no attendance rows at all
     *     (decision 4-11), so a count of Leave-status rows would have quietly left Tapu out of
     *     the one number that is about people being away. It is a count of people and nothing
     *     else — no percentage of the agency, no comparison with last week (Part H §1).
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
            // Phase 5. Counted off approved `leave_requests` covering today — see the docblock
            // for why that is not the same as counting Leave attendance rows.
            'on_leave' => $this->leave->onLeaveCount($asOf),
            'leave_href' => '/admin/leave/calendar',
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

    /**
     * "Tasks by employee" — Part D §3's second Row 2 chart, and a **count**.
     *
     * ## It is `WorkloadService`'s answer, not a second one
     *
     * The agency already has a screen that answers *"who is carrying what"* — Admin → Workforce
     * → Workload — and `WorkloadService::forViewer()` is the single statement of it: one
     * `TaskService::count()` per person with `assignee_id` and `TaskBucket::Open`, which is
     * exactly the query `/admin/tasks?assignee_id=…&bucket=open` runs. So this chart and the
     * table it links to cannot disagree, and no new definition of "how much work has Yaseen
     * got" enters the application. A `group by task_assignees.employee_id` written here would
     * have been that second definition, and it would have differed the first time either side
     * changed what "open" means.
     *
     * ## No score, no ranking, no comparison (Part H §1, Part B §3 rule 12)
     *
     * **Ordered by name**, because that is the order `WorkloadService` returns and the order it
     * chose for the same reason: an order picked by a number is a league table whatever the
     * column is called, and the first bar of one reads as the winner. There is no target, no
     * percentage, no "vs last week" and no capacity line — a bar's length is a count of tasks
     * and nothing else. A flatter chart is the correct trade.
     *
     * Everybody in the viewer's scope is on the list, **including the people at zero**: the same
     * reason `taskStatuses()` ships its empty statuses, and the reason the Workload table lists
     * everybody. A chart whose categories come and go as the week does is one whose axis cannot
     * be read twice, and "nothing on Yaseen's plate" is an answer an Admin deciding who takes
     * the next job actually wants.
     *
     * `href` carries the two filters the number was counted with, so a bar that looks wrong can
     * be opened and read. The chart itself cannot hold a link — `BarCompare` has no per-bar
     * `href` — so the card's header links to the Workload table, where every one of these counts
     * is a link; see `Dashboard.vue`.
     *
     * @return list<array{id: int, name: string, count: int, href: string}>
     */
    private function tasksByEmployee(User $user, Carbon $asOf): array
    {
        /** @var list<array<string, mixed>> $employees */
        $employees = $this->workload->forViewer($user, $asOf)['employees'];

        return array_map(fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'count' => (int) $row['open_count'],
            'href' => '/admin/tasks?assignee_id='.$row['id'].'&bucket='.TaskBucket::Open->value,
        ], $employees);
    }

    /**
     * "Projects by type" — Part D §3's third Row 2 chart.
     *
     * One `group by project_type` over `Project::visibleTo()`, not archived: the same two
     * predicates `/admin/projects` starts from, so each bar's `href` opens exactly the projects
     * it counted. No status narrowing, because the list it links to applies none either — a bar
     * reading 3 over a list of 4 is the one failure this card can have.
     *
     * **Every type ships, the empty ones included**, in the enum's own order. `ProjectType` is a
     * fixed set of eight and this is the same argument `taskStatuses()` makes: an axis whose
     * categories appear and disappear as work is won and archived is an axis nobody can compare
     * against last month's. The label is `ProjectType::label()`, so *WooCommerce* is spelled
     * here exactly as it is spelled in the filter bar and on the project's own page.
     *
     * Empty for anybody who may not see projects at all — absent from the payload rather than
     * eight zeroes (Part C §1). `visibleTo()` would already return nothing for them, but a
     * payload of eight zeroes is a statement that the agency has no work, which is a different
     * lie from *"this is not yours to see"*.
     *
     * @return list<array{key: string, label: string, count: int, href: string}>
     */
    private function projectsByType(User $user): array
    {
        if (! Gate::forUser($user)->allows('viewAny', Project::class)) {
            return [];
        }

        $counts = Project::query()
            ->visibleTo($user)
            ->notArchived()
            ->groupBy('project_type')
            ->selectRaw('project_type, count(*) as total')
            ->pluck('total', 'project_type')
            ->all();

        return array_map(fn (ProjectType $type): array => [
            'key' => $type->value,
            'label' => $type->label(),
            'count' => (int) ($counts[$type->value] ?? 0),
            'href' => '/admin/projects?project_type='.$type->value,
        ], ProjectType::cases());
    }

    /**
     * "Upcoming deadlines" — the fourth thing in Part D §3's Row 2, and the one that is a LIST.
     *
     * Part D names four things and calls three of them the chart budget, so this is not a
     * fourth chart. It is `AttentionList`, the panel this file already feeds, because a
     * deadline is something somebody opens rather than something they measure.
     *
     * **It is PROJECT deadlines, not task due dates.** Every task date on this screen is
     * already answered twice over — *Tasks due today* and *Overdue* count them and the attention
     * panel lists the late ones — so a second list of task dates would be a third opinion about
     * the same column. `projects.deadline` is the one date on the Company dashboard that nothing
     * else shows, and it is the date a client was promised.
     *
     * Scoped by `Project::visibleTo()` and narrowed to projects that are still being worked on
     * (`ProjectStatus::isOpen()` — Active or On Hold), not archived, with a deadline inside the
     * window. A finished or cancelled project has no deadline anybody has to meet, and an
     * archived one is read-only by definition (Part D §4).
     *
     * **How near it is, in words.** "Due today", "Due tomorrow", "Due in 9 days" — the second
     * encoding of the urgent medallion, and the only part a screen reader gets (DESIGN.md §5.6,
     * a bug this repo has fixed twice). The client's name rides along because two of the seeded
     * projects are both called *Website Maintenance*.
     *
     * @return list<array{id: string, title: string, meta: string, href: string, tone: string}>
     */
    private function upcomingDeadlines(User $user, Carbon $asOf): array
    {
        if (! Gate::forUser($user)->allows('viewAny', Project::class)) {
            return [];
        }

        $projects = Project::query()
            ->visibleTo($user)
            ->notArchived()
            ->whereIn('status', array_map(
                fn (ProjectStatus $status): string => $status->value,
                array_filter(ProjectStatus::cases(), fn (ProjectStatus $status): bool => $status->isOpen()),
            ))
            ->whereNotNull('deadline')
            ->whereBetween('deadline', [
                $asOf->toDateString(),
                $asOf->copy()->addDays(self::DEADLINE_WINDOW_DAYS)->toDateString(),
            ])
            ->with('client:id,name')
            ->orderBy('deadline')
            ->orderBy('id')
            ->limit(self::UPCOMING_DEADLINES)
            ->get();

        return $projects->map(function (Project $project) use ($asOf): array {
            $days = (int) $asOf->copy()->startOfDay()
                ->diffInDays($project->deadline->copy()->startOfDay());

            return [
                'id' => 'deadline-'.$project->getKey(),
                'title' => (string) $project->name,
                'meta' => sprintf(
                    '%s · %s',
                    $this->deadlineWhen($days),
                    // A project without a client is Internal (decision 1-1), said in words
                    // rather than left blank — a blank reads as missing data.
                    $project->client?->name ?? 'Internal',
                ),
                'href' => '/admin/projects/'.$project->getKey(),
                // Today and tomorrow are urgent. The medallion is the second encoding of the
                // words above, never the only one.
                'tone' => $days <= 1 ? 'urgent' : 'default',
            ];
        })->values()->all();
    }

    /**
     * How near a deadline is, in words.
     */
    private function deadlineWhen(int $days): string
    {
        return match (true) {
            $days <= 0 => 'Due today',
            $days === 1 => 'Due tomorrow',
            default => 'Due in '.$days.' days',
        };
    }
}
