<?php

namespace App\Http\Requests\Employees;

use App\Support\TrackingMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One field: how this employee's working time is measured from now on (master prompt Part D §2 —
 * *"role/schedule/**tracking_mode**"*).
 *
 * ## All three modes are assignable, including `none`
 *
 * Unlike the role list there is nothing to withhold here. Part D §1 gives the field three values
 * — *"`employees.tracking_mode ∈ {remote_timer, office_attendance, none}` is a per-employee field,
 * **not a role rule**"* — and every one of them is a real answer that the seed already uses:
 * `office_attendance` for Yaseen and both Admins, `remote_timer` for Tapu, and **`none` for the
 * Accountant**, who has no schedule row, runs no timer and is never marked absent
 * (`AttendanceService`, `LeaveService`, `PayrollService` all read the field to say so). Refusing
 * `none` would make the Accountant's own record unreachable from the screen that owns it.
 *
 * ## It is not the schedule's `office_or_remote`, and this is not that form
 *
 * `office_or_remote` says **where** the work happens and lives on `schedules`, written only by
 * `ScheduleService` through `PUT /admin/schedules/{employee}`. `tracking_mode` says **how the
 * time is measured** and decides who clocks in at all. `ScheduleService`'s own docblock refuses
 * to write one from the other, and this request is the other half of that split.
 *
 * ## No `reason`
 *
 * Deliberately, and the comparison is `UpdateAttendanceRequest`, which requires one. That edit
 * rewrites what a day in the past already said; this changes what future days will be called.
 * Requiring a sentence for a forward-looking configuration change would put prose where
 * `audit_logs`' old and new values already carry the fact, and `schedules` — the same shape of
 * change, also audited — asks for none either.
 *
 * Authorization is `EmployeePolicy::changeTrackingMode`, asked in the controller, and
 * `EmployeeAdministrationService::guard()` refuses the call as well.
 */
class UpdateEmployeeTrackingModeRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tracking_mode' => ['required', Rule::in(array_map(
                fn (TrackingMode $mode): string => $mode->value,
                TrackingMode::cases(),
            ))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'tracking_mode' => 'tracking mode',
        ];
    }

    public function trackingMode(): TrackingMode
    {
        return TrackingMode::from($this->validated()['tracking_mode']);
    }
}
