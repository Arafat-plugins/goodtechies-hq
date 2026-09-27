<?php

namespace App\Http\Requests\Finance;

use App\Support\FinanceCategoryKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A new category on one side of the ledger (master prompt Part D §13: *"Categories (seeded,
 * Admin-editable)"*).
 *
 * Authorization is `FinanceCategoryPolicy::create`, which is **`settings.manage`** and not
 * `finance.manage` — decision 8-12, and the reasoning is in that policy rather than restated
 * here. The controller asks it; this class only validates.
 *
 * ## `UNIQUE (kind, name)`, on the pair and not on the name
 *
 * *Other* exists on both sides of the ledger and they are different categories; *Payroll* as an
 * income category is a name nobody has taken (decision 8-1). Within one side, two categories
 * with the same name would split a rollup line in two with neither half wrong, which is why the
 * index exists — this rule is the same statement in the place that can produce a sentence about
 * it.
 *
 * The name is compared trimmed, because that is what will be stored:
 * `FinanceService::createCategory()` trims, and a rule that checked the untrimmed value would
 * let `"SEO "` through to collide at the index.
 */
class StoreFinanceCategoryRequest extends FormRequest
{
    /**
     * The column is a plain `string` (255). This is shorter on purpose: a category name is a
     * label on a rollup line and on every picker, and one that wraps to three lines is a
     * reporting choice nobody made deliberately.
     */
    public const MAX_NAME = 60;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Which side. Required here and refused on every edit — a category in use cannot
            // change sides (`ON UPDATE RESTRICT`), and one that is not in use is deleted and
            // recreated rather than moved (`FinanceService::updateCategory()`).
            'kind' => ['required', Rule::in(FinanceCategoryKind::values())],

            'name' => [
                'required',
                'string',
                'max:'.self::MAX_NAME,
                Rule::unique('finance_categories', 'name')->where(
                    fn ($query) => $query->where('kind', (string) $this->input('kind')),
                ),
            ],
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
            // The default says "the name has already been taken", which is wrong on a ledger
            // with two sides: it may well be free on the other one.
            'name.unique' => 'That category already exists on this side of the ledger.',
        ];
    }

    public function kind(): FinanceCategoryKind
    {
        return FinanceCategoryKind::from((string) $this->validated()['kind']);
    }

    public function categoryName(): string
    {
        return trim((string) $this->validated()['name']);
    }
}
