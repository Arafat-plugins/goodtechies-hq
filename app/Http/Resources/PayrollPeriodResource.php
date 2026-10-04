<?php

namespace App\Http\Resources;

use App\Models\PayrollPeriod;
use App\Models\User;
use App\Support\PayrollStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * One month of payroll as the Admin's and the Accountant's payroll screens know it (master
 * prompt Part D §14).
 *
 * ## This is not a payslip, and it never reaches an employee
 *
 * A period carries **totals over everybody** and the state machine's controls. Part C §1 gives
 * *View others' payroll* to ADMIN and ACCOUNTANT only, and `PayrollPeriodPolicy::viewAny()`
 * asks `payroll.view_others` — so nothing that serves this class is reachable by anybody else.
 * `PayrollItemResource` deliberately does **not** nest it: an employee's own payslip names its
 * month in five keys of its own rather than borrowing this payload and trusting a `when()` to
 * subtract the rest of the company from it.
 *
 * ## `permissions` is the state machine, resolved per requester
 *
 * Six booleans, one per verb, straight from `PayrollPeriodPolicy`. Every screen renders them
 * and none of them computes what it may do — in particular `can_reverse_lock` is the ADMIN-only
 * cell Part D §14 and Part C §4 both name, and no Vue file is allowed a second opinion about it.
 * The endpoints ask the same policy again before they act, so a stale payload buys nobody
 * anything, and `PayrollService` throws a sentence when the *status* is wrong rather than the
 * person — which is why these booleans are about permission alone and `available_transitions`
 * beside them is about state.
 *
 * @mixin PayrollPeriod
 */
class PayrollPeriodResource extends JsonResource
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
            'month' => $this->resource->month?->toDateString(),
            'label' => $this->resource->label(),

            'status' => $this->resource->status?->value,
            'status_label' => $this->resource->status?->label(),
            'state' => $this->resource->status?->tone(),

            // What the machine allows from here, as values. A screen greys a control out from
            // this; a service refuses it with a sentence if the screen gets it wrong.
            'available_transitions' => array_values(array_unique(array_merge(
                array_map(
                    fn ($status): string => $status->value,
                    $this->resource->status?->transitions() ?? [],
                ),
                // Polish 002: Approved → Paid is one press (`PayrollService::markPaid`).
                $this->resource->status === PayrollStatus::Approved ? [PayrollStatus::Paid->value] : [],
            ))),
            'closes_the_month' => (bool) $this->resource->status?->closesTheMonth(),

            // **Calculate is the one verb that is not a transition**, so it cannot be read off
            // `available_transitions`. Pressing it on a `draft` moves the period; pressing it
            // again on a `calculated` one recomputes and moves nothing, which is Part D §14's
            // fill-adjust-calculate loop and why `PayrollStatus` has no `calculated →
            // calculated` self-move. The screen therefore has to be *told* whether the button
            // belongs on the page, exactly as it is told the six permissions — deriving it in
            // Vue would be a second copy of `allowsCalculation()`, and the copy that goes stale.
            'allows_calculation' => (bool) $this->resource->status?->allowsCalculation(),

            'locked_at' => $this->resource->locked_at?->toIso8601String(),
            'lock_reversal' => $this->lockReversal(),

            // Present only where the relation was loaded with the counts — a period list is not
            // a period detail (the shape `TaskResource` uses for the same reason).
            'items_count' => $this->whenCounted('items'),

            // What the month comes to, and **only where the caller summed it**. See
            // `netTotal()` for why this is a spread and not a `when()`.
            ...$this->netTotal(),

            'permissions' => [
                'can_calculate' => $this->allows($viewer, 'calculate'),
                'can_review' => $this->allows($viewer, 'review'),
                'can_approve' => $this->allows($viewer, 'approve'),
                'can_lock' => $this->allows($viewer, 'lock'),
                'can_reverse_lock' => $this->allows($viewer, 'reverseLock'),
                'can_mark_paid' => $this->allows($viewer, 'markPaid'),
                // Polish 005: a Draft can be moved to the month it pays for, by whoever may draft.
                'can_change_month' => $viewer !== null
                    && $this->resource->status === PayrollStatus::Draft
                    && Gate::forUser($viewer)->allows('create', PayrollPeriod::class),
            ],
        ];
    }

    /**
     * The most recent lock reversal, or null if there has never been one.
     *
     * The reason travels, to everybody who may see a period at all. It is not a private note —
     * it is the explanation for why a month that was closed is open again, and the Accountant,
     * whose finance edits were unblocked by it, is the person who most needs to read it. The
     * complete history of every lock and every reversal is in `audit_logs`, which is ADMIN-only
     * (Part C §1) and append-only; this is the current state of one row.
     *
     * @return array<string, mixed>|null
     */
    private function lockReversal(): ?array
    {
        if ($this->resource->lock_reversal_reason === null) {
            return null;
        }

        return [
            'reason' => $this->resource->lock_reversal_reason,
            'by' => $this->resource->lockReverser?->name,
        ];
    }

    /**
     * `['net_total' => '5900.00']` when the caller loaded the sum, and `[]` when it did not.
     *
     * ## Why the key is absent rather than zero
     *
     * A period list and a period detail both print *what this month comes to*, and a caller
     * that did not ask must not be answered `0.00` — which is a statement that the month is
     * worth nothing, and indistinguishable from a month whose lines all net to zero. Same rule
     * `items_count` follows one line above, and the same rule `FinanceCategoryResource` follows
     * for `usage_count`: **absent is not zero**.
     *
     * A spread rather than `$this->when()`, for `PayrollItemResource::adminNotes()`'s exact
     * reason: `when()` survives a later refactor into a merge as a `null`, and a null total on
     * a screen about money reads as a figure rather than as a missing question.
     *
     * ## The string is never parsed
     *
     * `withSum('items', 'net_salary')` puts PostgreSQL's own `numeric` sum on the model, and it
     * arrives as an exact decimal string. It is padded to two places here with string
     * operations only — no `(float)`, no `number_format()`, no `round()` — because a sum of
     * everybody's pay is the last figure in this application that should meet a binary float
     * (decision 8-4). The screen renders it through `formatMoney()` and nothing else.
     *
     * @return array<string, mixed>
     */
    private function netTotal(): array
    {
        $attributes = $this->resource->getAttributes();

        if (! array_key_exists('items_sum_net_salary', $attributes)) {
            return [];
        }

        return ['net_total' => self::twoPlaces($attributes['items_sum_net_salary'])];
    }

    /**
     * An exact decimal string with exactly two places, by moving characters about.
     *
     * `SUM(numeric(12,2))` over no rows is SQL `NULL`, which is the honest total `0.00` for a
     * month nobody is on.
     */
    private static function twoPlaces(mixed $value): string
    {
        $raw = trim((string) ($value ?? ''));

        if ($raw === '') {
            $raw = '0';
        }

        if (! str_contains($raw, '.')) {
            return $raw.'.00';
        }

        [$whole, $fraction] = explode('.', $raw, 2);

        return $whole.'.'.str_pad(substr($fraction, 0, 2), 2, '0');
    }

    private function allows(?User $viewer, string $ability): bool
    {
        return $viewer !== null && Gate::forUser($viewer)->allows($ability, $this->resource);
    }
}
