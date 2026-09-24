<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\LeaveStateException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Leave\DecideLeaveRequest;
use App\Http\Resources\LeaveRequestResource;
use App\Models\LeaveRequest;
use App\Services\LeaveService;
use App\Support\LeaveStatus;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Admin → Workforce → Leave: the requests queue and the leave calendar (Part D §9, Phase 5).
 *
 * ## Three verbs, three endpoints, one decision
 *
 * Approve / Reject / Request Correction are three routes because they are three different acts
 * with three different rules about the note — and because which one was called is then a fact
 * about the URL rather than a field in the body a client could choose (see
 * `DecideLeaveRequest`). All three funnel into `LeaveService`, which is the only thing that
 * moves a request's status; there is no second path, and `LeaveRequest`'s guard throws if
 * anybody ever writes one.
 *
 * ## 403 and 404
 *
 * Every cell of the permission matrix for these routes is **403** for everybody but the Admin,
 * and that is the shape of the rule on this surface: reading the agency's leave and ruling on
 * it are Admin acts, so `surface:admin` stops the other shells before a record is looked up at
 * all, and `LeaveRequestPolicy::review` / `::decide` sit behind it so that widening the surface
 * one day would not quietly hand somebody the power to approve leave.
 *
 * The **404** that does exist is a request outside the requester's scope: `{leaveRequest}` is
 * re-resolved through `LeaveRequest::visibleTo()` **before** the policy is asked, so it is
 * absent rather than refused. No matrix row can show it today, because an Admin sees every
 * request and everybody else is stopped by the surface first — so it is asserted directly in
 * `tests/Feature/Leave/LeaveEndpointsTest.php`, the same way decision 3-8 handles it for a
 * recurring template.
 *
 * ## A refusal from the request's own state is a flash, not a status code
 *
 * Two Admins pressing Approve and Reject on the same row in the same second is a real race and
 * the service locks for it: the second one finds an approved request and the machine refuses
 * the move. That is a sentence on the screen they were looking at — *"A leave request cannot go
 * from Approved to Rejected"* — not a 409 nobody can read. The same choice the task detail
 * makes for a refused transition.
 */
class LeaveController extends Controller
{
    public function __construct(private readonly LeaveService $leave) {}

    /**
     * The queue. Open requests by default, because a queue is a list of things still owed a
     * decision; `?status=` widens it to any one status or to everything.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('review', LeaveRequest::class);

        $status = $this->status($request);

        $requests = LeaveRequest::query()
            ->visibleTo($request->user())
            ->with(['employee.user', 'employee.role', 'leaveType', 'approver'])
            ->when(
                $status === null,
                fn ($query) => $query->awaitingDecision(),
                fn ($query) => $query->where('status', $status->value),
            )
            // Soonest first: a request for next Monday matters more than one for December, and
            // a queue ordered by when it was filed buries the urgent one under the patient one.
            ->orderBy('start_date')
            ->orderBy('id')
            ->get();

        return Inertia::render('Admin/Leave/Index', [
            'requests' => LeaveRequestResource::collection($requests)->toArray($request),

            // The tabs, with their counts, from the same scope the list uses — so a tab's
            // number and the list it opens are the same `where` and cannot disagree
            // (decision 2-37).
            'counts' => $this->counts($request),

            'filters' => ['status' => $status?->value],

            // Sent as data so the filter's options and `LeaveStatus` are one list.
            'statuses' => array_map(fn (LeaveStatus $case): array => [
                'value' => $case->value,
                'label' => $case->label(),
                'tone' => $case->tone(),
            ], LeaveStatus::cases()),
        ]);
    }

    /**
     * The leave calendar: one month, everybody in scope, approved and pending.
     *
     * Pending is drawn as well as approved, and that is deliberate rather than incidental — an
     * Admin looking at the calendar to decide whether they can spare somebody next week needs
     * to see what has already been asked for as well as what has been granted. The two are
     * never the same colour and each prints its word (`LeaveStatus::tone()` and `label()`).
     */
    public function calendar(Request $request): Response
    {
        Gate::authorize('review', LeaveRequest::class);

        $month = $this->month($request);
        $from = $month->copy()->startOfMonth();
        $to = $month->copy()->endOfMonth();

        /** @var Collection<int, LeaveRequest> $requests */
        $requests = LeaveRequest::query()
            ->visibleTo($request->user())
            ->holding()
            ->overlapping($from, $to)
            ->with(['employee.user', 'employee.schedule', 'leaveType'])
            ->orderBy('start_date')
            ->get();

        return Inertia::render('Admin/Leave/Calendar', [
            'month' => [
                'value' => $month->format('Y-m'),
                'label' => $month->isoFormat('MMMM YYYY'),
                'previous' => $month->copy()->subMonthNoOverflow()->format('Y-m'),
                'next' => $month->copy()->addMonthNoOverflow()->format('Y-m'),
            ],

            // Every date of the month in order, each carrying who is away on it. The days are
            // expanded on the SERVER, per employee, through `LeaveService::daysByDate()` —
            // which asks each person's own schedule, so a calendar never paints Friday as leave
            // for a team that does not work Fridays, and never does date arithmetic in Vue.
            'days' => $this->days($from, $to, $this->leave->daysByDate($requests, $from, $to)),

            // The same requests as a list, for the sub-`md` reading of this screen. See
            // `Components/Leave/LeaveCalendar.vue` for why a phone gets a list and not a grid.
            'requests' => LeaveRequestResource::collection($requests)->toArray($request),
        ]);
    }

