<?php

namespace App\Http\Controllers\Shared;

use App\Exceptions\LeaveStateException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Leave\ApplyLeaveRequest;
use App\Http\Resources\LeaveRequestResource;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\LeaveService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * My Leave: this person's balances, the apply form, and their own history.
 *
 * ## Why this is shared and not one controller per surface
 *
 * Part C §1 gives *Apply for own leave* to **every** role — ADMIN, MANAGER, EMPLOYEE,
 * REMOTE_EMPLOYEE and ACCOUNTANT — and says so again underneath the matrix: *"Two cells the
 * matrix implies but the sidebar does not show: the ACCOUNTANT may apply for own leave and view
 * own payslip. The Accountant shell therefore carries My Leave and My Payslip."*
 *
 * So applying for leave is a fact about the PERSON and not about the shell they happened to
 * open — exactly what decision 4-15 said about clocking in, which is why
 * `Shared\AttendanceController` exists and why this one does. Three copies of these three
 * routes, one per surface file, would have been three places for "whose leave is this" to be
 * answered differently, and the Accountant's copy would have been the one nobody tested.
 *
 * **The Accountant still applies in its own shell**, and that is the page's doing rather than
 * the route's: `Pages/Shared/Leave.vue` picks its layout from `auth.user.surface`, the way
 * `Pages/Shared/Profile.vue` and `Pages/Shared/Attendance.vue` do. An Accountant sees
 * `AccountantLayout` with the finance nav and a *My Leave* row in it, and never imports
 * anything from `Layouts/AdminLayout.vue` or `Pages/Admin/` (AGENTS.md's rule).
 *
 * It also meant `routes/employee.php` and `routes/accountant.php` needed no change at all,
 * which is two fewer contested files between two agents working the same phase — the same
 * incidental benefit decision 4-15 notes for the clock.
 *
 * ## The queue is NOT here
 *
 * Approving, rejecting and sending a request back are Admin acts on the Admin surface, and so
 * is editing somebody's balance. See `Admin\LeaveController` and `Admin\LeaveBalanceController`.
 * This controller can only ever act on the requester's own record.
 *
 * ## 404, not 403
 *
 * `PUT /leave/{leaveRequest}` takes an id, and it is re-resolved through
 * `LeaveRequest::visibleTo()` before any policy is asked — so somebody else's request is
 * **absent** rather than refused, and the Accountant asking for one never learns whether the id
 * existed (Part C). The 403s on this controller are the two that are about the requester rather
 * than the record: a signed-out visitor is sent to log in, and somebody who does not hold
 * `leave.apply` — or has no employee record to have leave at all — is refused by
 * `LeaveRequestPolicy::viewAny`.
 */
class LeaveController extends Controller
{
    public function __construct(private readonly LeaveService $leave) {}

    /**
     * My Leave: balances per type, the apply form's options, and this person's history.
     */
    public function show(Request $request): Response
    {
        $employee = $this->employee($request);

        Gate::authorize('viewAny', LeaveRequest::class);

        $requests = LeaveRequest::query()
            ->forEmployee($employee)
            ->with(['leaveType', 'approver'])
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->get();

        return Inertia::render('Shared/Leave', [
            'subject' => [
                'id' => (int) $employee->getKey(),
                'name' => $employee->user?->name ?? 'Unknown',
            ],

            // Every capped type, including the ones this employee has no row for — as zero.
            // Zero is an answer, not an empty state: "you have no Sick leave left" and "Sick
            // leave does not exist here" are different sentences and the screen must say the
            // first one.
            'balances' => $this->leave->balancesFor($employee),

            // The picker's options, with `has_balance` and `is_unpaid` on each, so the form can
            // say "no balance — these days are uncapped" and warn that Unpaid days are unpaid
            // BEFORE somebody sends it rather than on a payslip. The server sends the list; the
            // screen does not know the six names.
            'types' => LeaveType::query()
                ->inOrder()
                ->get()
                ->map(fn (LeaveType $type): array => $type->toPayload())
                ->values()
                ->all(),

            'requests' => LeaveRequestResource::collection($requests)->toArray($request),

            'permissions' => [
                'can_apply' => Gate::allows('create', LeaveRequest::class),
            ],
        ]);
    }

    /**
     * File a request.
     *
     * A refusal from the service — overlapping dates, not enough days, a range with no working
     * days in it — is a flash error and not a status code, for the reason a refused clock-in is
     * one: the person is trying to book a week off and needs to be told which week is already
     * spoken for, and an HTTP code is not that. Authorization refusals stay 403.
     */
    public function store(ApplyLeaveRequest $request): RedirectResponse
    {
        $employee = $this->employee($request);

        Gate::authorize('create', LeaveRequest::class);

        try {
            $leaveRequest = $this->leave->apply(
                $request->user(),
                $employee,
                $request->leaveType(),
                $request->startDate(),
                $request->endDate(),
                $request->reason(),
            );
        } catch (LeaveStateException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', sprintf(
            'Leave requested: %s, %d %s. It is waiting for approval.',
            $leaveRequest->leaveType?->name ?? 'Leave',
            $leaveRequest->days,
            $leaveRequest->days === 1 ? 'day' : 'days',
        ));
    }

    /**
     * Answer a correction request by amending and resubmitting.
     *
     * The same request, moving `correction_requested → pending` — not a new one, which is the
     * whole point of the status existing. `LeaveRequestPolicy::resubmit` is what refuses it on
     * any other status, and the model's machine refuses the move again.
     */
    public function update(ApplyLeaveRequest $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        $leaveRequest = $this->visible($request, $leaveRequest);

        Gate::authorize('resubmit', $leaveRequest);

        try {
            $updated = $this->leave->resubmit(
                $request->user(),
                $leaveRequest,
                $request->leaveType(),
                $request->startDate(),
                $request->endDate(),
                $request->reason(),
            );
        } catch (LeaveStateException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', sprintf(
            'Sent back for approval: %s, %d %s.',
            $updated->leaveType?->name ?? 'Leave',
            $updated->days,
            $updated->days === 1 ? 'day' : 'days',
        ));
    }

    /**
     * The signed-in person's employee record, or 404.
     *
     * A user with no employee row has no leave to show and nothing for an apply form to be
     * about. It cannot happen on the seed — every user is an employee — and it is answered as
     * absence rather than as a refusal, because there is no record here to refuse access to.
     */
    private function employee(Request $request): Employee
    {
        $employee = $request->user()?->employee;

        if ($employee === null) {
            throw new NotFoundHttpException;
        }

        $employee->loadMissing(['user', 'schedule']);

        return $employee;
    }

    /**
     * The request this id names, resolved through the visibility scope — so somebody else's is
     * absent rather than refused. See the class docblock.
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
}
