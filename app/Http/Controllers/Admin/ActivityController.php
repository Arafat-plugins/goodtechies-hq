<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\BuildsActivityDay;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\TimeEntry;
use App\Services\EmployeeAdministrationService;
use App\Support\TrackingMode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Admin → Workforce → Time → Activity: one remote employee's day, minute by minute.
 *
 * The Time page's gate (`TimeEntryPolicy::review`) and the employee page's record rule
 * (`EmployeeAdministrationService::findFor()` then `EmployeePolicy::view`): somebody outside the
 * requester's scope is ABSENT (404), never refused.
 */
class ActivityController extends Controller
{
    use BuildsActivityDay;

    public function __construct(private readonly EmployeeAdministrationService $employees) {}

    public function show(Request $request, ?Employee $employee = null): Response
    {
        Gate::authorize('review', TimeEntry::class);

        $user = $request->user();

        $choices = Employee::query()
            ->attendanceVisibleTo($user)
            ->where('tracking_mode', TrackingMode::RemoteTimer)
            ->with('user:id,name')
            ->join('users', 'users.id', '=', 'employees.user_id')
            ->orderBy('users.name')
            ->select('employees.*')
            ->get();

        $subject = $this->employees->findFor($user, $employee ?? $choices->first() ?? 0);

        if ($subject === null) {
            throw new NotFoundHttpException;
        }

        Gate::authorize('view', $subject);

        return Inertia::render('Admin/Time/Activity', [
            'activity' => $this->activityDay($subject, $this->activityDate($request)),
            'employees' => $choices
                ->map(fn (Employee $row): array => [
                    'id' => (int) $row->getKey(),
                    'name' => $row->user?->name ?? 'Unknown',
                ])
                ->values()
                ->all(),
        ]);
    }
}
