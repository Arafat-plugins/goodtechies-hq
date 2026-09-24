<?php

namespace App\Support;

/**
 * What a leave request can be (master prompt Part D §9: `status ∈ {pending, approved, rejected,
 * correction_requested}`).
 *
 * Four statuses and one machine. The map below is the whole of it, and it is the ONLY statement
 * of which moves exist: `LeaveRequest::applyTransition()` re-checks it, and the model's guard
 * throws if anything writes `status` without going through that door (decision 2-9, applied
 * here for the same reason it was applied to tasks — see `LeaveRequest`).
 *
 * ## Why the map looks like this
 *
 *   - **pending** is where a request is born and the only status a decision can be made from
 *     first. All three of Part D §9's verbs hang off it.
 *   - **correction_requested** is not a refusal, it is a question — so the employee can answer
 *     it (back to `pending`, which is what "resubmit" means) and the Admin who asked can still
 *     rule on it without waiting, because an Admin who changes their mind must not have to ask
 *     the employee to resubmit first so that they can then reject it.
 *   - **approved** and **rejected** are terminal. There is no un-approve: an approval has
 *     already decremented a balance and written attendance rows, and reversing that quietly
 *     from a status change would be a second write path for facts that are not this table's.
 *     The spec gives no verb for it, so neither does this.
 *
 * There is no `cancelled`. Part D §9's list has four statuses and an employee withdrawing a
 * request is not among them; inventing it would be building ahead of the plan (Part H).
 */
enum LeaveStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case CorrectionRequested = 'correction_requested';

    /**
     * The legal moves. `from value => list of to values`.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        'pending' => ['approved', 'rejected', 'correction_requested'],
        'correction_requested' => ['pending', 'approved', 'rejected'],
        'approved' => [],
        'rejected' => [],
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
     * Is this status one that still holds a place in the calendar?
     *
     * The overlap rule and the exclusion constraint are written against exactly this set —
     * Part D §9: "overlapping pending/approved requests are refused". A rejected request and
     * one sent back for correction hold nothing, so a second request over the same days is
     * fine, which is the whole point of sending one back.
     *
     * @return list<self>
     */
    public static function holding(): array
    {
        return [self::Pending, self::Approved];
    }

    /**
     * @return list<string>
     */
    public static function holdingValues(): array
    {
        return array_map(fn (self $status): string => $status->value, self::holding());
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
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::CorrectionRequested => 'Correction requested',
        };
    }

    /**
     * The `StatusBadge` key. Four statuses onto four distinct keys, resolved on the server so
     * that no Vue computed holds a second copy of this map (decision 2-37).
     *
     * The tone is never the only carrier: every surface prints `label()` beside it, which is
     * what DESIGN.md §5.6 requires and what §1.4's measurement — two of the eight status tones
     * sit ΔE 0.16 apart under deuteranopia — makes non-negotiable. `waiting` and `changes` are
     * two of the pairs that measurement is about, which is why *Pending* and *Correction
     * requested* never appear as a bare dot anywhere in this feature.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Pending => 'waiting',
            self::Approved => 'done',
            self::Rejected => 'cancelled',
            self::CorrectionRequested => 'changes',
        };
    }

    /**
     * Is a decision still owed on this request? The queue's default filter, and the Admin
     * dashboard's count.
     */
    public function isOpen(): bool
    {
        return $this === self::Pending || $this === self::CorrectionRequested;
    }
}
