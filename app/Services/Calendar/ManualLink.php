<?php

namespace App\Services\Calendar;

use App\Models\Meeting;

/**
 * The calendar driver that has no calendar (master prompt Part D §12, the MVP variant):
 * *"'Create Meet Link' opens Google's instant-meeting flow and the organizer pastes the link
 * back"*.
 *
 * `GOOGLE_CALENDAR_DRIVER=manual`, which is the default, and Part D says this driver *"is
 * always available"* — it needs no credentials, no Workspace decision and no network, so it is
 * also what a VPS with no Google account at all runs on.
 *
 * ## It creates nothing, on purpose
 *
 * `schedule()` and `reschedule()` return `CalendarOutcome::nothing()`. The Meet link on a
 * meeting under this driver arrives from the FORM — the organiser pastes it — and is stored by
 * `MeetingService` like the title and the agenda. This class is not where that happens, and it
 * deliberately does not reach out and take the link off the meeting and hand it back as its own
 * "outcome": that would make the two drivers look symmetrical in a way that hides which of them
 * actually talked to Google.
 *
 * ## Cancelling: the honest half of spec §47
 *
 * Spec §47 lists *"Calendar event cancelled via API"* as a case the build must handle, and Part
 * D §12 gives this driver's counterpart of it: *"with the manual driver the record notes 'cancel
 * the Meet manually'"*.
 *
 * So `cancel()` returns `manualActionNeeded()` with that sentence, and `MeetingService` writes
 * it to `activity_logs` against the meeting — where the organiser reads it, where it is
 * timestamped and attributed, and where it survives. It is not an exception, because nothing
 * went wrong: a driver with no API cannot delete a remote event and saying so is the correct
 * behaviour, not a failure of it. And it is not a new column on `meetings`, because it is an
 * event in the meeting's history rather than a state the meeting is in.
 *
 * The sentence is only produced when there is a Meet link to cancel. A meeting nobody ever
 * pasted a link into has no room standing open, and telling its organiser to go and close one
 * would be an instruction to do nothing.
 */
class ManualLink implements CalendarLink
{
    /**
     * What `MeetingService` records on the activity trail when a meeting with a Meet link is
     * cancelled under this driver.
     *
     * A constant so the test asserts the same string the service writes, and so the wording is
     * changed in one place rather than in a service, a test and a translation of both.
     */
    public const CANCEL_INSTRUCTION = 'Cancel the Google Meet manually — this calendar driver cannot delete the event.';

    public function name(): string
    {
        return 'manual';
    }

    /**
     * No. The screens read this to decide whether to draw the *Create Meet Link* button and the
     * paste box at all.
     */
    public function createsLinksItself(): bool
    {
        return false;
    }

    public function startUrl(): ?string
    {
        return MeetLink::NEW_MEETING_URL;
    }

    /**
     * Nothing to do: there is no remote calendar to insert into. The meeting's `meet_link`, if
     * it has one, came from the organiser's paste and is already on the row.
     */
    public function schedule(Meeting $meeting): CalendarOutcome
    {
        return CalendarOutcome::nothing();
    }

    /**
     * Nothing to do again — and notably, **no instruction to the organiser either**.
     *
     * A Meet room is not tied to a time: the same link works whenever the people turn up, so a
     * meeting moved from Tuesday to Thursday needs no action on Google's side. That is the one
     * asymmetry with `cancel()`, and it is why this returns `nothing()` rather than a second
     * manual-action sentence that would train people to ignore the first.
     */
    public function reschedule(Meeting $meeting): CalendarOutcome
    {
        return CalendarOutcome::nothing();
    }

    /**
     * The room stays open until a human closes it, so a human is told — once, on the record.
     */
    public function cancel(Meeting $meeting): CalendarOutcome
    {
        if (trim((string) $meeting->meet_link) === '') {
            return CalendarOutcome::nothing();
        }

        return CalendarOutcome::manualActionNeeded(self::CANCEL_INSTRUCTION);
    }
}
