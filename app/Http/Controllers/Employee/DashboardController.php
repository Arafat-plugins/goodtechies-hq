<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Http\Resources\HolidayResource;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\Task;
use App\Services\AttendanceService;
use App\Services\HolidayService;
use App\Services\LeaveService;
use App\Services\TaskService;
use App\Services\TimerService;
use App\Support\LeaveStatus;
use App\Support\TaskBucket;
use App\Support\TrackingMode;
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

    public function __construct(
        private readonly TaskService $tasks,
        private readonly TimerService $timers,
        private readonly AttendanceService $attendance,
        private readonly HolidayService $holidays,
        private readonly LeaveService $leave,
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
            'leave' => $this->myLeave($user?->employee),
        ]);
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

        return [
            'today' => $this->attendance->dayFor($employee, Carbon::today(config('app.timezone')))->toArray(),
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
