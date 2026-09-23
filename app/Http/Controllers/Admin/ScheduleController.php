<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\UpdateScheduleRequest;
use App\Models\Employee;
use App\Models\Schedule;
use App\Services\ScheduleService;
use App\Support\Weekday;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Admin → Workforce → Work Schedule: the per-employee schedule editor.
 *
 * The `schedules` table is Phase 0's and it is seeded; this is the screen that makes it
 * editable, which is what stops the working week from being a constant anywhere else. Every
 * rule that reads it — Off Day, Late, and which days `hq:mark-absent` marks — is stated once
 * in `AttendanceService` and simply follows whatever is saved here.
 *
 * Writes go through `ScheduleService::save()`, which audit-logs the change with old and new
 * values: a start time moved half an hour earlier quietly rewrites a fortnight of Present days
 * as Late ones, and Phase 9 reads those days.
 *
 * The screen does not offer `tracking_mode`. `office_or_remote` says where the work happens;
 * `tracking_mode` says how it is measured and decides who clocks in at all, and letting this
 * editor write one from the other would put a second statement of that rule in the app.
 */
class ScheduleController extends Controller
{
    public function __construct(private readonly ScheduleService $schedules) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Schedule::class);

        return Inertia::render('Admin/Schedules/Index', [
            'rows' => $this->schedules->forEditor($request->user())
                ->map(fn (Employee $employee): array => $this->schedules->rowFor($employee))
                ->values()
                ->all(),

            // The seven keys, in week order, with their labels. Data rather than a list in the
            // template, so the editor's checkboxes and `UpdateScheduleRequest`'s `Rule::in`
            // read the same enum (App\Support\Weekday).
            'weekdays' => array_map(fn (Weekday $day): array => [
                'value' => $day->value,
                'label' => $day->label(),
                'short' => $day->shortLabel(),
            ], Weekday::cases()),
        ]);
    }

    public function update(UpdateScheduleRequest $request, Employee $employee): RedirectResponse
    {
        $subject = $this->visible($request, $employee);

        // Class-first, because the subject of the rule is the EMPLOYEE and not the schedule
        // row: an employee with no schedule yet is exactly the case this screen exists for, so
        // an ability that needed a `Schedule` instance could not authorise creating one.
        Gate::authorize('update', [Schedule::class, $subject]);

        $this->schedules->save($subject, $request->scheduleAttributes(), $request->user());

        return back()->with('success', sprintf(
            'Work schedule saved for %s.',
            $subject->user?->name ?? 'that employee',
        ));
    }

    /**
     * The employee, or 404 — the same visibility scope the roster and the month page use, so
     * an employee outside the requester's scope is ABSENT here too (Part C).
     */
    private function visible(Request $request, Employee $employee): Employee
    {
        $visible = Employee::query()
            ->attendanceVisibleTo($request->user())
            ->with(['user', 'schedule'])
            ->whereKey($employee->getKey())
            ->first();

        if ($visible === null) {
            throw new NotFoundHttpException;
        }

        return $visible;
    }
}
