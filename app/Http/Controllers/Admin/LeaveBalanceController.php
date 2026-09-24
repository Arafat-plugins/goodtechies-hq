<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\LeaveStateException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Leave\UpdateLeaveBalanceRequest;
use App\Models\Employee;
use App\Models\LeaveBalance;
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
 * Admin → Workforce → Leave → Balances: how many days of each capped type each employee has
 * left, and the one place a number is changed (Part D §9, Phase 5).
 *
 * ## No accrual. The plan says so twice.
 *
 * *"seeded per employee by Admin; no accrual logic in MVP — Admin adjusts balances,
 * audit-logged"*. There is no job here, no yearly reset and no carry-over: an Admin types the
 * number that should be there, with a reason, and `leave.balance_adjusted` records the old and
 * the new value beside it. Approving a request is the only other thing that moves one, and
 * `LeaveService` owns both.
 *
 * ## One endpoint, keyed by (employee, type)
 *
 * `PUT /admin/leave/balances/{employee}/{leaveType}` — the row's own identity, which is
 * `unique(employee_id, leave_type_id)`. Setting somebody's first balance and correcting one
 * they already have are the same act to the Admin making it, so it is one upsert and not two
 * endpoints — the shape the attendance correction already uses, and for the same reason: two
 * endpoints would be two places for the audit row to be forgotten.
 *
 * ## 403 and 404
 *
 * 403 for every shell but the Admin's, from `surface:admin` and `LeaveRequestPolicy` agreeing.
 * 404 for an employee outside the requester's scope — `Employee::leaveVisibleTo()` resolves the
 * parameter before the policy is asked, so they are absent rather than refused.
 *
 * An **uncapped** type named in the URL is neither: `LeaveService::setBalance()` refuses it with
 * a sentence — *"Unpaid leave has no balance, so there is no number to set"* — because the type
 * is perfectly visible and perfectly real, it simply has nothing to hold a number. The grid
 * offers no column for one, so the only way to reach it is by typing the URL.
 */
class LeaveBalanceController extends Controller
{
    public function __construct(private readonly LeaveService $leave) {}

    /**
     * The grid: every employee in scope, every capped type, and the number.
     *
     * Zero for a pair with no row, because zero is an answer — see `LeaveService::balancesFor()`.
     * Rows are ordered by name and nothing on this screen is sortable by a number: a table of
     * people ordered by how much leave they have left is a ranking, and Part H §1 forbids one.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('manageBalances', LeaveRequest::class);

        $types = LeaveType::query()->capped()->inOrder()->get();

        $employees = Employee::query()
            ->leaveVisibleTo($request->user())
            ->tracked()
            ->with(['user', 'role'])
            ->join('users', 'users.id', '=', 'employees.user_id')
            ->orderBy('users.name')
            ->select('employees.*')
            ->get();

        $balances = LeaveBalance::query()
            ->whereIn('employee_id', $employees->modelKeys())
            ->get()
            ->groupBy('employee_id');

        return Inertia::render('Admin/Leave/Balances', [
            'types' => $types->map(fn (LeaveType $type): array => $type->toPayload())->values()->all(),

            'rows' => $employees->map(function (Employee $employee) use ($types, $balances, $request): array {
                $own = $balances->get($employee->getKey())?->keyBy('leave_type_id');

                return [
                    'employee' => [
                        'id' => (int) $employee->getKey(),
                        'name' => $employee->user?->name ?? 'Unknown',
                        'role' => $employee->role?->name,
                        'employee_number' => $employee->employee_number,
                    ],
                    'balances' => $types->map(fn (LeaveType $type): array => [
                        'leave_type_id' => (int) $type->getKey(),
                        'balance_days' => (int) ($own?->get($type->getKey())?->balance_days ?? 0),
                    ])->values()->all(),
                    // Resolved per row on the server, so the grid does not draw an edit control
                    // on somebody the endpoint would refuse (DESIGN.md §5.11).
                    'can_edit' => Gate::forUser($request->user())->allows('manageBalances', [LeaveRequest::class, $employee]),
                ];
            })->values()->all(),
        ]);
    }

    /**
     * Set one balance, with the reason.
     */
    public function update(
        UpdateLeaveBalanceRequest $request,
        Employee $employee,
        LeaveType $leaveType,
    ): RedirectResponse {
        $employee = $this->visible($request, $employee);

        Gate::authorize('manageBalances', [LeaveRequest::class, $employee]);

        try {
            $balance = $this->leave->setBalance(
                $request->user(),
                $employee,
                $leaveType,
                $request->days(),
                $request->reason(),
            );
        } catch (LeaveStateException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', sprintf(
            '%s — %s leave set to %d %s.',
            $employee->user?->name ?? 'Employee',
            $leaveType->name,
            $balance->balance_days,
            $balance->balance_days === 1 ? 'day' : 'days',
        ));
    }

    /**
     * The employee this id names, resolved through the leave scope BEFORE the policy — so
     * somebody outside it is absent rather than refused (Part C).
     */
    private function visible(Request $request, Employee $employee): Employee
    {
        $visible = Employee::query()
            ->leaveVisibleTo($request->user())
            ->with('user')
            ->whereKey($employee->getKey())
            ->first();

        if ($visible === null) {
            throw new NotFoundHttpException;
        }

        return $visible;
    }
}
