<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * Polish 029: an employee asks for one of their own days to be corrected. Which days qualify is
 * the service's rule (`AttendanceCorrectionService::request()`); this checks the shape. Who may
 * ask is `AttendanceRecordPolicy::requestCorrection`, asked by the controller.
 */
class StoreAttendanceCorrectionRequest extends FormRequest
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
            'date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Say what happened, so the Admin can decide.',
        ];
    }

    public function day(): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', (string) $this->validated('date'))->startOfDay();
    }
}
