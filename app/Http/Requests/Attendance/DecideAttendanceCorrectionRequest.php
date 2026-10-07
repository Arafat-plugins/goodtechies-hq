<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Polish 029: an Admin approves or declines a correction request, with an optional note that
 * the employee reads on a decline. Who may decide is `AttendanceRecordPolicy::update`, asked by
 * the controller once the request's employee is resolved within the reader's scope.
 */
class DecideAttendanceCorrectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function note(): ?string
    {
        $note = $this->validated('note');

        return is_string($note) ? $note : null;
    }
}
