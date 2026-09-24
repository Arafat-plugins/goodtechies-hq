<?php

namespace App\Http\Requests\Holiday;

use App\Models\Holiday;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Renaming a holiday, or moving it to another date.
 *
 * One request for both, because they are one act: a lunar date seeded from an almanac and then
 * gazetted a day later is moved, and more often than not renamed in the same edit (*"Eid
 * ul-Fitr holiday"* becoming *"Eid ul-Fitr"* when the moon shifts the block). Two endpoints
 * would have been two audit rows for one correction.
 *
 * The uniqueness rule is `StoreHolidayRequest`'s with the routed holiday ignored, so saving a
 * row without changing it is not a validation error. See that class for why the pair rather
 * than the date alone.
 *
 * Authorization is `HolidayPolicy::update`, asked in the controller.
 */
class UpdateHolidayRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $holiday = $this->route('holiday');

        return [
            'date' => ['required', 'date_format:Y-m-d', Rule::unique('holidays', 'date')
                ->ignore($holiday instanceof Holiday ? $holiday->getKey() : null)
                ->where(fn ($query) => $query->where('name', trim((string) $this->input('name'))))],
            'name' => ['required', 'string', 'max:'.Holiday::MAX_NAME],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
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
