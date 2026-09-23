<?php

namespace App\Http\Requests\Attendance;

use App\Support\Weekday;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One employee's work schedule.
 *
 * The seven day keys come from `Weekday::values()` and not from a literal list, for the reason
 * the enum itself gives: the working week is data, and the only fixed thing is the vocabulary.
 * A schedule with **no** working days is accepted — somebody on unpaid leave of absence has
 * one, and refusing it would push an Admin into deactivating the account instead.
 *
 * `start_time` is nullable and that nullability is load-bearing: an employee with no start
 * time cannot be late (`AttendanceService::isLate()`), which is how a flexible or remote
 * schedule is expressed. The editor says so beside the field rather than letting somebody
 * discover it from a report.
 *
 * Authorization is `SchedulePolicy::update`, asked in the controller.
 */
class UpdateScheduleRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'working_days' => ['present', 'array'],
            'working_days.*' => [Rule::in(Weekday::values())],

            // Half an hour to a very long day. The upper bound is not a policy about overtime
            // — it is the range a `decimal(4,2)` column can hold with a number a person could
            // have meant, and 24 is where a day stops.
            'working_hours_per_day' => ['required', 'numeric', 'min:0.5', 'max:24'],

            'start_time' => ['nullable', 'date_format:H:i'],
            'office_or_remote' => ['required', Rule::in(['office', 'remote'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'working_days' => 'working days',
            'working_hours_per_day' => 'hours per day',
            'start_time' => 'start time',
            'office_or_remote' => 'work location',
        ];
    }

    /**
     * @return array{working_days: list<string>, working_hours_per_day: float, start_time: ?string, office_or_remote: string}
     */
    public function scheduleAttributes(): array
    {
        $validated = $this->validated();

        return [
            'working_days' => array_values(array_unique($validated['working_days'] ?? [])),
            'working_hours_per_day' => (float) $validated['working_hours_per_day'],
            'start_time' => $validated['start_time'] ?? null,
            'office_or_remote' => $validated['office_or_remote'],
        ];
    }
}
