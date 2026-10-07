<?php

namespace App\Http\Controllers\Shared;

use App\Exceptions\AttendanceCorrectionException;
use App\Exceptions\AttendanceStateException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\DecideAttendanceCorrectionRequest;
use App\Http\Requests\Attendance\StoreAttendanceCorrectionRequest;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Services\AttendanceCorrectionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Polish 029: asking for a day to be corrected, and answering.
 *
 * Shared, like the clock: an employee asks from their own attendance month, and whoever
 * corrects attendance answers from the roster or that employee's month. Asking is always about
 * your own day (no employee in the URL); answering resolves the request's employee through
 * `Employee::attendanceVisibleTo()` (404 outside it) and then asks `update` (403).
 */
class AttendanceCorrectionController extends Controller
{
    public function __construct(private readonly AttendanceCorrectionService $corrections) {}

    public function store(StoreAttendanceCorrectionRequest $request): RedirectResponse
    {
        $employee = $request->user()?->employee;

        if ($employee === null) {
            throw new NotFoundHttpException;
        }

        Gate::authorize('requestCorrection', [AttendanceRecord::class, $employee]);

        try {
            $this->corrections->request($employee, $request->day(), (string) $request->validated('reason'), $request->user());
        } catch (AttendanceCorrectionException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', sprintf(
            'Sent. An Admin will review %s.',
            $request->day()->isoFormat('D MMM'),
        ));
    }

    public function approve(DecideAttendanceCorrectionRequest $request, AttendanceCorrection $correction): RedirectResponse
    {
        $this->authorizeDecision($request, $correction);

        try {
            $this->corrections->approve($correction, $request->user(), $request->note());
        } catch (AttendanceCorrectionException|AttendanceStateException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', sprintf(
            'Approved — %s, %s is now Present.',
            $correction->employee?->user?->name ?? 'that employee',
            $correction->date->isoFormat('D MMM'),
        ));
    }

    public function reject(DecideAttendanceCorrectionRequest $request, AttendanceCorrection $correction): RedirectResponse
    {
        $this->authorizeDecision($request, $correction);

        try {
            $this->corrections->reject($correction, $request->user(), $request->note());
        } catch (AttendanceCorrectionException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Declined. The day stays as it was.');
    }

    private function authorizeDecision(Request $request, AttendanceCorrection $correction): void
    {
        $employee = Employee::query()
            ->attendanceVisibleTo($request->user())
            ->with('user')
            ->whereKey($correction->employee_id)
            ->first();

        if ($employee === null) {
            throw new NotFoundHttpException;
        }

        Gate::authorize('update', [AttendanceRecord::class, $employee]);

        $correction->setRelation('employee', $employee);
    }
}
