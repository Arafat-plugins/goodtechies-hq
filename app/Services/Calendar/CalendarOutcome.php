<?php

namespace App\Services\Calendar;

/**
 * What a calendar driver learned, handed back for `MeetingService` to store.
 *
 * A third file in a folder the brief named two for, and it earns its place: without it the
 * interface's three verbs would each have needed a different return type (a pair of ids, a
 * bool, a string), and `MeetingService` would have had a branch per verb per driver. One value
 * object means the service applies every outcome the same way and the second driver cannot
 * invent a fourth shape.
 *
 * Immutable, and constructed through the four named factories rather than the constructor, so
 * that the states a driver may report are a closed list you can read in one screen:
 *
 *   | factory                  | means                                                      |
 *   | ------------------------ | ---------------------------------------------------------- |
 *   | `nothing()`              | the driver did nothing and had nothing to say               |
 *   | `linked($id, $link)`     | a remote event exists; here is its id and its Meet link     |
 *   | `remoteEventRemoved()`   | the remote event is gone, because this driver deleted it    |
 *   | `manualActionNeeded($s)` | a human must finish this on Google; `$s` is what to tell them |
 *
 * **Nothing here is nullable-by-accident.** `googleEventId` and `meetLink` are null exactly
 * when the driver produced none, and `MeetingService` is written to treat null as *"leave what
 * is already on the row alone"* rather than *"clear it"* — otherwise a manual driver's empty
 * `schedule()` outcome would wipe the link the organiser had just pasted into the same form.
 */
final readonly class CalendarOutcome
{
    private function __construct(
        /** The remote calendar event's id, when the driver made or found one. */
        public ?string $googleEventId = null,
        /** The Meet URL, when the driver produced one itself. */
        public ?string $meetLink = null,
        /** Did this driver actually delete the remote event? Only ever true from `cancel()`. */
        public bool $remoteEventRemoved = false,
        /**
         * What a human still has to do, in the words the activity trail will carry — or null
         * when nothing is owed. This is spec §47's *"Calendar event cancelled via API"* seen
         * from the manual driver's side: the event is not cancelled, so somebody is told.
         */
        public ?string $manualAction = null,
    ) {}

    /** The driver had nothing to do and nothing to report. */
    public static function nothing(): self
    {
        return new self;
    }

    /**
     * A remote event exists for this meeting.
     *
     * Either half may be null: an event can exist without a Meet link (a plain calendar entry),
     * and a link can exist without an event id (the manual driver's pasted link, which is
     * stored by the service from the form rather than returned from here).
     */
    public static function linked(?string $googleEventId, ?string $meetLink): self
    {
        return new self(googleEventId: $googleEventId, meetLink: $meetLink);
    }

    /** The driver deleted the remote event, as Part D §12 requires of the API variant. */
    public static function remoteEventRemoved(): self
    {
        return new self(remoteEventRemoved: true);
    }

    /** The driver cannot finish this; a human must. The sentence is recorded, not thrown. */
    public static function manualActionNeeded(string $instruction): self
    {
        return new self(manualAction: $instruction);
    }

    /** Is there anything on this outcome for `MeetingService` to write to the row? */
    public function touchesMeeting(): bool
    {
        return $this->googleEventId !== null || $this->meetLink !== null;
    }
}
