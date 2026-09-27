<?php

namespace App\Http\Requests\Finance;

use App\Support\FinanceCategoryKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Money out: a category, an amount, a date and optional notes.
 *
 * `StoreIncomeRequest` minus the project link, and its class note carries the whole argument —
 * one class for create and edit, and three rules that are database constraints said in a
 * sentence first. The category rule here is scoped to `kind = 'expense'`, which is what turns
 * *"that is an income category"* into a 422 on the field rather than a foreign-key violation on
 * a form about the electricity bill.
 *
 * **There is no `project_id`**, and it is not an omission: Part D §20 gives an expense no
 * project column, *Project Cost* is a category rather than a reference, and cost-per-project is
 * an open question with the client (decision 8-17). A `nullable` project here would be a
 * promise the table cannot keep.
 *
 * Authorization is `ExpensePolicy`, asked in the controller and again inside `FinanceService`.
 */
class StoreExpenseRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category_id' => [
                'required',
                'integer',
                Rule::exists('finance_categories', 'id')->where('kind', FinanceCategoryKind::Expense->value),
            ],

            'amount' => [
                'required',
                'numeric',
                'decimal:0,2',
                'gt:0',
                'max:'.StoreIncomeRequest::MAX_AMOUNT,
            ],

            'date' => ['required', 'date_format:Y-m-d'],

            'notes' => ['nullable', 'string', 'max:'.StoreIncomeRequest::MAX_NOTES],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'category_id.exists' => 'Choose an expense category. An income category cannot be used on an expense record.',
            'amount.gt' => 'An expense needs an amount greater than zero.',
            'amount.decimal' => 'An amount has at most two decimal places.',
        ];
    }

    /**
     * @return array{category_id: int, amount: string, date: string, notes: string|null}
     */
    public function expenseAttributes(): array
    {
        $validated = $this->validated();

        $notes = isset($validated['notes']) ? trim((string) $validated['notes']) : '';

        return [
            'category_id' => (int) $validated['category_id'],
            'amount' => (string) $validated['amount'],
            'date' => (string) $validated['date'],
            'notes' => $notes === '' ? null : $notes,
        ];
    }
}
