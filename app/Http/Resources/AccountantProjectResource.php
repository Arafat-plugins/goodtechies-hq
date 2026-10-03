<?php

namespace App\Http\Resources;

use App\Models\Project;
use App\Models\ProjectFinance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A project as the **Accountant** — and only the Accountant's finance screens — may know it
 * (master prompt Part D §13).
 *
 * > *"the matrix says ❌ view projects and ❌ clients, but 🟡 view project finance, read-only,
 * > linked to invoicing. Implemented as a dedicated finance-only endpoint → id, project name,
 * > domain, `project_finance` fields, read-only — **no client name or contacts** (AC5)."*
 *
 * ## The whole key set, and the rule about it
 *
 * Four keys at the top level:
 *
 *     id · name · domain · finance
 *
 * and, inside `finance`, exactly the six money columns of `project_finance`:
 *
 *     price · recurring_amount · billing_frequency · contract_value · contract_terms ·
 *     profitability_snapshot
 *
 * **A key that is not on that list is not in the payload — not null, not masked, absent**
 * (Part C). Not the client. Not the client's id. Not a contact. Not the description, the
 * internal notes, the employee notes, the status, the deadline, the priority, the PM, the
 * status history, a task, a member, a message, a file, or a COUNT of any of those. A count is a
 * fact about the operational side of a project and the Accountant has ❌ on that whole column
 * of the matrix; `tasks_count: 14` tells them how busy a client's account is, which is exactly
 * the kind of thing the boundary exists to withhold.
 *
 * `tests/Feature/Finance/AccountantProjectEndpointTest.php` pins this with an exact-key
 * assertion **and** a recursive forbidden-key walk over the whole payload, in the shape
 * `tests/Feature/Team/TeamDirectoryTest.php` established. The exact-key assertion is the real
 * test: a check for three field names passes the day somebody adds a fourth.
 *
 * ## Why this is a separate class and not `ProjectResource` with a flag
 *
 * `ProjectResource` is the serializer for a project on the Admin and Employee surfaces, and it
 * is a *subtractive* payload — it starts from the operational project and adds `finance` when
 * `ProjectPolicy::viewFinance` allows. Reusing it here would mean the Accountant's privacy
 * depended on a chain of `when()`s staying right through every future change to a screen they
 * cannot open. This class is *additive*: it starts from nothing and names four keys. The
 * difference is that a field added to `ProjectResource` next phase cannot leak through here,
 * because it would have to be typed into this file to arrive.
 *
 * It also has **no `permissions` block**, unlike every other resource in this application. The
 * endpoint is read-only by Part D's own word, so there is no control for a UI to hide and no
 * ability worth publishing.
 *
 * ## `domain`, and why the one field that identifies the client is allowed
 *
 * Part C §2 already settles this: an Employee *"sees **domain** instead"* of the client name.
 * The domain is the project's own public address and it is the only way a human recognises
 * which of four *— Website Maintenance* rows they are invoicing. It is on Part D §13's own list
 * of four. The client's NAME and contacts are not, and neither is here.
 *
 * `finance` is `null` for a project with no `project_finance` row — the internal project is one
 * — and the key is still present. That null is not a privacy absence: it is the honest answer
 * to *"what is this project worth"* for a project that is worth nothing because nobody is
 * billed for it. A privacy absence would be the key not existing, and nothing in this payload
 * is ever hidden from its reader — if they may call this endpoint, they may see all four keys.
 *
 * @mixin Project
 */
class AccountantProjectResource extends JsonResource
{
    /**
     * @return array{id: int, name: string, domain: string|null, finance: array<string, mixed>|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->resource->getKey(),
            'name' => $this->resource->name,
            'domain' => $this->resource->domain,
            'finance' => $this->finance(),
        ];
    }

    /**
     * The seven columns of `project_finance`, and nothing else from that table.
     *
     * Its own `id`, its `project_id` and its timestamps are row bookkeeping rather than money:
     * `project_id` is already `id` above, and a finance row's own id is a handle on a record
     * this endpoint is not allowed to write.
     *
     * `billing_frequency` travels as the stored string with no label beside it. `ProjectResource`
     * resolves a label because an Admin screen prints one; this payload feeds a picker and a
     * report, and a second representation of the same field would be a second key on a list
     * whose whole point is that it is exactly this long.
     *
     * @return array<string, mixed>|null
     */
    private function finance(): ?array
    {
        /** @var ProjectFinance|null $finance */
        $finance = $this->resource->finance;

        if ($finance === null) {
            return null;
        }

        return [
            'price' => $finance->price,
            'recurring_amount' => $finance->recurring_amount,
            'billing_frequency' => $finance->billing_frequency,
            'hourly_rate' => $finance->hourly_rate,
            'contract_value' => $finance->contract_value,
            'contract_terms' => $finance->contract_terms,
            'profitability_snapshot' => $finance->profitability_snapshot,
        ];
    }
}
