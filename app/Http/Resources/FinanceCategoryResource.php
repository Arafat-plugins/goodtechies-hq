<?php

namespace App\Http\Resources;

use App\Models\FinanceCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * A name money is filed under (master prompt Part D §13, Phase 8).
 *
 * Read by three screens: the income form's picker, the expense form's picker, and the
 * Categories screen itself. One shape for all three, with `permissions` resolved per record on
 * the server.
 *
 * ## `kind` is on the payload, and it is what stops the wrong picker
 *
 * Every category carries the side of the ledger it belongs to. The composite foreign key on
 * `income` and `expenses` makes filing income under an expense category impossible at the
 * database (decision 8-2), and `StoreIncomeRequest` turns that into a 422 rather than a 500 —
 * but a picker that *offered* the wrong one would be a form whose only feedback is an error.
 * So each form is sent the one side's list, already filtered by the controller through
 * `FinanceService::categories()`, and `kind` travels so the Categories screen can group by it
 * and so a wrongly-built picker is visible in the payload rather than only in a failure.
 *
 * ## `usage_count` is present only when the caller counted
 *
 * How many finance records are filed under a category is the blast radius of deleting it —
 * `ON DELETE RESTRICT` refuses it while anything is — and the Categories screen needs it to
 * decide whether there is a Remove control at all. It is `TagResource::task_count`'s job.
 *
 * It is behind a `whenCounted`-style check rather than always computed, because
 * `FinanceCategory::usageCount()` is a query per row: a picker with nine categories would
 * otherwise run nine counts to render a dropdown that never shows the number. The Categories
 * controller calls `loadCount(['income', 'expenses'])` once and the keys appear; the pickers do
 * not, and they are absent. **Absent, not zero** — a screen must never read "nothing is filed
 * under this" from a caller that simply did not ask.
 *
 * @mixin FinanceCategory
 */
class FinanceCategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return array_merge([
            'id' => (int) $this->resource->getKey(),

            // `income` or `expense`. See the class note — this is the side, and the side is
            // never editable (`FinanceService::updateCategory()` refuses it, and `ON UPDATE
            // RESTRICT` refuses it underneath).
            'kind' => $this->resource->kind?->value,

            'name' => $this->resource->name,

            // The order every picker offers them in, sent so a screen sorts by nothing of its
            // own. The server already ordered the collection; this is here so a reader can see
            // why two categories sit the way round they do.
            'position' => (int) $this->resource->position,

            'permissions' => $this->permissions($request),
        ], $this->usage());
    }

    /**
     * The blast radius, when the caller loaded it. See the class note for why not always.
     *
     * Both counts are read and added because a category lives on exactly one side and the
     * other side's count is structurally zero — the composite foreign key makes a row pointing
     * the wrong way impossible — so the sum is the one side's count without this file having to
     * branch on `kind` to say so.
     *
     * @return array<string, int|bool>
     */
    private function usage(): array
    {
        $income = $this->resource->getAttribute('income_count');
        $expenses = $this->resource->getAttribute('expenses_count');

        if ($income === null && $expenses === null) {
            return [];
        }

        $count = (int) $income + (int) $expenses;

        return [
            'usage_count' => $count,
            // The same fact as a boolean, because that is the question the screen asks: a
            // category in use has no Remove control (`ON DELETE RESTRICT` would refuse it).
            'in_use' => $count > 0,
        ];
    }

    /**
     * From `FinanceCategoryPolicy`, per record, on the server.
     *
     * This is the one payload in the finance slice where the two answers genuinely differ by
     * reader: **reading the list is `finance.view` and changing it is `settings.manage`**
     * (decision 8-12), so an Accountant gets every category with both flags false and an Admin
     * gets the same list with both true. The Categories screen draws its controls from these
     * and never from a role, which is what makes the Accountant's read-only view the same
     * component as the Admin's editable one.
     *
     * @return array<string, bool>
     */
    private function permissions(Request $request): array
    {
        $user = $request->user();

        if ($user === null) {
            return ['can_update' => false, 'can_delete' => false];
        }

        return [
            'can_update' => Gate::forUser($user)->allows('update', $this->resource),
            'can_delete' => Gate::forUser($user)->allows('delete', $this->resource),
        ];
    }
}
