<?php

namespace App\Http\Resources;

use App\Models\Expense;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * One payment out, as a finance screen reads it (master prompt Part D §13, Phase 8).
 *
 * The mirror of `IncomeResource` and every note on that class applies: no total of any kind, no
 * `category_kind`, no `recorded_by`, and `amount` as the exact decimal string PostgreSQL
 * stored — formatted by the one function in `Components/Finance/finance.ts` and nowhere else.
 *
 * The one difference is the one Part D §20 draws: **an expense has no project link**, so there
 * is no `project` key here, not even as null. *Project Cost* is an expense category rather than
 * a project reference, and cost-per-project is an open question with the client (decision
 * 8-17), not a key this payload should imply an answer to.
 *
 * It is a separate class from `IncomeResource` rather than one shared shape with a nullable
 * project, for the reason `ExpensePolicy` is a separate class from `IncomePolicy`: the two
 * sides are already diverging — Phase 9's payroll writes expenses nobody should hand-edit —
 * and when they diverge further it lands in one file instead of in a `when()` on a class name.
 *
 * @mixin Expense
 */
class ExpenseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->resource->getKey(),
            'date' => $this->resource->date?->toDateString(),
            'amount' => (string) $this->resource->amount,
            'category' => $this->category(),
            'notes' => $this->resource->notes,
            'permissions' => $this->permissions($request),
        ];
    }

    /**
     * @return array{id: int, name: string|null}|null
     */
    private function category(): ?array
    {
        $category = $this->resource->category;

        if ($category === null) {
            return null;
        }

        return [
            'id' => (int) $category->getKey(),
            'name' => $category->name,
        ];
    }

    /**
     * Per record, from `ExpensePolicy`, on the server. See `IncomeResource::permissions()`.
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
