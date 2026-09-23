<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\AttendanceStateException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\UpdateAttendanceRecordRequest;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Services\AttendanceService;
use App\Support\AttendanceStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Admin → Workforce → Attendance: today's roster, and the correction.
 *
 * ## The roster is the morning question
 *
 * "Was Yaseen in today, and on time." One row per tracked employee, each with the day's status
 * as a word, the times, and — for a remote-timer employee — the minutes the timer recorded.
 * There is no ranking on this screen, no total to compare people by and no score (Part H §1);
 * the summary line counts how many of four people are in, which is what the Admin opened it
 * for.
 *
 * Tapu is not on it as Absent and never can be: `AttendanceService::dayFor()` derives Remote
 * from `tracking_mode`, and `hq:mark-absent` only ever considers `office_attendance`
 * employees. His **tracked minutes** are the seam — `AttendanceService::trackedMinutes()`
 * returns null until `time_entries` exists, and the row prints the clause only when it is not
 * null, so today he reads *"Remote"* and afterwards *"Remote — 2h 10m tracked"* with no change
 * to this file or to the screen.
 *
 * ## The correction, and why it is an upsert
 *
 * `PUT /admin/attendance/{employee}/{date}` writes the day whether or not a row exists.
 * Marking a Half Day on a day nobody clocked into and correcting one the 23:55 sweep wrote are
 * the same act to the Admin doing it, and two endpoints would have been two places for the
 * audit row to be forgotten. The reason is required by the Form Request and the audit row
 * carries old and new (`AttendanceService::edit()`).
 *
 * The month grid of one employee is **not** here: it is `GET /attendance/{employee}`, the
 * shared page, because an Admin reading somebody's month and that person reading their own
 * are the same screen and the same query, differing only in whether the edit control is drawn.
 * The roster links each row to it.
 *
 * ## 403 and 404
 *
 * Both routes are behind `surface:admin`, so every other role is refused there — 403, about
 * who is asking. The 404 is the record rule: `{employee}` is re-resolved through
 * `Employee::attendanceVisibleTo()`, so an employee outside the requester's scope is ABSENT.
 * An Admin sees everybody, so in practice that 404 is an unknown id — but it is coded rather
 * than argued, because the day somebody widens this surface is not the day to find out it was
 * only true by accident.
 */
class AttendanceController extends Controller
{
    public function __construct(private readonly AttendanceService $attendance) {}

    /**
     * Today's roster. `?date=YYYY-MM-DD` to read another day, because which day you are
     * looking at is shareable and belongs in the URL (DESIGN.md §5.10).
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', AttendanceRecord::class);

        $date = $this->date($request);
        $rows = $this->attendance->roster($request->user(), $date);

        return Inertia::render('Admin/Attendance/Index', [
            'date' => [
                'value' => $date->toDateString(),
                'label' => $date->isoFormat('dddd, D MMMM YYYY'),
                'previous' => $date->copy()->subDay()->toDateString(),
                'next' => $date->copy()->addDay()->toDateString(),
                'today' => Carbon::today()->toDateString(),
            ],
            'rows' => $rows->all(),
            // Counts of people per status, in Part D §8's order. Counts only.
            'summary' => array_values(array_filter(
                $this->attendance->rosterSummary($rows),
                fn (array $row): bool => $row['count'] > 0,
            )),
            // The four statuses an edit may write, sent as data so the dialog's options and
            // the request's `Rule::in` are one list (AttendanceStatus::editable()).
            'statuses' => array_map(fn (AttendanceStatus $status): array => [
                'value' => $status->value,
                'label' => $status->label(),
                'tone' => $status->tone(),
            ], AttendanceStatus::editable()),
        ]);
    }

    /**
     * Correct one day, with a reason. Audit-logged with old and new by the service.
     */
    public function update(UpdateAttendanceRecordRequest $request, Employee $employee, string $date): RedirectResponse
    {
        $subject = $this->visible($request, $employee);

        Gate::authorize('update', [AttendanceRecord::class, $subject]);

        try {
            $day = $this->parseDate($date);
        } catch (\Throwable) {
            throw new NotFoundHttpException;
        }

        try {
            $record = $this->attendance->edit($subject, $day, $request->attendanceAttributes(), $request->user());
        } catch (AttendanceStateException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', sprintf(
            '%s — %s is now %s.',
            $day->isoFormat('D MMM YYYY'),
            $subject->user?->name ?? 'That employee',
            $record->status->label(),
        ));
    }

    private function visible(Request $request, Employee $employee): Employee
    {
        $visible = Employee::query()
            ->attendanceVisibleTo($request->user())
            ->with('user')
            ->whereKey($employee->getKey())
            ->first();

        if ($visible === null) {
            throw new NotFoundHttpException;
        }

        return $visible;
    }

    /**
     * The roster's day. An unparseable value falls back to today rather than throwing — a
     * mistyped link should show this morning's roster.
     */
    private function date(Request $request): Carbon
    {
        $value = trim((string) $request->query('date', ''));

        if ($value === '') {
            return Carbon::today();
        }

        try {
            return $this->parseDate($value);
        } catch (\Throwable) {
            return Carbon::today();
        }
    }

    /**
     * A `Y-m-d` path segment or query value. Strict, because the edit route's `{date}` IS the
     * row's identity — half of `unique(employee_id, date)` — and a lenient parse there would
     * let `2026-09-31` silently become the first of October on somebody's pay record.
     */
    private function parseDate(string $value): Carbon
    {
        $date = Carbon::createFromFormat('Y-m-d', $value)->startOfDay();

        // Carbon rolls a bad day over rather than refusing it, so `2026-09-31` parses as the
        // first of October. The round trip is what makes this strict.
        if ($date->toDateString() !== $value) {
            throw new \InvalidArgumentException("Not a date: {$value}");
        }

        return $date;
    }
}
