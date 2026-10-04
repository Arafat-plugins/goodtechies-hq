<?php

namespace App\Http\Requests\Payroll;

use App\Models\PayrollPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * Which month a payroll is FOR — polish 005.
 *
 * In Bangladesh pay follows the work: September is worked, then paid in early October, and the
 * payslip has to say September. So drafting defaults to the month just finished, and an Admin
 * or the Accountant can pick (or correct, while still a draft) the month a payroll covers.
 *
 * A month in the future cannot be chosen: nobody is paid for work that has not happened, and
 * a stray click on next year would draft a period nobody can see coming.
 */
class PayrollMonthRequest extends FormRequest
{
    public function rules(): array
    {
        $required = $this->isMethod('put') ? 'required' : 'nullable';

        return [
            'month' => [
                $required,
                'date_format:Y-m',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $month = Carbon::createFromFormat('!Y-m', (string) $value, config('app.timezone'));
                    $now = PayrollPeriod::monthKey(Carbon::today(config('app.timezone')));

                    if ($month !== false && $month->greaterThan($now)) {
                        $fail('A payroll cannot be for a month that has not started yet.');
                    }
                },
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'month.required' => 'Choose the month this payroll is for.',
            'month.date_format' => 'Choose a month, such as September 2026.',
        ];
    }

    /** The chosen month keyed to its first day, or null when none was given. */
    public function month(): ?Carbon
    {
        $value = $this->validated()['month'] ?? null;

        return $value === null || $value === ''
            ? null
            : PayrollPeriod::monthKey(Carbon::createFromFormat('!Y-m', (string) $value, config('app.timezone')));
    }
}
