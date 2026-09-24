<?php

namespace App\Http\Requests\Leave;

use App\Models\LeaveType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Applying for leave, and answering a correction request by resubmitting — the same four
 * fields both times, because it is the same request asking the same question again.
 *
 * ## What is NOT validated here
 *
 * Three of this feature's refusals are deliberately not rules in this file:
 *
 *   - **overlap**, **balance** and **"no working days in that range"**. All three are
 *     questions about the state of other rows, and all three are answered inside
 *     `LeaveService`'s transaction with the employee's requests locked — a validator that asked
 *     them would be asking outside the lock, so two applications in the same second would both
 *     pass and the second would then hit `leave_requests_no_overlap` as a raw constraint
 *     violation. They come back as flash errors with a sentence naming the dates or the
 *     numbers, which is what somebody trying to book a week off needs.
 *
 * What IS here is the shape of the input: a type that exists, two dates the right way round,
 * and a reason somebody actually wrote.
 *
 * Authorization is not here either — it is `LeaveRequestPolicy::create` / `::resubmit`, asked
 * in the controller.
 */
class ApplyLeaveRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'leave_type_id' => ['required', 'integer', Rule::exists('leave_types', 'id')],

            // `Y-m-d`, the format the date controls send and the column stores. `after_or_equal`
            // rather than `after`: one day off is a range of one day, which is the commonest
            // request there is.
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],

            // Part D §9 asks for a reason on every application. Ten characters, because "asdf"
            // is not one and an approver has to be able to rule on what is written here.
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'leave_type_id' => 'leave type',
            'start_date' => 'first day',
            'end_date' => 'last day',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'end_date.after_or_equal' => 'The last day cannot be before the first day.',
            'reason.min' => 'Say a little more about why you need the time — the person approving it reads this.',
        ];
    }

    public function leaveType(): LeaveType
    {
        return LeaveType::findOrFail($this->validated()['leave_type_id']);
    }

    public function startDate(): string
    {
        return $this->validated()['start_date'];
    }

    public function endDate(): string
    {
        return $this->validated()['end_date'];
    }

    public function reason(): string
    {
        return trim($this->validated()['reason']);
    }
}
