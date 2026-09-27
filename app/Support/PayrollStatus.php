<?php

namespace App\Support;

/**
 * What a payroll period can be (master prompt Part D §14: `DRAFT → CALCULATED → REVIEWED →
 * APPROVED → LOCKED → PAID`).
 *
 * Six statuses and one machine. The map below is the ONLY statement of which moves exist:
 * `PayrollPeriod::applyTransition()` re-checks it, and the model's guard throws if anything
 * writes `status` without going through that door (decision 2-9, applied here for the fifth
 * time — `Task`, `LeaveRequest` and now this).
 *
 * That guard matters more here than anywhere it has been applied before. A payroll period is
 * not a word changing colour on a screen: `LOCKED` and `PAID` **close the month to the whole
 * finance ledger** (Part D §13, and `FinanceService::assertPeriodIsOpen()`), so a second write
 * path to `status` would be a second way to freeze — or quietly unfreeze — every income and
 * expense row dated in that month, with nothing in the audit log to say who did it.
 *
 * ## Why the map looks like this
 *
 *   - **The forward path is Part D §14's arrow, verbatim**, one move per step. Each step has a
 *     different holder (see `PayrollPeriodPolicy`): `payroll.draft` presses Calculate,
 *     `payroll.approve` does everything from Review onwards.
 *   - **`locked → approved` is the one backward move**, and it is the lock reversal Part D §14
 *     and Part C §4 both name: *"only ADMIN can reverse a lock (requires reason,
 *     audit-logged)"*. It lands on `approved` rather than on `reviewed` or `draft` because
 *     reversing a lock un-closes the month — it does not un-approve the figures, which nobody
 *     asked to undo.
 *   - **`paid` is terminal.** Part D §14 gives no verb after *Paid → payslip generated and
 *     released*, and by then the money has left the bank. Inventing an un-pay would be building
 *     ahead of the plan (Part H), and it would be the one transition that makes a released
 *     payslip change after the fact. It is listed in the report as a question for the client.
 *   - **There is no `cancelled`, and no `reviewed → calculated` send-back.** Part D §14
 *     enumerates six statuses and this is those six.
 *
 * ## Calculating is not always a transition
 *
 * Pressing Calculate on a `draft` period moves it to `calculated`. Pressing it again on a
 * `calculated` period recomputes and moves nothing — Part D §14 has the Accountant *"fill and
 * adjust"* the figures and then calculate, which is a loop, not a one-shot. So the map has no
 * `calculated → calculated` self-move (a self-move is not a move, and a machine that lists one
 * invites a listener to fire on it); `allowsCalculation()` below is what the service asks, and
 * `PayrollService::calculate()` transitions only when the status actually changes.
 */
enum PayrollStatus: string
{
    case Draft = 'draft';
    case Calculated = 'calculated';
    case Reviewed = 'reviewed';
    case Approved = 'approved';
    case Locked = 'locked';
    case Paid = 'paid';

    /**
     * The legal moves. `from value => list of to values`.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        'draft' => ['calculated'],
        'calculated' => ['reviewed'],
        'reviewed' => ['approved'],
        'approved' => ['locked'],
        // 'approved' here is the lock reversal — ADMIN only, and a reason is required.
        'locked' => ['paid', 'approved'],
        'paid' => [],
    ];

    public function canTransitionTo(self $to): bool
    {
        return in_array($to->value, self::TRANSITIONS[$this->value] ?? [], true);
    }

    /**
     * The statuses this one can move to, as cases.
     *
     * @return list<self>
     */
    public function transitions(): array
    {
        return array_map(
            fn (string $value): self => self::from($value),
            self::TRANSITIONS[$this->value] ?? [],
        );
    }

    /**
     * **Does this status close the month to the finance ledger?**
     *
     * Part D §13, word for word: *"a finance record is blocked when its `date` falls in the
     * month of a `payroll_periods` row whose status is `LOCKED` or `PAID`"*. This method is the
     * single statement of that set, and `FinanceService::assertPeriodIsOpen()` is its only
     * caller outside this file — so the question *"is September closed?"* has one answer in
     * this application, not one per screen.
     */
    public function closesTheMonth(): bool
    {
        return $this === self::Locked || $this === self::Paid;
    }

    /**
     * The same set as raw strings, for the `whereIn` that asks the database.
     *
     * @return list<string>
     */
    public static function closingValues(): array
    {
        return array_map(
            fn (self $status): string => $status->value,
            array_values(array_filter(self::cases(), fn (self $status): bool => $status->closesTheMonth())),
        );
    }

    /**
     * May Calculate be pressed on a period in this status?
     *
     * Draft (the first time) and Calculated (every time after that). See the class note on why
     * this is a predicate rather than a self-transition in the map.
     */
    public function allowsCalculation(): bool
    {
        return $this === self::Draft || $this === self::Calculated;
    }

    /**
     * May the ACCOUNTANT still change the figures on an item in a period in this status?
     *
     * Part D §14: *"Admin reviews and approves (**read-only to Accountant afterwards**)"* — so
     * the line is drawn at approval and not before it, which is what this returns. A period
     * that has been reviewed but not yet approved is still the Accountant's to correct, which
     * is the literal reading of the spec and the useful one: *review* is how an Admin finds a
     * wrong figure, and the person who fixes it is the person who entered it.
     *
     * (What that leaves open — whether an edit after review silently invalidates the review —
     * is a question for the client, and it is in this slice's report rather than answered here.)
     */
    public function isOpenToAccountant(): bool
    {
        return $this === self::Draft || $this === self::Calculated || $this === self::Reviewed;
    }

    /**
     * May an ADMIN still change the figures on an item in a period in this status?
     *
     * Everything the Accountant may, plus `approved` — an Admin who spots an error between
     * approving and locking must not have to reverse anything to fix it, because there is
     * nothing to reverse yet. **From `locked` onwards nobody edits an item**: that is what
     * locking a month means, and it is the same set of statuses that closes the finance ledger.
     */
    public function isOpenToAdmin(): bool
    {
        return $this->isOpenToAccountant() || $this === self::Approved;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $status): string => $status->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Calculated => 'Calculated',
            self::Reviewed => 'Reviewed',
            self::Approved => 'Approved',
            self::Locked => 'Locked',
            self::Paid => 'Paid',
        };
    }

    /**
     * The `StatusBadge` key, resolved on the server so that no Vue computed holds a second copy
     * of this map (decision 2-37).
     *
     * Six statuses onto five of DESIGN.md's eight tones. `approved` and `locked` deliberately
     * share `waiting`: both mean *the figures are settled and the money has not moved yet*, and
     * the alternative was inventing a ninth status colour, which is a change to `app.css` and
     * DESIGN.md that this slice does not own. Sharing costs nothing because **the tone is never
     * the only carrier** — every surface prints `label()` beside it (DESIGN.md §5.6), which is
     * non-negotiable here for the reason §1.4's measurement gives: two of the eight tones sit
     * ΔE 0.16 apart under deuteranopia.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Draft => 'backlog',
            self::Calculated => 'progress',
            self::Reviewed => 'review',
            self::Approved, self::Locked => 'waiting',
            self::Paid => 'done',
        };
    }
}
