<?php

namespace App\Http\Requests\Leave;

use Illuminate\Foundation\Http\FormRequest;

/**
 * An Admin setting somebody's balance for one capped type.
 *
 * ## It is a SET, not an adjustment
 *
 * `balance_days` is the number that should be there afterwards, which is what the Admin is
 * actually deciding. A delta would have needed the screen to show what it was being applied to
 * and would have made "set it to 12" a subtraction somebody had to do in their head.
 *
 * ## The reason is required, and it is a rule and not a convention
 *
 * Part D §9: *"no accrual logic in MVP — Admin adjusts balances, **audit-logged**"*. A balance
 * decides what somebody is allowed to take later, and changing one quietly is exactly what the
 * audit log exists to prevent — so the reason is a validation rule, which means there is no
 * code path that can skip it. It is carried into the `leave.balance_adjusted` row beside the
 * old and the new value. The same shape `UpdateAttendanceRecordRequest` uses for a correction.
 *
 * The upper bound is a sanity rail rather than a policy: 365 days of one type is already more
 * than a year, and a typo of 1500 should be caught here rather than sit in a balance nobody
 * reads until somebody books four years off.
 */
class UpdateLeaveBalanceRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'balance_days' => ['required', 'integer', 'min:0', 'max:365'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['balance_days' => 'days', 'reason' => 'reason'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Say why you are changing this balance. It is recorded in the audit log.',
            'balance_days.min' => 'A balance cannot be negative.',
        ];
    }

    public function days(): int
    {
        return (int) $this->validated()['balance_days'];
    }

    public function reason(): string
    {
        return trim($this->validated()['reason']);
    }
}
