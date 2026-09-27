<?php

namespace App\Http\Requests\Meeting;

/**
 * Editing a meeting — the same fields, validated the same way, with one difference that
 * matters enough to be the whole reason this class exists.
 *
 * ## An absent `participants` key means *leave the room alone*
 *
 * `MeetingService::update()` takes `?array $participantUserIds` and documents the distinction:
 * `null` leaves the participant list untouched, `[]` empties it down to the organiser. A form
 * that simply does not manage participants — a future "edit the agenda" panel, an import, a
 * `PUT` written by hand — must not be able to clear somebody's meeting by omission, and every
 * RSVP already given with it.
 *
 * On a **create** the same absence means the opposite: nobody was invited, so the meeting is
 * the organiser's alone. That is why the two requests answer `participantIds()` differently and
 * why the rule set itself is inherited unchanged.
 *
 * The edit form in this phase always posts the list, so this is the path nothing takes today.
 * It is the path the next caller takes.
 */
class UpdateMeetingRequest extends StoreMeetingRequest
{
    /**
     * @return list<int>|null
     */
    public function participantIds(): ?array
    {
        if (! $this->has('participants')) {
            return null;
        }

        return array_values(array_map('intval', $this->validated()['participants'] ?? []));
    }
}
