<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\BuildsTimesheet;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Support\TrackingMode;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * Admin → Workforce → Timesheet: anybody's week (master prompt Part D §7, Phase 4).
 *
 * The same grid the employee reads of their own week, because it is the same question and the
 * same query — *what was worked on, on which day, and does it add up to the target.* The
 * payload is `BuildsTimesheet`'s, so an Admin and the person whose week it is can never be
 * shown two different totals.
 *
 * It is a **view over `time_entries`**: no table, no cache, no stored week total.
 *
 * ## What it is not
 *
 * It is not an approval queue and it signs nothing off — that is Admin → Workforce → Time. It
 * is not a comparison between people either: one employee at a time, chosen by name, with no
 * league table and no rate (Part H §1). The only number anything is measured against is the
 * target the employee's own schedule sets.
 *
 * ## 403 and 404
 *
 * The route is behind `surface:admin` and `can:attendance.manage_others`, so every other role
 * is refused before a record is looked up — 403, about who is asking. The 404 is the record
 * rule: `{employee}` is re-resolved through `Employee::attendanceVisibleTo()`, so an employee
 * outside the requester's scope, and an unknown id, are ABSENT. An Admin sees everybody, so in
 * practice that 404 is an unknown id — coded rather than argued, because the day somebody
 * widens this surface is not the day to find out it was only true by accident.
 */
class TimesheetController extends Controller
{
    use BuildsTimesheet;

    public function index(Request $request, ?Employee $employee = null): Response
    {
        $choices = $this->choices($request);
        $subject = $this->visibleEmployee($request, $employee ?? $this->landOn($choices));

        return $this->renderTimesheet($request, 'Admin/Timesheet/Index', $subject, [
            // Whose weeks can be read from here, as data, so the picker's options and the
            // scope this controller resolves against are one list. The tracking mode rides
            // along because it is the answer to "why is this week empty" for somebody whose
            // day is recorded by the office clock — a question the screen should not make an
            // Admin guess at.
            'employees' => $choices
                ->map(fn (Employee $row): array => [
                    'id' => $row->getKey(),
                    'name' => $row->user?->name ?? 'Unknown',
                    'tracking_mode' => $row->tracking_mode->value,
                ])
                ->values()
                ->all(),
        ]);
    }

    /**
     * Whose timesheets this Admin may read: every tracked employee in their scope, by name.
     *
     * Not filtered to the timer, deliberately. A timesheet is a view over `time_entries`, so an
     * office employee's is simply empty — and empty is an answer the Admin can read, whereas a
     * name missing from the picker is a question they cannot ask.
     *
     * @return Collection<int, Employee>
     */
    private function choices(Request $request): Collection
    {
        return Employee::query()
            ->attendanceVisibleTo($request->user())
            ->tracked()
            ->with('user')
            ->join('users', 'users.id', '=', 'employees.user_id')
            ->orderBy('users.name')
            ->select('employees.*')
            ->get();
    }

    /**
     * Which week opens when the URL names nobody.
     *
     * The first timer-tracked name, alphabetically — the people this screen exists for — and
     * failing that the first tracked name. Alphabetical and not "whoever has the most hours":
     * a default chosen by a total is a ranking with one row showing, which Part H forbids.
     *
     * The scope always contains the requester themselves (`attendance.view_own` is held by
     * every role), so the last fallback is a real record rather than a null.
     *
     * @param  Collection<int, Employee>  $choices
     */
    private function landOn(Collection $choices): ?Employee
    {
        return $choices->first(
            fn (Employee $row): bool => $row->tracking_mode === TrackingMode::RemoteTimer,
        ) ?? $choices->first();
    }
}
