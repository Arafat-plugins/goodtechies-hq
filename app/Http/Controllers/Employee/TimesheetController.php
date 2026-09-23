<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Concerns\BuildsTimerState;
use App\Http\Controllers\Concerns\BuildsTimesheet;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\TimeEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Response;

/**
 * The employee's own weekly timesheet (master prompt Part D §7, Phase 4).
 *
 * The grid the plan asks for: rows are the tasks worked on, columns are the days of **this
 * employee's** week, cells are tracked hours, with row, day and week totals and the target line.
 * Tapu opens it on a Thursday to see whether he is near his 25 hours.
 *
 * It is a **view over `time_entries`** and adds no data source — no table, no cache, no stored
 * week total. Every figure is summed by `TimesheetService` from the rows the week holds, which
 * is what makes this page and the Time page incapable of disagreeing about an afternoon.
 *
 * ## 403 and 404
 *
 * Being unable to use the timer at all is a fact about the requester, so an office employee, a
 * Manager and an Accountant are refused here with a **403** — `TimeEntryPolicy::viewAny`, the
 * same gate the Time page has, and the same shape as every other timer route.
 *
 * `{employee?}` is what makes Part C's record rule real: no parameter means yours, and a
 * parameter is resolved through `Employee::attendanceVisibleTo()`, so Tapu asking for a
 * colleague's week gets **404** and never learns whether the id existed. Nobody on this surface
 * holds `attendance.manage_others`, so in practice the only id that answers here is their own —
 * which is the rule, coded rather than argued.
 */
class TimesheetController extends Controller
{
    use BuildsTimerState;
    use BuildsTimesheet;

    public function index(Request $request, ?Employee $employee = null): Response
    {
        Gate::authorize('viewAny', TimeEntry::class);

        $subject = $this->visibleEmployee($request, $employee);

        return $this->renderTimesheet($request, 'Employee/Timesheet/Index', $subject, [
            // The picker's options for the Add-time dialog: the tasks this person may time
            // against, scoped by `Task::visibleTo()` exactly as the Time page scopes them, so a
            // cell cannot offer a task the endpoint would refuse.
            'tasks' => $this->timeableTasks($request),
        ]);
    }
}