    public function approve(DecideLeaveRequest $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        return $this->decide(
            $request,
            $leaveRequest,
            fn (LeaveRequest $subject): LeaveRequest => $this->leave->approve(
                $request->user(),
                $subject,
                $request->note(),
            ),
            fn (LeaveRequest $subject): string => sprintf(
                'Approved: %s, %s, %d %s.',
                $subject->employee?->user?->name ?? 'Employee',
                $subject->leaveType?->name ?? 'Leave',
                $subject->days,
                $subject->days === 1 ? 'day' : 'days',
            ),
        );
    }

    public function reject(DecideLeaveRequest $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        return $this->decide(
            $request,
            $leaveRequest,
            fn (LeaveRequest $subject): LeaveRequest => $this->leave->reject(
                $request->user(),
                $subject,
                (string) $request->note(),
            ),
            fn (LeaveRequest $subject): string => sprintf(
                'Turned down: %s, %s. They can read your reason on their own leave page.',
                $subject->employee?->user?->name ?? 'Employee',
                $subject->leaveType?->name ?? 'Leave',
            ),
        );
    }

    public function correction(DecideLeaveRequest $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        return $this->decide(
            $request,
            $leaveRequest,
            fn (LeaveRequest $subject): LeaveRequest => $this->leave->requestCorrection(
                $request->user(),
                $subject,
                (string) $request->note(),
            ),
            fn (LeaveRequest $subject): string => sprintf(
                'Sent back to %s with your note. Nothing has been spent or booked.',
                $subject->employee?->user?->name ?? 'them',
            ),
        );
    }

    /**
     * The shape all three verbs share: resolve, authorise, act, answer in a sentence.
     *
     * @param  Closure(LeaveRequest): LeaveRequest  $act
     * @param  Closure(LeaveRequest): string  $sentence
     */
    private function decide(
        Request $request,
        LeaveRequest $leaveRequest,
        Closure $act,
        Closure $sentence,
    ): RedirectResponse {
        $leaveRequest = $this->visible($request, $leaveRequest);

        Gate::authorize('decide', $leaveRequest);

        try {
            $decided = $act($leaveRequest);
        } catch (LeaveStateException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', $sentence($decided));
    }

    /**
     * The request this id names, resolved through the visibility scope BEFORE the policy — so a
     * request outside the requester's scope is absent, not refused.
     */
    private function visible(Request $request, LeaveRequest $leaveRequest): LeaveRequest
    {
        $visible = LeaveRequest::query()
            ->visibleTo($request->user())
            ->with(['employee.schedule', 'employee.user', 'leaveType'])
            ->whereKey($leaveRequest->getKey())
            ->first();

        if ($visible === null) {
            throw new NotFoundHttpException;
        }

        return $visible;
    }

    /**
     * How many requests sit under each tab. Counts, and nothing but counts (Part H §1).
     *
     * @return array<string, int>
     */
    private function counts(Request $request): array
    {
        $byStatus = LeaveRequest::query()
            ->visibleTo($request->user())
            ->groupBy('status')
            ->selectRaw('status, count(*) as total')
            ->pluck('total', 'status')
            ->all();

        $counts = ['open' => 0];

        foreach (LeaveStatus::cases() as $case) {
            $counts[$case->value] = (int) ($byStatus[$case->value] ?? 0);

            if ($case->isOpen()) {
                $counts['open'] += $counts[$case->value];
            }
        }

        return $counts;
    }

    /**
     * `?status=` — one of the four, or null for the open ones.
     *
     * An unknown value falls back to the open queue rather than 404ing: it is a filter that
     * used to exist or never did, and the useful answer to "show me the queue" is the queue.
     */
    private function status(Request $request): ?LeaveStatus
    {
        return LeaveStatus::tryFrom((string) $request->query('status', ''));
    }

    /**
     * `?month=YYYY-MM`, because which month you are looking at is shareable and belongs in the
     * URL (DESIGN.md §5.10). Anything unparseable falls back to this month rather than throwing
     * — a mistyped link should show a calendar.
     */
    private function month(Request $request): Carbon
    {
        $value = trim((string) $request->query('month', ''));

        if ($value === '') {
            return Carbon::today()->startOfMonth();
        }

        try {
            return Carbon::createFromFormat('Y-m', $value)->startOfMonth();
        } catch (\Throwable) {
            return Carbon::today()->startOfMonth();
        }
    }

    /**
     * Every date of the month, in order, with who is away on each.
     *
     * Built here rather than in the grid for the reason `AttendanceDay` exists: the weekday
     * name, the day number and the leading-blank arithmetic are the server's, so a phone list
     * and a desktop grid read the same rows and neither parses a date string in the browser —
     * which is a timezone conversion, and a month that slid by a day either side of midnight is
     * a bug nobody can reproduce (see `Components/Attendance/attendance.ts`).
     *
     * @param  array<string, list<array<string, mixed>>>  $byDate
     * @return list<array<string, mixed>>
     */
    private function days(Carbon $from, Carbon $to, array $byDate): array
    {
        $today = Carbon::today();
        $days = [];

        for ($day = $from->copy(); $day->lessThanOrEqualTo($to); $day->addDay()) {
            $key = $day->toDateString();

            $days[] = [
                'date' => $key,
                'weekday' => $day->isoFormat('dddd'),
                'day_of_month' => $day->day,
                'is_today' => $day->isSameDay($today),
                'is_past' => $day->lessThan($today),
                'people' => $byDate[$key] ?? [],
            ];
        }

        return $days;
    }
}
