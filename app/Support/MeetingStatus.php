<?php

namespace App\Support;

/**
 * What a meeting can be (master prompt Part D §12).
 *
 * **Two statuses, and the absence of a third is the decision.** Part D §12 names exactly one
 * transition — *"Cancel: status Cancelled, participants notified, linked tasks not deleted"* —
 * and it never names a `completed`. This enum therefore has `scheduled` and `cancelled` and
 * nothing else.
 *
 * ## Why there is no `completed`
 *
 * Whether a meeting has happened is `end_at < now()`. That is already stored, already indexed,
 * and already the thing every screen sorts and filters on. A `completed` status would be a
 * SECOND statement of the same fact, and a second statement of a fact is a thing somebody has
 * to keep in step:
 *
 *   - it can only become true by a job running, so a night of downtime leaves yesterday's
 *     meetings claiming to be upcoming — the failure mode decision 2-x records for a stored
 *     overdue flag, which is why `Task::scopeOverdue()` is a query and not a column;
 *   - moving a meeting's `start_at` forward would have to move the status BACK, which is not a
 *     transition anybody would write down;
 *   - and the two can disagree, at which point a list filtered on the status and a detail page
 *     reading the clock say different things about the same row.
 *
 * So `hasHappened()` lives on the model, reads `end_at`, and is computed every time it is
 * asked. A meeting that is over is still `scheduled`: the status records *whether it was called
 * off*, not *where the clock is*. Those are genuinely different questions and a cancelled
 * meeting in the past is the case that proves it — it did not happen, and no amount of clock
 * reading would tell you.
 *
 * `rsvp_status` is its own enum for the same reason `LeaveStatus` is separate from
 * `TaskStatus`: a participant's answer is about the participant, not about the meeting.
 */
enum MeetingStatus: string
{
    /** Called, not called off. The status every meeting is born in and most die in. */
    case Scheduled = 'scheduled';

    /** Called off. Linked tasks survive (Part D §12); the Meet link stops being worth clicking. */
    case Cancelled = 'cancelled';

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
            self::Scheduled => 'Scheduled',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * The `StatusBadge` key for a meeting whose clock position is not known.
     *
     * Deliberately NOT the whole answer: a meeting that is over reads `done` and one still to
     * come reads `waiting`, and only the row knows which it is. `Meeting::tone()` is the method
     * a payload calls; this one is what it falls back to. Keeping the clock out of the enum is
     * what stops the enum growing a `completed` case by the back door.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Scheduled => 'waiting',
            self::Cancelled => 'cancelled',
        };
    }
}
