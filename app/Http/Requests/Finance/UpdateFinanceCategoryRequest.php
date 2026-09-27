<?php

namespace App\Http\Requests\Finance;

use App\Models\FinanceCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Renaming a category.
 *
 * **This one earns its own class**, unlike the income and expense pair, and for the reason
 * `UpdateHolidayRequest` does: the uniqueness rule has to ignore the routed record, or saving a
 * category without changing its name would be a validation error. It also differs in what it
 * accepts — there is no `kind` here at all.
 *
 * ## `kind` is not a field on this request, on purpose
 *
 * `FinanceService::updateCategory()` throws `FinanceStateException::categoryKindIsImmutable()`
 * if a `kind` is passed that differs from the category's, and `ON UPDATE RESTRICT` on the
 * composite foreign key refuses it underneath for any category in use. Leaving it out of the
 * rules means the service's refusal is the *only* answer to "can I move this to the other
 * side?", rather than a validation rule that agrees with it today. A form that sent one would
 * simply have it ignored here and then refused there, which is the order those two should
 * happen in.
 *
 * `position` is accepted so the Admin can reorder the pickers without a second endpoint. It is
 * an ordering hint, not an identifier — `FinanceCategory::scopeInOrder()` breaks ties by name,
 * so two categories sharing a position is untidy rather than broken.
 *
 * Authorization is `FinanceCategoryPolicy::update` (`settings.manage`, decision 8-12), asked in
 * the controller.
 */
class UpdateFinanceCategoryRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $category = $this->route('category');
        $kind = $category instanceof FinanceCategory ? $category->kind?->value : null;

        return [
            'name' => [
                'required',
                'string',
                'max:'.StoreFinanceCategoryRequest::MAX_NAME,
                // The routed category's own row is ignored, and the lookup stays scoped to its
                // side of the ledger — the index is on the pair.
                Rule::unique('finance_categories', 'name')
                    ->ignore($category instanceof FinanceCategory ? $category->getKey() : null)
                    ->where(fn ($query) => $query->where('kind', $kind)),
            ],

            'position' => ['sometimes', 'integer', 'min:0', 'max:999'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge(['name' => trim((string) $this->input('name'))]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'That category already exists on this side of the ledger.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function categoryAttributes(): array
    {
        $validated = $this->validated();

        $values = ['name' => trim((string) $validated['name'])];

        if (array_key_exists('position', $validated)) {
            $values['position'] = (int) $validated['position'];
        }

        return $values;
    }
}
