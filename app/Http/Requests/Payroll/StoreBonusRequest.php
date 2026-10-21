<?php

namespace App\Http\Requests\Payroll;

use App\Support\BonusPayout;
use App\Support\BonusType;
use App\Support\Currency;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A bonus for one or many employees. Authorization is `EmployeeBonusPolicy`, asked in the
 * controller and again in `BonusService::create()`.
 */
class StoreBonusRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'employee_ids' => ['required', 'array', 'min:1', 'max:100'],
            'employee_ids.*' => ['integer', 'distinct', 'exists:employees,id'],
            ...self::bonusRules(),
            'status' => [
                'sometimes',
                'in:pending,approved,paid',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value === 'paid' && $this->input('payout') !== BonusPayout::Separate->value) {
                        $fail('Only a bonus paid separately can be marked paid when it is created.');
                    }
                },
            ],
        ];
    }

    /**
     * The fields a bonus carries, shared with `UpdateBonusRequest`.
     *
     * @return array<string, mixed>
     */
    public static function bonusRules(): array
    {
        return [
            'type' => ['required', Rule::enum(BonusType::class)],
            'title' => ['required_if:type,custom', 'nullable', 'string', 'max:120'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999.99'],
            'currency' => ['required', Rule::in([Currency::default()->value])],
            'payment_date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'payout' => ['sometimes', 'in:with_payroll,separate'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return self::bonusMessages();
    }

    /**
     * @return array<string, string>
     */
    public static function bonusMessages(): array
    {
        return [
            'currency.in' => sprintf('Bonuses are paid with payroll, in %s.', Currency::default()->value),
            'title.required_if' => 'Give a custom bonus a title.',
        ];
    }
}
