<?php

namespace App\Models;

use App\Exceptions\LeaveStateException;
use App\Support\LeaveStatus;
use App\Support\Permission;
use App\Support\RoleName;
use Carbon\CarbonInterface;
use Database\Factories\LeaveRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One request for leave: who, which type, which days, and what became of it.
 *
 * ## The status is guarded at the model — decision 2-9, a fourth time
 *
 * `leave_requests.status` is writable on a row that does not exist yet — a request is born
 * pending — and after that ONLY through `applyTransition()`, which re-checks
 * `LeaveStatus::TRANSITIONS` itself. Anything else that leaves `status` dirty on an existing
 * row throws.
 *
 * This is the same shape `Task` has had since Phase 2, and it is here for the same reason,
 * sharpened by what approval actually does. An approval is not a word changing colour on a
 * screen: it decrements a balance, writes `attendance_records` rows for every working day in
 * the window and stores the `unpaid_days` Phase 9 will pay from. A second write path —
 * a future job, a console one-liner, a controller filling `status` from request input, a bulk
 * `update(['status' => 'approved'])` over a filtered queue — would produce a request that says
 * *approved* with none of that behind it. Nobody would notice until a payslip was wrong.
 *
 * So the single path is structural rather than conventional. `LeaveService` is the only caller
 * of `applyTransition()`, and every side effect lives beside that call in one transaction.
 * There is deliberately **no `withoutStatusGuard()`** — `Task` has one for the demo seeder,
 * which paints a board showing every status at once; the leave seeder makes its requests by
 * going through the service, so no such escape hatch exists here and none should be added.
 *
 * ## The overlap rule lives in the database
 *
 * `leave_requests_no_overlap` is a GiST EXCLUDE constraint over (employee, date range) for the
 * pending and approved statuses. `LeaveService` checks first so that a person gets a sentence
 * rather than a constraint violation, but the promise is the constraint — see the migration.
 *
 * @property int $id
 * @property int $employee_id
 * @property int $leave_type_id
 * @property Carbon $start_date
 * @property Carbon $end_date
 * @property int $days
 * @property int $unpaid_days
 * @property string $reason
 * @property LeaveStatus $status
 * @property int|null $approver_id
 * @property Carbon|null $decided_at
 * @property string|null $decision_note
 */
#[Fillable([
    'employee_id',
    'leave_type_id',
    'start_date',
    'end_date',
    'days',
    'unpaid_days',
    'reason',
])]
class LeaveRequest extends Model
{
    /** @use HasFactory<LeaveRequestFactory> */
    use HasFactory;

