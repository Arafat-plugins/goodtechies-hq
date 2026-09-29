<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Http\Resources\HolidayResource;
use App\Http\Resources\MeetingResource;
use App\Models\ActivityLog;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\Meeting;
use App\Models\Task;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\HolidayService;
use App\Services\LeaveService;
use App\Services\ScheduleService;
use App\Services\TaskService;
use App\Services\TimerService;
use App\Support\LeaveStatus;
use App\Support\TaskBucket;
use App\Support\TrackingMode;
use App\Support\Weekday;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * The plan's five dashboard cards, in its order: My Tasks, Due Today, Overdue, In
     * Progress, Completed.
     *
     * @var list<TaskBucket>
     */
    private const CARDS = [
        TaskBucket::Open,
        TaskBucket::DueToday,
        TaskBucket::Overdue,
        TaskBucket::InProgress,
        TaskBucket::Completed,
    ];

    /**
     * How many rows the "Upcoming meetings" card shows. The same five the Company dashboard's
     * card shows, and for the same reason: it answers *"what is on today and tomorrow"*, and
     * the Meetings screen it links to is where the rest of the diary is.
     */
    private const UPCOMING_MEETINGS = 5;

    /**
     * How many rows "Recent activity" shows, and how far back it looks to find them.
     *
     * The window is a bounded scan rather than a join, for the reason `Admin\DashboardController`
     * names at `REVIEW_SCAN`: *"is this task still mine to see"* is `Task::visibleTo()`'s
     * question, asked of the scope rather than restated as SQL inside an `activity_logs` query.
     * Forty is an order of magnitude above the five rows shown, so a person who spent yesterday
     * on one task still gets a list.
     */
    private const RECENT_ACTIVITY = 5;

    private const ACTIVITY_SCAN = 40;

    public function __construct(
        private readonly TaskService $tasks,
        private readonly TimerService $timers,
        private readonly AttendanceService $attendance,
        private readonly HolidayService $holidays,
        private readonly LeaveService $leave,
        private readonly ScheduleService $schedules,
    ) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Employee/Dashboard', [
            'greetingName' => Str::before(trim($user->name), ' '),
            'today' => now(config('app.timezone'))->toDateString(),
            'trackingMode' => ($user->employee?->tracking_mode ?? TrackingMode::None)->value,
            'taskStats' => $this->taskStats($request),
            // The hero, whichever kind of day this person has. One of the two is null, and
            // which one is decided by `tracking_mode` on the server — never in Vue from a
            // role, and never by the card itself.
            'timer' => $this->timerHero($user?->employee),
            'attendance' => $this->attendanceHero($user?->employee),
            'upcomingHolidays' => $this->upcomingHolidays($request),
            'upcomingMeetings' => $this->upcomingMeetings($request, $user),
            'leave' => $this->myLeave($user?->employee),
            // The two Part D §3 secondary cards this dashboard was still missing.
            'schedule' => $this->mySchedule($user?->employee),
            'recentActivity' => $this->recentActivity($user),
        ]);
    }

    /**
     * "My Schedule" — Part D §3 lists it among this dashboard's secondary cards.
     *
     * Four facts about the reader's own week, and **`ScheduleService::rowFor()` is where all four
     * come from**: the working days in the week's order, the hours a day, the start time and
     * whether the day is an office day or a remote one. Those are the same four the Admin's
     * schedule editor writes and the same four `/attendance` prints at the top of the month, so a
     * person cannot be told their week is Sunday-to-Thursday on one screen and Monday-to-Friday on
     * another. Nothing is derived here and no weekday order is spelled out: the service returns
     * them in `Weekday`'s order and the labels are `Weekday::shortLabel()`.
     *
     * **This is the card that explains the two above it.** Whether today is an Off Day, and
     * whether 9:07 counts as late, are both answers about the schedule — a clock widget that says
     * *Off day* with nothing on the page saying which days are working days is a state nobody can
     * check.
     *
     * `null` for somebody with no employee record, and `schedule: null` for an employee nobody has
     * given one: the card then says so in words rather than printing a zero-hour week (Part C §1's
     * shape — an absence is rendered, not faked).
     *
     * @return array<string, mixed>|null
     */
    private function mySchedule(?Employee $employee): ?array
    {
        if ($employee === null) {
            return null;
        }

        $schedule = $this->schedules->rowFor($employee)['schedule'];

        if ($schedule === null) {
            return null;
        }

        return [
            // `sun` → `Sun`, from the enum. Already in week order; nothing here sorts them.
            'days' => array_map(
                fn (string $day): string => Weekday::from($day)->shortLabel(),
                $schedule['working_days'],
            ),
            'hours_per_day' => $schedule['working_hours_per_day'],
            'start_time' => $schedule['start_time'],
            // The word, resolved here. `Pages/Shared/Attendance.vue` reads the raw column and
            // picks the word itself; a card that did the same would be a second place for the
            // same two strings, and the server is where every other label on this page is chosen.
            'location' => $schedule['office_or_remote'] === 'remote' ? 'Remote' : 'Office',
            // Their own month, which is where the schedule is shown in full beside what it
            // produced. The shared route defaults to the requester, so no id travels.
            'href' => '/attendance',
        ];
    }

    /**
     * "Recent activity" — Part D §3's last secondary card, and Part I's *"Recent activity list
     * (spec §23 lists it for the employee surface)"*.
     *
     * ## Whose activity: the reader's own, and there is no query here that could return anybody
     * else's
     *
     * `actor_id = the requester` is the first `where` clause. That makes this a record of what
     * **you** did, which is what the card the design reference shows is, and it is also the only
     * shape of it that cannot become surveillance: Part H §1 forbids monitoring and spec §22
     * keeps an activity feed off the Company dashboard entirely for that reason. A feed of
     * *"everything that happened on your projects"* would have been the second kind — it would put
     * colleagues' movements on a screen nobody asked for one on.
     *
     * ## And only tasks, still checked against `Task::visibleTo()`
     *
     * `activity_logs` is written for tasks, clients, projects, tags and meetings. This card reads
     * **tasks**, because a task is the only one of the five that this surface has a page to open
     * and a row that cannot be opened is worse than no row (the rule `Admin\DashboardController`
     * states for its attention panel).
     *
     * The visibility check is not decoration: a task can be handed off, reassigned or archived
     * after somebody worked on it, and their old log line must not become a title they may no
     * longer read. So the window is scanned, the ids are resolved through `Task::visibleTo()` —
     * the one statement of that rule (decision 2-37) — and a row whose task has left their scope
     * is dropped rather than shown without a link.
     *
     * Empty is an answer: a person who has not touched a task yet has no activity, and the card
     * says that instead of naming a phase.
     *
     * **`meta` is composed here, not in Vue**, for the reason `UpcomingHolidaysCard` states about
     * its own day counts: the agency's timezone is the server's, and "2 hours ago" computed from a
     * `Date` in the browser moves by five hours on a laptop somebody took to London. The row's
     * title is the log's own sentence — `ActivityLogger` already writes it for a reader.
     *
     * @return list<array{id: int, title: string, meta: string, href: string}>
     */
    private function recentActivity(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        $logs = ActivityLog::query()
            ->where('actor_id', $user->getKey())
            ->where('object_type', (new Task)->getMorphClass())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::ACTIVITY_SCAN)
            ->get();

        if ($logs->isEmpty()) {
            return [];
        }

        $tasks = Task::query()
            ->visibleTo($user)
            ->whereIn('id', $logs->pluck('object_id')->unique()->all())
            ->get(['id', 'title'])
            ->keyBy('id');

        return $logs
            ->filter(fn (ActivityLog $log): bool => $tasks->has($log->object_id))
            ->take(self::RECENT_ACTIVITY)
            ->map(fn (ActivityLog $log): array => [
                'id' => (int) $log->getKey(),
                'title' => (string) $log->description,
                'meta' => sprintf(
                    '%s · %s',
                    (string) $tasks->get($log->object_id)->title,
                    $log->created_at?->diffForHumans() ?? 'Unknown time',
                ),
                'href' => '/employee/tasks/'.$log->object_id,
            ])
            ->values()
            ->all();
    }

    /**
     * "Upcoming meetings" — Part D §12's dashboard card, Phase 7.
     *
     * **One query, scoped, capped.** `Meeting::visibleTo()` is `MeetingPolicy::view()` in SQL:
     * for this person it is the meetings they organise or were invited to, and nothing else.
     * That is what replaces the "Arrives in Phase 7" panel this slot used to hold, and it is
     * the same query and the same five rows the Company dashboard's card runs — a meeting is
     * the same meeting on both screens, it is only the scope that differs, and the scope is the
     * one thing neither controller decides for itself.
     *
     * **`MeetingResource`, not a shape of this card's own** — the same move
     * `upcomingHolidays()` below makes with `HolidayResource`: a meeting is the same meeting on
     * the card, on the list and on its own page, and a fourth shape would be a fourth place for
     * a state or a Join control to be worded differently. It also means the linked project
     * arrives already scoped per viewer (`linkedContextFor()`), whether or not this card prints
     * it — somebody can be in a meeting about a project they are not on, and there is no
     * shortcut here that could leak one.
     *
     * *Follow-up, in the shape decision 6-16 records:* `Admin\DashboardController` asks this
     * same question with this same query, because Phase 7's two slices could not both add a
     * method to `MeetingService`. It wants a `MeetingService::upcomingFor(User, int)` that both
     * call.
     *
     * @return list<array<string, mixed>>
     */
    private function upcomingMeetings(Request $request, ?User $user): array
    {
        if ($user === null || ! Gate::forUser($user)->allows('viewAny', Meeting::class)) {
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
     * "My Leave" — Part D §9 puts this card in the My Work group of this dashboard.
     *
     * Three numbers about **this person and nobody else**: days left of the type they have most
     * of, how many of their own requests are still waiting on a decision, and whether one has
     * been sent back to them for a correction. Nothing here is anybody else's leave, nothing is
     * a comparison, and nothing is a total across the team (Part H §1).
     *
     * The correction count leads the card's sub-line when it is non-zero, because it is the one
     * of the three that is waiting on the READER — a pending request is waiting on an approver
     * and there is nothing for them to do about it.
     *
     * Null for somebody with no employee record, who has no leave to have.
     *
     * @return array<string, mixed>|null
     */
    private function myLeave(?Employee $employee): ?array
    {
        if ($employee === null) {
            return null;
        }

        $balances = $this->leave->balancesFor($employee);

        $byStatus = LeaveRequest::query()
            ->forEmployee($employee)
            ->groupBy('status')
            ->selectRaw('status, count(*) as total')
            ->pluck('total', 'status')
            ->all();

        return [
            // The four capped types with their numbers, so the card can print the largest and
            // link to the page that has all of them. Zero is an answer and stays in the list.
            'balances' => array_map(fn (array $row): array => [
                'name' => (string) $row['type']['name'],
                'days' => (int) $row['balance_days'],
            ], $balances),
            'pending' => (int) ($byStatus[LeaveStatus::Pending->value] ?? 0),
            'correction_requested' => (int) ($byStatus[LeaveStatus::CorrectionRequested->value] ?? 0),
            'href' => '/leave',
        ];
    }

    /**
     * "Upcoming holidays" — Part D §3 puts the card on this dashboard as well as the Admin's.
     *
     * The **same** `HolidayService::upcoming()` and the same `HolidayResource` the Company
     * dashboard reads, because it is the same question with the same answer: a holiday is a
     * fact about the company, not about the person looking at it, so there is nothing to scope
     * and nothing that could make the two screens disagree.
     *
     * What differs is what the card offers: no "See all" and no add control, because this
     * surface has no holiday screen to send anybody to. The permissions block on each row says
     * so per record (`can_update` is false here), which is the same server-resolved answer the
     * Admin screen reads — never a role compared in Vue (decisions 2-28, 2-31).
     *
     * @return list<array<string, mixed>>
     */
    private function upcomingHolidays(Request $request): array
    {
        if (! Gate::forUser($request->user())->allows('viewAny', Holiday::class)) {
            return [];
        }

        return HolidayResource::collection($this->holidays->upcoming())->resolve($request);
    }

    /**
     * Today's tracked time, for the employee who tracks it.
     *
     * The figures are `TimerService`'s, which is the only thing that knows what counts:
     * `countedSecondsOn()` asks `approved_at is not null` and nothing else, and
     * `pendingSecondsOn()` is the rest — hours that are recorded but not yet counted, because
     * `manual_time_requires_approval` is on. Printing only the counted half would quietly lose
     * them, which is the one way this card could mislead.
     *
     * The controls are not here. The timer bar is on every page of this shell and owns
     * start/pause/stop; a second set of buttons would be a second thing to keep in step.
     *
     * @return array<string, mixed>|null
     */
    private function timerHero(?Employee $employee): ?array
    {
        if ($employee === null || $employee->tracking_mode !== TrackingMode::RemoteTimer) {
            return null;
        }

        return [
            'counted_seconds' => $this->timers->countedSecondsOn($employee),
            'pending_seconds' => $this->timers->pendingSecondsOn($employee),
            'target_seconds' => $this->timers->targetSecondsFor($employee),
        ];
    }

    /**
     * Today's attendance, for the employee who clocks.
     *
     * `dayFor()` is the single derivation of what a day is — Present, Late, Off day, or no
     * record yet — so this card cannot disagree with the roster the Admin is looking at.
     * `canClock` is the policy's answer, resolved here, because a button drawn from a role in
     * Vue is the mistake this repo has already caught twice.
     *
     * @return array<string, mixed>|null
     */
    private function attendanceHero(?Employee $employee): ?array
    {
        if ($employee === null || ! $this->attendance->clocks($employee)) {
            return null;
        }

        $today = Carbon::today(config('app.timezone'));

        // Today's row, fetched exactly as `/attendance` fetches it. Without it `dayFor()` reads
        // "no record yet", and the widget offered Clock in to somebody already clocked in.
        $record = AttendanceRecord::query()
            ->where('employee_id', $employee->getKey())
            ->forDate($today)
            ->with('editor')
            ->first();

        return [
            'today' => $this->attendance->dayFor($employee, $today, $record)->toArray(),
            'can_clock' => Gate::allows('clock', [AttendanceRecord::class, $employee]),
        ];
    }

    /**
     * Five counts, five links, all from TaskService.
     *
     * Every number is its own COUNT scoped by `Task::visibleTo()` and narrowed by `mine` —
     * never derived in Vue from a list, because there is no list on this page to derive it
     * from and a card that counted one would be counting a page.
     *
     * Each card links to the bucket it counted on the My Tasks page, which is the screen that
     * defines those buckets. So the number and what you get when you click it are the same
     * query, asked twice: "Overdue: 4" opens those four.
     *
     * A person who may not view tasks at all (no such role holds this surface today, but the
     * gate is the rule, not the roster) gets no cards rather than five zeroes — zero is an
     * answer about work, and "you may not see this" is not that answer.
     *
     * @return list<array{key: string, label: string, count: int, href: string}>
     */
    private function taskStats(Request $request): array
    {
        if (! Gate::forUser($request->user())->allows('viewAny', Task::class)) {
            return [];
        }

        $cards = $this->tasks->bucketCards($request->user(), self::CARDS, [
            'mine' => true,
            'as_of' => Carbon::today(),
        ]);

        return array_map(fn (array $card): array => [
            ...$card,
            'href' => $card['key'] === TaskBucket::Open->value
                ? '/employee/my-tasks'
                : '/employee/my-tasks?bucket='.$card['key'],
        ], $cards);
    }
}
