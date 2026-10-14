<?php

namespace App\Http\Requests\Finance;

use App\Support\FinanceCategoryKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Money out: a category, an amount, a date, optional notes, an optional project and a
 * billable flag.
 *
 * `StoreIncomeRequest`'s mirror, and its class note carries the whole argument — one class for
 * create and edit, and three rules that are database constraints said in a sentence first. The
 * category rule here is scoped to `kind = 'expense'`, which is what turns *"that is an income
 * category"* into a 422 on the field rather than a foreign-key violation on a form about the
 * electricity bill.
 *
 * **An expense may name a project and be billable** (decision 8-17b, superseding 8-17): a cost
 * for one client's site (hosting, domain, VPS) belongs to that project, the same optional,
 * unscoped link income has (8-9), and `billable` marks a cost the client pays back.
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

            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')],

            'billable' => ['sometimes', 'boolean'],
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
     * `project_id` and `billable` are only included when the request sent them, so an edit that
     * leaves them out keeps the stored link and flag instead of clearing them.
     *
     * @return array{category_id: int, project_id?: int|null, billable?: bool, amount: string, date: string, notes: string|null}
     */
    public function expenseAttributes(): array
    {
        $validated = $this->validated();

        $notes = isset($validated['notes']) ? trim((string) $validated['notes']) : '';

        $attributes = [
            'category_id' => (int) $validated['category_id'],
            'amount' => (string) $validated['amount'],
            'date' => (string) $validated['date'],
            'notes' => $notes === '' ? null : $notes,
        ];

        if (array_key_exists('project_id', $validated)) {
            $attributes['project_id'] = $validated['project_id'] === null ? null : (int) $validated['project_id'];
        }

        if (array_key_exists('billable', $validated)) {
            $attributes['billable'] = (bool) $validated['billable'];
        }

        return $attributes;
    }
}