    /**
     * This instance is inside `applyTransition()`, the one sanctioned status write.
     *
     * Per instance rather than static, so a nested save of some other request cannot inherit
     * this one's permission to move.
     */
    private bool $transitioning = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'days' => 'integer',
            'unpaid_days' => 'integer',
            'status' => LeaveStatus::class,
            'decided_at' => 'datetime',
        ];
    }

    /**
     * The status guard. See the class docblock.
     */
    protected static function booted(): void
    {
        static::saving(function (self $request): void {
            if (! $request->exists || ! $request->isDirty('status')) {
                return;
            }

            if ($request->transitioning) {
                return;
            }

            throw LeaveStateException::statusWrittenOutsideTheMachine();
        });

        static::saved(function (self $request): void {
            $request->transitioning = false;
        });
    }

    /**
     * Move this request's status. The only door in the guard above.
     *
     * It checks the map and nothing else: who may make the move is `LeaveRequestPolicy`'s
     * answer and `LeaveService` asks for it before calling this, and the side effects are the
     * service's, in the same transaction.
     *
     * @throws LeaveStateException
     */
    public function applyTransition(LeaveStatus $to): static
    {
        $from = $this->status;

        if ($from === null || ! $from->canTransitionTo($to)) {
            throw LeaveStateException::transition($from, $to);
        }

        $this->transitioning = true;
        $this->status = $to;

        return $this;
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<LeaveType, $this>
     */
    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * The requests this user may see at all.
     *
     * The same three cases `Employee::attendanceVisibleTo()` draws, asked of this table — and
     * asked as a SCOPE rather than as a policy call, because that is what makes somebody else's
     * request **absent** instead of refused. A list narrowed by it does not contain them and a
     * lookup by id comes back empty, so the controller *has* a 404 rather than deciding one
     * (Part C). That is the whole of "the Accountant can reach nobody else's request".
     *
     * No role is named for the first two cases. `leave.approve` is the key, and its holder's
     * scope is the one `Employee::attendanceVisibleTo()` already states: everybody for an
     * Admin, their own team for anyone else holding it.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeVisibleTo(Builder $query, ?User $user): void
    {
        if ($user === null || ! $user->isActive()) {
            $query->whereRaw('1 = 0');

            return;
        }

        $own = $user->employee?->getKey();

        if (! $user->hasPermission(Permission::LeaveApprove)) {
            // Everyone holds `leave.apply` on the seed — Part C §1 gives that cell to every
            // role, the Accountant included — but it is still asked: a role that lost it must
            // lose the page rather than keep it because everybody else has it.
            $query->where(
                'leave_requests.employee_id',
                $user->hasPermission(Permission::LeaveApply) ? $own : null,
            );

            return;
        }

        if ($user->hasRole(RoleName::ADMIN)) {
            return;
        }

        // Part C's 🟡 "own team" cell, which is the dormant MANAGER role in the MVP — by seed,
        // not by this file naming them. Their own request is in scope too: a Manager who could
        // not see their own leave could not apply for any.
        $query->whereIn('leave_requests.employee_id', function (QueryBuilder $employees) use ($own): void {
            $employees->select('id')
                ->from('employees')
                ->where('manager_id', $own)
                ->orWhere('id', $own);
        });
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeForEmployee(Builder $query, Employee|int $employee): void
    {
        $query->where(
            'leave_requests.employee_id',
            $employee instanceof Employee ? $employee->getKey() : $employee,
        );
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeApproved(Builder $query): void
    {
        $query->where('leave_requests.status', LeaveStatus::Approved->value);
    }

    /**
     * Pending and approved — the statuses that hold a place in the calendar, and exactly the
     * set the exclusion constraint is written against (`LeaveStatus::holding()`).
     *
     * @param  Builder<$this>  $query
     */
    public function scopeHolding(Builder $query): void
    {
        $query->whereIn('leave_requests.status', LeaveStatus::holdingValues());
    }

    /**
     * Requests a decision is still owed on. The queue's default view.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeAwaitingDecision(Builder $query): void
    {
        $query->whereIn('leave_requests.status', [
            LeaveStatus::Pending->value,
            LeaveStatus::CorrectionRequested->value,
        ]);
    }

    /**
     * Requests whose window contains this date.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeCovering(Builder $query, CarbonInterface $date): void
    {
        $day = Carbon::parse($date)->toDateString();

        $query->whereDate('leave_requests.start_date', '<=', $day)
            ->whereDate('leave_requests.end_date', '>=', $day);
    }

    /**
     * Requests whose window OVERLAPS a range — the interval test, not a `whereBetween` on one
     * end of it.
     *
     * The same reasoning as decision 2-16 for the task calendar: a request from 28 Sep to 3 Oct
     * is leave at both ends of the month boundary and belongs on both grids. `whereBetween` on
     * `start_date` would drop it from October entirely.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeOverlapping(Builder $query, CarbonInterface $from, CarbonInterface $to): void
    {
        $query->whereDate('leave_requests.start_date', '<=', Carbon::parse($to)->toDateString())
            ->whereDate('leave_requests.end_date', '>=', Carbon::parse($from)->toDateString());
    }

    /*
    |--------------------------------------------------------------------------
    | The two seams other features read this table through
    |--------------------------------------------------------------------------
    */

    /**
     * Is this employee on approved leave on this date? **The absent sweep's half of the Phase 5
     * seam** (decision 4-12).
     *
     * A static on the model rather than a method on `LeaveService`, and deliberately: the
     * caller is `AttendanceService::coveredByLeaveOrHoliday()`, and `LeaveService` already
     * depends on `AttendanceService` for the working-day predicate. Asking the model keeps that
     * dependency pointing one way instead of making two services constructor-inject each other.
     *
     * One indexed query per employee per date. `markAbsent()` asks it once for each office
     * employee it considers, which is four rows today and would be a hundred on a bad day —
     * well inside what the `(start_date, end_date)` index answers, and the shape the seam was
     * designed for rather than a `whereNotExists` buried in the sweep's own query.
     */
    public static function coversDate(Employee $employee, CarbonInterface $date): bool
    {
        return static::query()
            ->forEmployee($employee)
            ->approved()
            ->covering($date)
            ->exists();
    }

    /**
     * The "assignee on leave" flag, as a correlated subquery — **Part D §5's flag, and never a
     * reassignment**.
     *
     * Part D §9 asks for *"assignee on leave flags on task list/dashboard for tasks due in an
     * approved window"*. This is that sentence in SQL: for the task in the outer query, every
     * approved leave request belonging to one of its assignees whose window contains the task's
     * own `due_date`.
     *
     * ## Why a subquery and not a second pass
     *
     * `TaskService::query()` is the single funnel for the List, the Board, the Calendar, My
     * Tasks and the dashboards. Hanging the flag off it means every one of those screens grows
     * it with no controller change and, crucially, **no extra round trip** — a board of two
     * hundred cards costs the same one query it did before. A collection walked afterwards
     * would have had to be walked in five places, and the two that were forgotten would be the
     * two an Admin actually looks at.
     *
     * It answers a `json` array of `{name, until}` — the names, because a flag that says
     * *somebody* is away is a flag nobody can act on, and the date, because what an Admin does
     * about it depends on whether they are back on Thursday or in three weeks. `null` when
     * nothing matches, which `TaskResource` reads as an empty list.
     *
     * ## Who gets to see it
     *
     * `$scopeToEmployeeId` is how Part C's privacy rule reaches this. A holder of
     * `leave.approve` is passed null and sees every assignee's flag, because acting on it is
     * their job. Everybody else is passed their own employee id, so the only flag they can ever
     * see is their own — *"you are on leave when this is due"*, which is useful, and nothing
     * about a colleague. The decision is made once, on the server, by
     * `LeaveRequest::taskFlagScopeFor()`, and never in Vue (decisions 2-28, 2-31).
     */
    public static function taskFlagQuery(?int $scopeToEmployeeId): QueryBuilder
    {
        $query = DB::table('leave_requests')
            ->join('task_assignees', 'task_assignees.employee_id', '=', 'leave_requests.employee_id')
            ->join('employees', 'employees.id', '=', 'leave_requests.employee_id')
            ->join('users', 'users.id', '=', 'employees.user_id')
            ->selectRaw("json_agg(json_build_object('name', users.name, 'until', leave_requests.end_date) ORDER BY users.name)")
            ->whereColumn('task_assignees.task_id', 'tasks.id')
            ->where('leave_requests.status', LeaveStatus::Approved->value)
            ->whereColumn('leave_requests.start_date', '<=', 'tasks.due_date')
            ->whereColumn('leave_requests.end_date', '>=', 'tasks.due_date');

        if ($scopeToEmployeeId !== null) {
            $query->where('leave_requests.employee_id', $scopeToEmployeeId);
        }

        return $query;
    }

    /**
     * Which employee's leave this viewer may be shown on a task card: null for everybody's.
     *
     * One statement of the rule, called by `TaskService::query()`, so the flag's privacy and
     * `LeaveRequest::visibleTo()`'s cannot drift apart.
     */
    public static function taskFlagScopeFor(?User $user): ?int
    {
        if ($user !== null && $user->isActive() && $user->hasPermission(Permission::LeaveApprove)) {
            return null;
        }

        // A user with no employee row has no leave of their own either, so -1 is "match
        // nothing" rather than "match everything" — the same fail-closed shape
        // `TimeEntry::visibleTo()` uses when it meets one.
        return (int) ($user?->employee?->getKey() ?? -1);
    }

    /*
    |--------------------------------------------------------------------------
    | Reads
    |--------------------------------------------------------------------------
    */

    /**
     * Every date in the window, inclusive. The days a screen paints, before the schedule is
     * consulted — `LeaveService::leaveDays()` is what narrows it to working days.
     *
     * @return list<Carbon>
     */
    public function dates(): array
    {
        $dates = [];

        for (
            $day = $this->start_date->copy()->startOfDay();
            $day->lessThanOrEqualTo($this->end_date);
            $day->addDay()
        ) {
            $dates[] = $day->copy();
        }

        return $dates;
    }

    public function isOneDay(): bool
    {
        return $this->start_date->isSameDay($this->end_date);
    }

    /**
     * The values an audit row records for a request.
     *
     * One shape for both halves of a decision, so `leave.approved` and `leave.rejected` carry
     * the same keys before and after and a reader diffs them by eye — the property
     * `AttendanceRecord::auditValues()` established. The dates and the day counts are in it
     * because the whole point of the record is *what was granted*, not just that something was.
     *
     * @return array<string, mixed>
     */
    public function auditValues(): array
    {
        return [
            'employee_id' => (int) $this->employee_id,
            'leave_type' => $this->leaveType?->name,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'days' => (int) $this->days,
            'unpaid_days' => (int) $this->unpaid_days,
            'status' => $this->status?->value,
            'approver_id' => $this->approver_id === null ? null : (int) $this->approver_id,
            'decided_at' => $this->decided_at?->toIso8601String(),
            'decision_note' => $this->decision_note,
        ];
    }
}
