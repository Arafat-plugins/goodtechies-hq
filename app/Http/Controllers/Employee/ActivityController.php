<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Concerns\BuildsActivityDay;
use App\Http\Controllers\Concerns\BuildsTimerState;
use App\Http\Controllers\Controller;
use App\Models\TimeEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Employee → Time → My activity: one day of the person's own timer sessions, minute by minute,
 * and the website domains the time was spent on. Remote timer users only, the same gate as the
 * Time page (`TimeEntryPolicy::viewAny`).
 */
class ActivityController extends Controller
{
    use BuildsActivityDay;
    use BuildsTimerState;

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', TimeEntry::class);

        $employee = $request->user()?->employee;

        abort_if($employee === null, 403);

        $employee->loadMissing('user:id,name');

        return Inertia::render('Employee/Time/Activity', [
            'activity' => $this->activityDay($employee, $this->activityDate($request)),
            'timer' => $this->timerState($request, $employee),
        ]);
    }
}
