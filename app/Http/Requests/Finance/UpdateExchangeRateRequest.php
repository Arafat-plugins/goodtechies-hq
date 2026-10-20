<?php

namespace App\Http\Requests\Finance;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `PUT /finance/exchange-rate` (D7): `auto` follows the live USD→BDT rate; `manual` keeps the
 * figure given here. Authorization is `can:finance.manage` on the route.
 */
class UpdateExchangeRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'mode' => ['required', 'string', 'in:auto,manual'],
            'rate' => ['required_if:mode,manual', 'nullable', 'numeric', 'between:50,500', 'decimal:0,2'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rate.required_if' => 'Enter the rate: how many BDT one US dollar is worth.',
            'rate.between' => 'The rate must be between 50 and 500 BDT for 1 USD.',
            'rate.decimal' => 'Use at most two decimals.',
        ];
    }
}
