<?php

namespace App\Http\Requests\Finance;

use App\Support\FinanceCategoryKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Money in: a category, an amount, a date, optional notes and an optional project.
 *
 * **One class for create and for edit**, because the two are the same five fields with the same
 * five rules — there is no uniqueness constraint to ignore on the routed record, which is the
 * only reason `UpdateHolidayRequest` exists as its own class. A second class here would be this
 * file copied, and the copy is the one that would not get the next rule.
 *
 * Authorization is `IncomePolicy`, asked in the controller and again inside `FinanceService`.
 *
 * ## The three rules that are really database constraints, said in a sentence first
 *
 * Each of these is enforced underneath by PostgreSQL, which is the promise; the rule here is
 * what a person reads instead of a stack trace. Same shape as decision 3-1 and as
 * `FinanceStateException`'s own class note.
 *
 *   1. **The category must be an INCOME category.** `income` carries a constant `category_kind`
 *      column and a composite `FOREIGN KEY (category_id, category_kind)` (decision 8-2), so
 *      filing September's invoice under *Payroll* is refused by the database — as
 *      `SQLSTATE[23503]`, on a form about invoices. The `Rule::exists` below scopes the lookup
 *      to `kind = 'income'`, which makes the same refusal a **422 on the category field**. The
 *      picker is sent only this side's list, so a person can only reach this by hand; a
 *      response of 500 to somebody who did is still a defect.
 *   2. **The amount must be greater than zero.** `amount > 0` is a CHECK on the table (decision
 *      8-3): the sign is the table, not the number, and a negative income is an expense in
 *      disguise that would subtract from a line every screen renders as money received. Zero
 *      goes with it — that is what a half-filled form submits.
 *   3. **The amount has at most two decimal places and fits `decimal(12, 2)`.** Three decimals
 *      would be silently rounded by the column, and a figure that changed between the form and
 *      the ledger is the one thing a ledger may not do.
 */
class StoreIncomeRequest extends FormRequest
{
    /** `decimal(12, 2)` — ten digits before the point, two after. */
    public const MAX_AMOUNT = '9999999999.99';

    /** `notes` is a `text` column; this is a form field, not a document. */
    public const MAX_NOTES = 1000;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category_id' => [
                'required',
                'integer',
                // Scoped to this side of the ledger. See rule 1 in the class note.
                Rule::exists('finance_categories', 'id')->where('kind', FinanceCategoryKind::Income->value),
            ],

            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:'.self::MAX_AMOUNT],

            'date' => ['required', 'date_format:Y-m-d'],

            'notes' => ['nullable', 'string', 'max:'.self::MAX_NOTES],

            // Part D §13's "optional project link". Not scoped by `Project::visibleTo()`: that
            // scope returns nothing for an Accountant and this is the one place Part D gives
            // them project money by design (decision 8-9). Archived projects are allowed — a
            // final invoice on a finished project is the ordinary case, not the odd one.
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            // The default — "The selected category id is invalid" — is true and useless. The
            // reason a valid category id can be invalid HERE is the side of the ledger it is
            // on, and that is what somebody needs told.
            'category_id.exists' => 'Choose an income category. An expense category cannot be used on an income record.',
            'amount.gt' => 'An income needs an amount greater than zero.',
            'amount.decimal' => 'An amount has at most two decimal places.',
        ];
    }

    /**
     * The attributes `FinanceService` will narrow again and write.
     *
     * `recorded_by` is not here and never will be: the service sets it from the actor, so an
     * edit form cannot reassign who entered a figure. Neither is `category_kind` — no PHP in
     * this application writes that column.
     *
     * @return array{category_id: int, project_id: int|null, amount: string, date: string, notes: string|null}
     */
    public function incomeAttributes(): array
    {
        $validated = $this->validated();

        $notes = isset($validated['notes']) ? trim((string) $validated['notes']) : '';

        return [
            'category_id' => (int) $validated['category_id'],
            'project_id' => isset($validated['project_id']) ? (int) $validated['project_id'] : null,
            // A string, never a float — the column is `decimal(12, 2)` and binary float cannot
            // hold 0.10 (decision 8-4).
            'amount' => (string) $validated['amount'],
            'date' => (string) $validated['date'],
            'notes' => $notes === '' ? null : $notes,
        ];
    }
}
