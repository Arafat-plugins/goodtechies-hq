<?php

namespace App\Http\Resources;

use App\Models\Income;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * One receipt of money, as a finance screen reads it (master prompt Part D §13, Phase 8).
 *
 * The ledger, the finance dashboard and the monthly report all read income through this class
 * and through nothing else, which is what keeps *"what is an income row"* a single answer. It
 * is the income half of the pair `ExpenseResource` completes.
 *
 * ## The embedded project is the SAME three keys the picker sends
 *
 * A project arrives here as `id`, `name`, `domain` and nothing else — no client, no contact, no
 * description, no note, no status, no count. That is `projectPayload()` below, and the income
 * form's project picker is built from the same method rather than from a second list that
 * happens to agree today. One statement of the key set, in one file, is the only way a privacy
 * rule survives the phase that adds a field to `Project`.
 *
 * It is deliberately **narrower than `AccountantProjectResource`**, which also carries the six
 * `project_finance` money columns. A row in a ledger does not need to know what the project is
 * worth — it already says what this payment was — so the money is not here, on either surface.
 * See the note on `IncomeController::projects()`, which records that this supersedes decision
 * 8-16.
 *
 * ## What is NOT on this payload
 *
 *   - **No total of any kind.** A month's figure is `FinanceService::monthlyRollup()`, computed
 *     from these rows; a per-row running total would be a second statement of it (decision
 *     8-10), and the screen that added them up in Vue would be the one that disagreed.
 *   - **No `category_kind`.** It is a constant the database maintains and every row on this
 *     endpoint is income by construction. A screen that read it would be asking a question the
 *     table already answered.
 *   - **No `recorded_by`.** `IncomePolicy` says in as many words that the row belongs to the
 *     company and not to whoever typed it: it grants no ability and withholds none, so printing
 *     the name would put a person beside a figure for no purpose the permission model has.
 *     The audit log carries the actor, which is where that question belongs.
 *
 * `amount` travels as the exact decimal string PostgreSQL stored, never as a float and never
 * pre-formatted: `resources/js/Components/Finance/finance.ts` holds the one money formatter, so
 * two screens cannot round differently (decision 8-4).
 *
 * @mixin Income
 */
class IncomeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->resource->getKey(),

            // `YYYY-MM-DD`, the agency's calendar day. The screens re-order its parts for
            // display and never parse it — `new Date('2026-09-03')` is UTC midnight, which
            // prints as 2 September for anybody west of Greenwich.
            'date' => $this->resource->date?->toDateString(),

            // The exact decimal string. See the class note.
            'amount' => (string) $this->resource->amount,

            'category' => $this->category(),
            'project' => self::projectPayload($this->resource->project),
            'notes' => $this->resource->notes,

            'permissions' => $this->permissions($request),
        ];
    }

    /**
     * A project as every finance screen may know it: **`id`, `name`, `domain`, and nothing
     * else.**
     *
     * Public and static because the income form's picker sends exactly this and is built from
     * exactly this call — see the class note. A fourth key added here arrives in both places or
     * in neither, which is the whole point of it being one method.
     *
     * `domain` is what stands in place of the client's name (Part C §2) and it is how a human
     * tells four *Website Maintenance* rows apart. The client's name and contacts are not here
     * for any reader, on any surface.
     *
     * @return array{id: int, name: string, domain: string|null}|null
     */
    public static function projectPayload(?Project $project): ?array
    {
        if ($project === null) {
            return null;
        }

        return [
            'id' => (int) $project->getKey(),
            'name' => $project->name,
            'domain' => $project->domain,
        ];
    }

    /**
     * The category, by id and name.
     *
     * The name is read through the relation rather than copied onto the row (see the model):
     * it is editable, and a copy would be a second name for the same category, stale from the
     * first rename. The one place a name IS copied is the audit row, which has the opposite
     * job — saying what was true when it was written.
     *
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
     * Resolved per record, on the server, from `IncomePolicy` — never derived in Vue from a
     * role (decisions 2-28, 2-31). Today both answers are the same for every row; the day the
     * locked-period rule lands in Phase 9 they will not be, and no screen has to change.
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
