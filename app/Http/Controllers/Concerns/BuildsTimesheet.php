<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Employee;
use App\Models\TimeEntry;
use App\Services\SettingsService;
use App\Services\TimesheetService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The one shape a week of timesheet is answered in.
 *
 * Two surfaces read the same grid — the employee's own week and an Admin's view of anybody's —
 * and they differ in exactly two things: which employee is the subject, and whether the
 * *Add time* control is drawn. Everything else is one payload built here, for the reason
 * `BuildsTimerState` is one payload: two assemblies of the same numbers would eventually be two
 * ideas of what a week totalled.
 *
 * **Nothing in here decides what anybody may do.** The subject is resolved through a visibility
 * SCOPE, so an employee outside it is absent rather than refused (404, Part C); and
 * `permissions.can_add_time` is `TimeEntryPolicy::create` resolved on the server for this
 * subject, never re-derived in Vue from a role (decisions 2-28, 2-31).
 */
trait BuildsTimesheet
{
    /**
     * Render one employee's week.
     *
     * @param  array<string, mixed>  $extra
     */
    private function renderTimesheet(
        Request $request,
        string $page,
        Employee $subject,
        array $extra = [],
    ): Response {
        $timesheet = app(TimesheetService::class);
        $settings = app(SettingsService::class);

        $isSelf = $request->user()?->employee?->getKey() === $subject->getKey();

        return Inertia::render($page, [
            'subject' => [
                'id' => $subject->getKey(),
                'name' => $subject->user?->name ?? 'Unknown',
                'employee_number' => $subject->employee_number,
                'is_self' => $isSelf,
                'tracking_mode' => $subject->tracking_mode->value,
            ],

            ...$timesheet->week($subject, $this->timesheetWeek($request)),

            // What a manual entry will do once it is saved, in the dialog's own words. Read
            // from settings here rather than assumed in Vue, exactly as the Time page reads it.
            'manual_time_requires_approval' => (bool) $settings->get('manual_time_requires_approval'),

            'permissions' => [
                // Adding a stretch of time by hand is the timer's fallback and belongs to the
                // person whose week it is: the write lives on the Employee surface, so an Admin
                // reading somebody else's week gets the grid and no *Add time* control rather
                // than a button the server would refuse.
                'can_add_time' => $isSelf && Gate::forUser($request->user())->allows('create', TimeEntry::class),
            ],

            ...$extra,
        ]);
    }

    /**
     * The week being shown. `?week=YYYY-MM-DD` — any day in it, because which week you are
     * looking at is shareable and belongs in the URL (DESIGN.md §5.10).
     *
     * The value is a day and not a week number: `TimesheetService` decides where the week
     * starts from the employee's own schedule, so a caller that had to name the Monday would be
     * naming a day that is not the start of anybody's week here.
     *
     * Anything unparseable falls back to today rather than throwing — a stale bookmark should
     * show this week, not an error page.
     */
    private function timesheetWeek(Request $request): Carbon
    {
        $value = trim((string) $request->query('week', ''));

        if ($value === '') {
            return Carbon::today();
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            return Carbon::today();
        }
    }

    /**
     * The employee whose week this is, or 404.
     *
     * No parameter means "mine". A parameter means that employee, re-resolved through
     * `Employee::attendanceVisibleTo()` — the ONE statement of whose working record this viewer
     * may read (decision 2-37) — so somebody outside the scope is not found rather than
     * refused, and the reader never learns whether the id existed (Part C).
     */
    private function visibleEmployee(Request $request, ?Employee $employee): Employee
    {
        $subject = $employee ?? $request->user()?->employee;

        if ($subject === null) {
            throw new NotFoundHttpException;
        }

        $visible = Employee::query()
            ->attendanceVisibleTo($request->user())
            ->with(['user', 'schedule'])
            ->whereKey($subject->getKey())
            ->first();

        if ($visible === null) {
            throw new NotFoundHttpException;
        }

        return $visible;
    }
}
