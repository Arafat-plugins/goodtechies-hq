<?php

namespace App\Http\Resources;

use App\Models\PayrollItem;
use App\Models\User;
use App\Support\RoleName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * **The only way a payroll item leaves the server** (master prompt Part B §3 rule 1, which names
 * this class and `ProjectResource` and no others: *"No endpoint may return raw model JSON for
 * these two models"*).
 *
 * One shape for every reader — the Admin's period detail, the Accountant's, and an employee's
 * own My Payslip — because *"what does a payslip look like"* must be answered once. What
 * differs between readers is **which keys exist**, never which values are blanked.
 *
 * ## The whole key set, and the rule about it
 *
 * Twelve keys, always:
 *
 *     id · period · employee · base_salary · allowance · bonus · deduction · advance ·
 *     leave_impact · net_salary · permissions
 *
 * and a thirteenth, **`admin_notes`, for an ADMIN and nobody else**.
 *
 * A key that is not on that list is not in the payload — not null, not masked, **absent**
 * (Part C). Not the employee's email, phone, employee number, joining date, role, manager,
 * schedule or status. Not their salary history. Not another month's figures. Not a count of
 * anything. `PayrollPrivacyTest` pins it with an exact key-set assertion **and** a recursive
 * forbidden-key walk over the whole payload, in the shape `AccountantProjectEndpointTest`
 * established — because a check for one field name passes the day somebody adds a second.
 *
 * ## `admin_notes` — who gets it, and why the answer is *only an Admin*
 *
 * Part D §14 calls it *"the 'personal notes' column **the Accountant never receives**"*, and
 * Part C §1's matrix says it from the other side: *View others' payroll — ADMIN ✅, ACCOUNTANT
 * 🟡 amounts, **no personal notes***. So the Accountant is excluded in as many words.
 *
 * **The employee it is about does not receive it either**, and that is a decision this slice
 * takes rather than inherits. Part C §1 gives every role *"view own payslip"* — a payslip, which
 * is what was earned and what was deducted. `admin_notes` is not on a payslip: it is what one
 * Admin writes to another about somebody's pay (*"advance to be recovered next month"*, *"held
 * back pending the client's payment"*), and Part C is deny-by-default — nothing in it grants an
 * employee a note written about them. Sending it to the subject would also make it useless for
 * the purpose Part D gives it, which would push the same sentences into a channel with no
 * privacy rules at all.
 *
 * So the test is `RoleName::ADMIN`, and it is a role rather than a key on purpose. There is no
 * permission for *"personal notes"* and Part C §1 forbids inventing one — *"the 🟡 cells are
 * implemented as the key plus a scope check in the Policy … never as a separate key"*. The 🟡
 * here is the Accountant's, and this is its scope check: the same `RoleName::ADMIN`
 * administrative override six existing policies use (decision 7-11).
 *
 * ## Which reader gets which ROW is not this class's business
 *
 * It is `PayrollItem::scopeVisibleTo()`'s, and `PayrollService::findItemFor()`'s — omission from
 * lists and 404 by id (Part B §3 rule 1). A resource never decides whether a record exists; by
 * the time one reaches this class the answer is already yes.
 *
 * @mixin PayrollItem
 */
class PayrollItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User|null $viewer */
        $viewer = $request->user();

        return [
            'id' => (int) $this->resource->getKey(),

            'period' => $this->period(),
            'employee' => $this->employee(),

            // The figures. Exact decimal strings, exactly as PostgreSQL stores them — never
            // floats, and never pre-formatted with a currency symbol, which is a display
            // decision and belongs to the screen.
            'base_salary' => $this->resource->base_salary,
            'allowance' => $this->resource->allowance,
            'bonus' => $this->resource->bonus,
            'deduction' => $this->resource->deduction,
            'advance' => $this->resource->advance,
            'leave_impact' => $this->resource->leave_impact,
            // Computed by PostgreSQL, not by this class and not by Vue. See the migration.
            'net_salary' => $this->resource->net_salary,

            // Part D §14's "personal notes". The key itself is absent for everybody but an
            // Admin — see the class note.
            ...$this->adminNotes($viewer),

            'permissions' => [
                'can_update' => $viewer !== null && Gate::forUser($viewer)->allows('update', $this->resource),
                'can_annotate' => $viewer !== null && Gate::forUser($viewer)->allows('annotate', $this->resource),
            ],
        ];
    }

    /**
     * The month this line belongs to, and where that month has got to.
     *
     * Five keys and not a nested `PayrollPeriodResource`: that class carries the period's
     * totals and its own permission block, which is a list of everybody's pay — exactly what an
     * employee opening their own payslip must not be handed. A payslip needs to say *September
     * 2026, paid*, and this is that sentence and nothing more.
     *
     * @return array<string, mixed>|null
     */
    private function period(): ?array
    {
        $period = $this->resource->period;

        if ($period === null) {
            return null;
        }

        return [
            'id' => (int) $period->getKey(),
            'month' => $period->month?->toDateString(),
            'label' => $period->label(),
            'status' => $period->status?->value,
            'status_label' => $period->status?->label(),
            // The StatusBadge key, resolved on the server so no Vue computed holds a second
            // copy of the map (decision 2-37).
            'state' => $period->status?->tone(),
        ];
    }

    /**
     * Who this line is about: an id and a name.
     *
     * **Two keys, and the second one is the only fact about a person in this payload.** An
     * employee object with an email, a phone number, a joining date or a manager on it would be
     * a staff directory reachable from the payroll screen, and the Accountant has ❌ on every
     * operational cell of Part C §1's matrix. The name is here because a period detail listing
     * nine numeric rows keyed by id is not a screen anybody can use.
     *
     * @return array<string, mixed>|null
     */
    private function employee(): ?array
    {
        $employee = $this->resource->employee;

        if ($employee === null) {
            return null;
        }

        return [
            'id' => (int) $employee->getKey(),
            'name' => $employee->user?->name,
        ];
    }

    /**
     * `['admin_notes' => …]` for an Admin, and `[]` — the key itself gone — for everybody else.
     *
     * Spread into the payload rather than set with `when()`, because a resource that wrote
     * `'admin_notes' => $this->when(...)` and was later refactored into a merge would put the
     * key back as `null` for exactly the people it is meant to be hidden from. Part C's rule is
     * *absent*, and `=== null` passes for both the right answer and the wrong one — which is why
     * the test asserts `array_key_exists` and not a value.
     *
     * @return array<string, mixed>
     */
    private function adminNotes(?User $viewer): array
    {
        if ($viewer === null || ! $viewer->isActive() || ! $viewer->hasRole(RoleName::ADMIN)) {
            return [];
        }

        return ['admin_notes' => $this->resource->admin_notes];
    }
}
