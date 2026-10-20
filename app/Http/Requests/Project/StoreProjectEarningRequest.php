<?php

namespace App\Http\Requests\Project;

use App\Support\Currency;
use App\Support\ProjectEarningKind;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One hand-recorded project financial entry, on create and on edit. Who may send this at all is
 * ProjectPolicy::updateFinance's decision, asked by the controller and the service.
 */
class StoreProjectEarningRequest extends FormRequest
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
            'kind' => ['required', Rule::enum(ProjectEarningKind::class)],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999.99'],
            'currency' => ['required', Rule::in(Currency::values())],
            'date' => ['required', 'date_format:Y-m-d'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.gt' => 'An amount must be greater than zero.',
            'currency.in' => 'Choose BDT or USD.',
            'kind' => 'Choose Contract value, Earned or Received.',
        ];
    }

    /**
     * @return array{kind: string, amount: string, currency: string, date: string, description: string|null}
     */
    public function entryAttributes(): array
    {
        $validated = $this->validated();
        $description = isset($validated['description']) ? trim((string) $validated['description']) : '';

        return [
            'kind' => (string) $validated['kind'],
            'amount' => (string) $validated['amount'],
            'currency' => (string) $validated['currency'],
            'date' => (string) $validated['date'],
            'description' => $description === '' ? null : $description,
        ];
    }
}
