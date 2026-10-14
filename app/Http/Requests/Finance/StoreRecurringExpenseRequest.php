<?php

namespace App\Http\Requests\Finance;

use App\Models\RecurringExpense;
use App\Support\FinanceCategoryKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * A repeating expense: a category, an amount, how often, from when, until when (optional), an
 * optional project, a billable flag and whether it is running.
 *
 * `StoreExpenseRequest`'s sibling, for create and edit alike. The category rule is scoped to
 * `kind = 'expense'` for the same reason it is there: a 422 on the field rather than a
 * foreign-key violation. Authorization is asked in the controller and again in
 * `FinanceService::saveRecurringExpense()`.
 */
class StoreRecurringExpenseRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // A new template starts this month or later: earlier months are recorded as normal
        // expenses, never back-filled by the generator. An edit that leaves the stored start
        // date as it is keeps it, even once that month has passed.
        $startRules = ['required', 'date_format:Y-m-d'];

        $existing = $this->route('recurringExpense');

        if ($existing !== null && ! $existing instanceof RecurringExpense) {
            $existing = RecurringExpense::query()->find($existing);
        }

        $unchanged = $existing instanceof RecurringExpense
            && $existing->start_date?->toDateString() === $this->input('start_date');

        if (! $unchanged) {
            $startRules[] = 'after_or_equal:'.Carbon::today(config('app.timezone'))->startOfMonth()->toDateString();
        }

        return [
            'category_id' => [
                'required',
                'integer',
                Rule::exists('finance_categories', 'id')->where('kind', FinanceCategoryKind::Expense->value),
            ],

            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')],

            'billable' => ['sometimes', 'boolean'],

            'amount' => [
                'required',
                'numeric',
                'decimal:0,2',
                'gt:0',
                'max:'.StoreIncomeRequest::MAX_AMOUNT,
            ],

            'frequency' => ['required', 'in:monthly,quarterly,yearly'],

            'start_date' => $startRules,

            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],

            'notes' => ['nullable', 'string', 'max:2000'],

            'active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'category_id.required' => 'Choose an expense category.',
            'category_id.exists' => 'Choose an expense category. An income category cannot be used on an expense.',
            'amount.gt' => 'A repeating expense needs an amount greater than zero.',
            'amount.decimal' => 'An amount has at most two decimal places.',
            'frequency.in' => 'Choose how often it repeats: monthly, quarterly or yearly.',
            'start_date.required' => 'Choose the first date it is due.',
            'start_date.after_or_equal' => 'A repeating expense can start this month or later. Record earlier months as normal expenses.',
            'ends_on.after_or_equal' => 'The end date cannot be before the start date.',
        ];
    }

    /**
     * @return array{category_id: int, project_id: int|null, billable: bool, amount: string, frequency: string, start_date: string, ends_on: string|null, notes: string|null, active: bool}
     */
    public function templateAttributes(): array
    {
        $validated = $this->validated();

        $notes = isset($validated['notes']) ? trim((string) $validated['notes']) : '';

        return [
            'category_id' => (int) $validated['category_id'],
            'project_id' => isset($validated['project_id']) ? (int) $validated['project_id'] : null,
            'billable' => (bool) ($validated['billable'] ?? false),
            'amount' => (string) $validated['amount'],
            'frequency' => (string) $validated['frequency'],
            'start_date' => (string) $validated['start_date'],
            'ends_on' => isset($validated['ends_on']) && $validated['ends_on'] !== '' ? (string) $validated['ends_on'] : null,
            'notes' => $notes === '' ? null : $notes,
            'active' => (bool) ($validated['active'] ?? true),
        ];
    }
}
