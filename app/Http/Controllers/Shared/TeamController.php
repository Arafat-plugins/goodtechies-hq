<?php

namespace App\Http\Controllers\Shared;

use App\Http\Controllers\Controller;
use App\Http\Resources\TeamMemberResource;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Services\AttendanceService;
use App\Support\TrackingMode;
use App\Support\UserStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Team directory (Part D §2: WORK → Team, and the Employee's Messages → Team).
 *
 * *"Directory with name, role, today's availability, DM button."* Four things, and the line
 * ends *"no salary, no tracking data"* — which is why the payload leaves through
 * `TeamMemberResource` and nothing else, and why the test on it asserts the exact key list.
 *
 * ## Shared, not one per surface
 *
 * For the reason the Messages page, the clock and My Leave are shared: who works here is a fact
 * about the agency, not about the shell somebody is looking at, and three copies of this
 * controller would have been three places for "who is on the team" to be answered differently
 * — with the Accountant's copy the one nobody tested. `Pages/Shared/Team.vue` picks its layout
 * from `auth.user.surface`, exactly as Profile does.
 *
 * ## The Accountant
 *
 * They do not reach this route: it is gated by `can:messages.use`, the key Phase 6 adds and the
 * one they do not hold, so they get **403** — the same answer, from the same key, as every
 * other messaging route. That is the plan's *"Accountant has no messaging routes"* enforced by
 * the capability rather than by the role name.
 *
 * They still APPEAR in the directory, and that is deliberate. They work here; a colleague
 * looking up who the accountant is should find them. What they do not get is a DM button
 * pointing at them — `TeamMemberResource::dmUrl()` asks the same key about the subject, so the
 * button is simply absent rather than drawn and refused.
 *
 * ## Availability is computed, once, somewhere else
 *
 * `AttendanceService::dayFor()` is the single statement of what a day is (decisions 4-9, 5-2),
 * and this asks it. There is no second definition here and there must not be one: the day the
 * roster's Off Day rule changes, this screen changes with it.
 */
class TeamController extends Controller
{
    public function __construct(private readonly AttendanceService $attendance) {}

    public function index(Request $request): Response
    {
        $today = Carbon::today();

        // Everybody who works here, in alphabetical order. Not `visibleTo` anything: a
        // directory is the one list in this application that is deliberately not scoped, and
        // it is safe to be so precisely because of what it carries — a name, a role and a
        // status word, none of which is anybody's private business. The scoped lists
        // (attendance, leave, payroll) are scoped because of what THEY carry.
        $employees = Employee::query()
            ->where('employees.status', UserStatus::Active->value)
            ->with(['user', 'role', 'schedule'])
            ->join('users', 'users.id', '=', 'employees.user_id')
            ->where('users.status', UserStatus::Active->value)
            ->orderBy('users.name')
            ->select('employees.*')
            ->get();

        // Who has a day worth reporting, asked with the scope that already states it rather
        // than restated here as a `tracking_mode` comparison. Somebody outside this set gets a
        // null day and reads *Not tracked* — see TeamMemberResource.
        $tracked = Employee::query()->tracked()->pluck('employees.id')
            ->map(fn (mixed $id): int => (int) $id)
            ->flip();

        // One query for today's holiday and one for the day's tracked minutes, before the loop
        // asks per person — the same priming `AttendanceService::roster()` does, for the same
        // reason. The minutes are computed and then thrown away by the serializer, which is
        // the correct shape: `dayFor()` answers the whole day, and this screen publishes one
        // word of it.
        $this->attendance->primeTrackedMinutes(
            $employees
                ->filter(fn (Employee $employee): bool => $employee->tracking_mode === TrackingMode::RemoteTimer)
                ->map(fn (Employee $employee): int => (int) $employee->getKey())
                ->values()
                ->all(),
            $today,
            $today,
        );

        // Today's clock-ins, in one query. Without them `dayFor()` has no record to read and
        // everybody on the office clock would read *No record yet* however early they arrived
        // — the roster makes the same call for the same reason.
        $records = AttendanceRecord::query()
            ->forDate($today)
            ->whereIn('employee_id', $employees->modelKeys())
            ->get()
            ->keyBy('employee_id');

        return Inertia::render('Shared/Team', [
            'today' => [
                'value' => $today->toDateString(),
                'label' => $today->isoFormat('dddd, D MMMM YYYY'),
            ],
            'members' => $employees
                ->map(fn (Employee $employee): TeamMemberResource => new TeamMemberResource(
                    $employee,
                    $tracked->has((int) $employee->getKey())
                        ? $this->attendance->dayFor($employee, $today, $records[$employee->getKey()] ?? null)
                        : null,
                ))
                ->map(fn (TeamMemberResource $member): array => $member->resolve($request))
                ->values()
                ->all(),
        ]);
    }
}
