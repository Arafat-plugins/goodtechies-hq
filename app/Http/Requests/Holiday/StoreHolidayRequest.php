<?php

namespace App\Http\Requests\Holiday;

use App\Models\Holiday;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A new company holiday: a date and a name.
 *
 * ## Uniqueness is on the pair, and it is here because it is also in the database
 *
 * `unique(date, name)` is the table's own constraint (see the create migration): two
 * observances may share a day — in the seeded year Buddha Purnima and May Day do — but the same
 * observance may not be listed twice on one. The rule below is the same statement in the place
 * that can produce a sentence about it, and the index is what makes it true under a double
 * submit. Same shape as decision 3-1: the check is the readable error, the index is the promise.
 *
 * ## Past dates are allowed, deliberately
 *
 * A holiday this year that nobody entered is entered afterwards, and it has to change what the
 * month grid already shows — which is exactly what deriving the status at read time is for
 * (decision 4-9). `AttendanceService::edit()` refuses a *future* attendance record because it
 * would be a claim about a day that has not happened; a holiday is the opposite kind of record,
 * a statement about the calendar, and the calendar has a past.
 *
 * Authorization is `HolidayPolicy::create`, asked in the controller.
 */
class StoreHolidayRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d', Rule::unique('holidays', 'date')->where(
                fn ($query) => $query->where('name', trim((string) $this->input('name'))),
            )],
            'name' => ['required', 'string', 'max:'.Holiday::MAX_NAME],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            // The default — "The date has already been taken" — would be wrong twice over: the
            // date is not taken (another holiday may share it) and it does not say what to do.
            'date.unique' => 'That holiday is already on the calendar for this date.',
        ];
    }

    /**
     * @return array{date: string, name: string}
     */
    public function holidayAttributes(): array
    {
        $validated = $this->validated();

        return [
            'date' => (string) $validated['date'],
            'name' => trim((string) $validated['name']),
        ];
    }
}
