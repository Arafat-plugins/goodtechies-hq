<?php

namespace App\Http\Requests\Project;

use App\Support\BillingFrequency;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The money on a project. Every field is optional: a partial write leaves the rest of the
 * finance row alone. Who may send this at all is ProjectPolicy::updateFinance's decision.
 */
class UpdateProjectFinanceRequest extends FormRequest
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
        return self::financeRules();
    }

    /**
     * The same rules, optionally under a prefix, so creating a project can carry its finance
     * in one request without the two definitions drifting apart.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public static function financeRules(string $prefix = ''): array
    {
        $money = ['nullable', 'numeric', 'min:0', 'max:99999999.99'];

        return [
            $prefix.'price' => $money,
            $prefix.'recurring_amount' => $money,
            $prefix.'contract_value' => $money,
            $prefix.'billing_frequency' => ['nullable', Rule::enum(BillingFrequency::class)],
            $prefix.'contract_terms' => ['nullable', 'string', 'max:5000'],
            $prefix.'profitability_snapshot' => ['nullable', 'numeric'],
        ];
    }
}
