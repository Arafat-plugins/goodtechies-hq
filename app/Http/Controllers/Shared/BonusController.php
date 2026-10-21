<?php

namespace App\Http\Controllers\Shared;

use App\Exceptions\PayrollStateException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payroll\CancelBonusRequest;
use App\Http\Requests\Payroll\StoreBonusRequest;
use App\Http\Requests\Payroll\UpdateBonusRequest;
use App\Models\Employee;
use App\Models\EmployeeBonus;
use App\Services\BonusService;
use App\Support\BonusStatus;
use App\Support\BonusType;
use App\Support\Currency;
use App\Support\UserStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Bonuses page: history with filters, and create (one or many employees), edit, approve,
 * mark paid (a bonus paid separately) and cancel. Every write goes through `BonusService`, which re-checks the policy and keeps
 * `payroll_items.bonus` in step.
 */
class BonusController extends Controller
{
    public function __construct(
        private readonly BonusService $bonuses,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', EmployeeBonus::class);

        $user = $request->user();

        $filters = [
            'employee' => $request->filled('employee') && ctype_digit((string) $request->query('employee'))
                ? (int) $request->query('employee')
                : null,
            'status' => BonusStatus::tryFrom((string) $request->query('status'))?->value,
            'type' => BonusType::tryFrom((string) $request->query('type'))?->value,
            'month' => preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $request->query('month')) === 1
                ? (string) $request->query('month')
                : null,
        ];

        $bonuses = EmployeeBonus::query()
            ->with(['employee.user', 'period', 'creator', 'approver', 'canceller'])
            ->when($filters['employee'] !== null, fn (Builder $query) => $query->where('employee_id', $filters['employee']))
            ->when($filters['status'] !== null, fn (Builder $query) => $query->where('status', $filters['status']))
            ->when($filters['type'] !== null, fn (Builder $query) => $query->where('type', $filters['type']))
            ->when($filters['month'] !== null, function (Builder $query) use ($filters): void {
                $start = Carbon::createFromFormat('Y-m-d', $filters['month'].'-01')->startOfDay();
                $query->whereBetween('payment_date', [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()]);
            })
            ->orderByDesc('payment_date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (EmployeeBonus $bonus): array => [
                'id' => (int) $bonus->getKey(),
                'employee' => [
                    'id' => (int) $bonus->employee_id,
                    'name' => (string) ($bonus->employee?->user?->name ?? 'Employee #'.$bonus->employee_id),
                ],
                'type' => $bonus->type->value,
                'type_label' => $bonus->type->label(),
                'title' => $bonus->title,
                'display_name' => $bonus->displayName(),
                'amount' => (string) $bonus->amount,
                'currency' => $bonus->currency,
                'payment_date' => $bonus->payment_date->toDateString(),
                'reason' => $bonus->reason,
                'status' => $bonus->status->value,
                'status_label' => $bonus->status->label(),
                'payout' => $bonus->payout->value,
                'payout_label' => $bonus->payout->label(),
                'paid_at' => $bonus->paid_at?->toIso8601String(),
                'period' => $bonus->period === null ? null : [
                    'id' => (int) $bonus->period->getKey(),
                    'month' => $bonus->period->label(),
                ],
                'created_by' => $bonus->creator?->name,
                'approved_by' => $bonus->approver?->name,
                'cancelled_by' => $bonus->canceller?->name,
                'cancellation_reason' => $bonus->cancellation_reason,
                'permissions' => [
                    'can_update' => Gate::forUser($user)->allows('update', $bonus),
                    'can_approve' => $bonus->status === BonusStatus::Pending && Gate::forUser($user)->allows('approve', $bonus),
                    'can_cancel' => Gate::forUser($user)->allows('cancel', $bonus),
                    'can_mark_paid' => Gate::forUser($user)->allows('markPaid', $bonus),
                ],
            ]);

        $employees = Employee::query()
            ->join('users', 'users.id', '=', 'employees.user_id')
            ->where('employees.status', UserStatus::Active->value)
            ->orderBy('users.name')
            ->get(['employees.id', 'users.name'])
            ->map(fn ($row): array => ['id' => (int) $row->id, 'name' => (string) $row->name])
            ->values()
            ->all();

        return Inertia::render('Shared/Payroll/Bonuses', [
            'bonuses' => $bonuses,
            'filters' => $filters,
            'employees' => $employees,
            'types' => BonusType::options(),
            'statuses' => BonusStatus::options(),
            'currency' => Currency::default()->value,
            'permissions' => [
                'can_create' => Gate::forUser($user)->allows('create', EmployeeBonus::class),
                'can_approve' => Gate::forUser($user)->allows('approve', EmployeeBonus::class),
            ],
            'today' => Carbon::today(config('app.timezone'))->toDateString(),
        ]);
    }

    public function store(StoreBonusRequest $request): RedirectResponse
    {
        Gate::authorize('create', EmployeeBonus::class);

        try {
            $result = $this->bonuses->create(
                $request->user(),
                array_map('intval', (array) $request->validated('employee_ids')),
                $request->safe()->except('employee_ids'),
            );
        } catch (PayrollStateException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $message = sprintf('Bonus saved for %d employee(s).', $result['created']->count());

        if ($result['skipped'] !== []) {
            $message .= sprintf(' Skipped %s: the same bonus already exists.', implode(', ', $result['skipped']));
        }

        return back()->with('success', $message);
    }

    public function update(UpdateBonusRequest $request, EmployeeBonus $bonus): RedirectResponse
    {
        Gate::authorize('update', $bonus);

        try {
            $this->bonuses->update($request->user(), $bonus, $request->validated());
        } catch (PayrollStateException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Bonus updated.');
    }

    public function approve(Request $request, EmployeeBonus $bonus): RedirectResponse
    {
        Gate::authorize('approve', $bonus);

        try {
            $this->bonuses->approve($request->user(), $bonus);
        } catch (PayrollStateException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Bonus approved.');
    }

    /**
     * Pay an approved bonus that is paid separately, today. Only holders of `payroll.approve`
     * get this far; whether this bonus can be paid here is the service's check, so a bonus paid
     * with salary is refused with a message rather than a 403.
     */
    public function markPaid(Request $request, EmployeeBonus $bonus): RedirectResponse
    {
        Gate::authorize('approve', EmployeeBonus::class);

        try {
            $this->bonuses->markPaid($request->user(), $bonus);
        } catch (PayrollStateException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Bonus marked paid.');
    }

    public function cancel(CancelBonusRequest $request, EmployeeBonus $bonus): RedirectResponse
    {
        Gate::authorize('cancel', $bonus);

        try {
            $this->bonuses->cancel($request->user(), $bonus, (string) $request->validated('reason'));
        } catch (PayrollStateException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Bonus cancelled.');
    }
}
