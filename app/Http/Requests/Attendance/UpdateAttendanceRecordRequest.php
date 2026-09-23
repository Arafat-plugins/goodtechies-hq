<?php

namespace App\Http\Requests\Attendance;

use App\Support\AttendanceStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An Admin's correction to one day.
 *
 * ## The reason is required, and it is a rule and not a convention
 *
 * `note` is `required` here and nowhere else — a clock-in writes none. An attendance record is
 * what Phase 9 pays somebody from, so a change to one without a sentence saying why is the
 * thing the audit log exists to prevent, and making it a validation rule means there is no
 * code path that can skip it. The same text is stored in the row's `note` (Part D §20 gives
 * this table one free-text column) and carried into the `attendance.edited` audit row.
 *
 * ## Only four statuses
 *
 * `AttendanceStatus::editable()` — Present, Late, Half day, Absent. Leave and Holiday are
 * owned by their own records in Phase 5, so setting one here would produce an attendance day
 * that no leave request explains; Off Day and Remote are derived and have no column to be
 * written to at all (the table's CHECK constraint refuses them outright). The enum is asked
 * for the list rather than four strings being typed here.
 *
 * Authorization is not here — it is `AttendanceRecordPolicy::update`, asked in the controller
 * against the employee the route resolved.
 */
class UpdateAttendanceRecordRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(AttendanceStatus::editableValues())],

            // `H:i`, the two controls the dialog actually has. Both nullable, because Absent is
            // a day with no times on it and a correction to Absent has to be able to clear
            // them; the service refuses a clock-out earlier than its clock-in, and the table
            // refuses it again.
            'clock_in' => ['nullable', 'date_format:H:i'],
            'clock_out' => ['nullable', 'date_format:H:i'],

            'note' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'note' => 'reason',
            'clock_in' => 'clock in',
            'clock_out' => 'clock out',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'note.required' => 'Say why you are changing this day. It is recorded in the audit log.',
        ];
    }

    /**
     * @return array{status: AttendanceStatus, clock_in: ?string, clock_out: ?string, note: string}
     */
    public function attendanceAttributes(): array
    {
        $validated = $this->validated();

        return [
            'status' => AttendanceStatus::from($validated['status']),
            'clock_in' => $validated['clock_in'] ?? null,
            'clock_out' => $validated['clock_out'] ?? null,
            'note' => trim($validated['note']),
        ];
    }
}
