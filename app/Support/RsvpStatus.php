<?php

namespace App\Support;

/**
 * A participant's answer to an invitation (master prompt Part D §20:
 * `meeting_participants (meeting_id, user_id, rsvp_status)`).
 *
 * ## Three values, and why not four
 *
 * Part D §12 asks for *"participants + RSVP"* and never enumerates the answers. Google
 * Calendar's own vocabulary is four — `needsAction`, `accepted`, `declined`, `tentative` — and
 * copying it wholesale would be building to a driver that does not exist yet (Part H: nothing
 * is built ahead of the phase that needs it). `tentative` is the one that earns nothing today:
 * no screen in this slice branches on it, no notification is owed for it, and a participant who
 * means "probably" says so in the meeting's agenda thread.
 *
 * So: **pending, accepted, declined**. `pending` is the state everybody is created in, and it
 * is a real answer rather than a null — "has not replied" is information the organiser wants,
 * and a nullable column would make "not invited" and "invited, silent" the same value.
 *
 * If `CalendarApiOneWay` ever pushes attendee responses, `tentative` becomes one case here and
 * one branch wherever the label is drawn. That is the whole cost of leaving it out now.
 *
 * ## The organiser is a participant too
 *
 * `MeetingService::schedule()` always writes the organiser a participant row, accepted. They
 * called the meeting, so their answer is not in doubt, and having them in the same table as
 * everybody else is what lets one query answer "who is in this room" without a union.
 */
enum RsvpStatus: string
{
    /** Invited, has not answered. The default, and never null. */
    case Pending = 'pending';

    case Accepted = 'accepted';

    case Declined = 'declined';

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
            self::Pending => 'No answer yet',
            self::Accepted => 'Going',
            self::Declined => 'Not going',
        };
    }

    /**
     * The `StatusBadge` key. Resolved on the server so no Vue computed holds a second copy of
     * this map (decision 2-37), and never the only carrier of the meaning — every surface
     * prints `label()` beside it (DESIGN.md §5.6).
     */
    public function tone(): string
    {
        return match ($this) {
            self::Pending => 'waiting',
            self::Accepted => 'done',
            self::Declined => 'cancelled',
        };
    }
}
