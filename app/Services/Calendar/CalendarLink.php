<?php

namespace App\Services\Calendar;

use App\Models\Meeting;

/**
 * The calendar seam: everything a meeting needs from Google, expressed so that the two drivers
 * Part D §12 names can both satisfy it.
 *
 * ## Why an interface with one implementation today
 *
 * Part D §12 offers a choice the client has not made — *"Either the organizer connects their
 * own Google account once (OAuth, tokens stored encrypted per user) **or** a service account
 * with domain-wide delegation impersonates a Workspace user — a plain service account cannot
 * create Meet links. Decide at GATE A (PROGRESS.md question 3)"*. Until that answer lands there
 * is exactly one driver, `ManualLink`, and Part D says it *"is always available"*.
 *
 * So this file's job is not abstraction for its own sake. It is to make adding
 * `CalendarApiOneWay` **a class and a binding**, not a refactor of `MeetingService`. That is
 * only true if the interface is shaped around what the API driver will actually need, which is
 * why it looks the way it does:
 *
 *   - **Three verbs, not one.** `schedule`, `reschedule`, `cancel`. The API driver does a
 *     different HTTP call for each (`events.insert`, `events.patch`, `events.delete`) and the
 *     manual driver does nothing for two of them. A single `sync()` would have made the caller
 *     work out which it meant.
 *   - **Every verb returns a `CalendarOutcome` rather than void or a bool.** Creating an event
 *     produces two values the meeting must store (`google_event_id`, `meet_link`); cancelling
 *     one produces a fact the meeting must record (whether the remote event is actually gone).
 *     A void return would have forced the service to ask the driver a second question
 *     afterwards, and a bool would have thrown away the ids.
 *   - **Nothing here writes to the database.** A driver returns what it learned;
 *     `MeetingService` is the only thing that saves it. That is what keeps a failed Google call
 *     from being half-applied, and what lets a test hand `MeetingService` a stub driver without
 *     a database at all.
 *   - **Nothing here throws for the ordinary "I cannot do that".** `ManualLink` cannot delete a
 *     remote event, and that is not an error — it is an outcome, carrying the sentence a human
 *     has to act on. Exceptions are for a driver that tried and failed.
 *
 * ## What `CalendarApiOneWay` will have to do, verb by verb
 *
 *   - `name()` → `'api'`. `createsLinksItself()` → `true`. `startUrl()` → `null`: there is no
 *     button to press, because the link arrives with the event.
 *   - `schedule()` → `events.insert` on the organiser's calendar with
 *     `conferenceDataVersion=1` and a `createRequest` whose `requestId` is idempotent for this
 *     meeting (the meeting's id is the obvious one), then return
 *     `CalendarOutcome::linked($event->id, $event->hangoutLink)`. The partial unique index
 *     `meetings_one_per_google_event` is what stops a retried insert attaching the same event
 *     to two rows.
 *   - `reschedule()` → `events.patch` with the new `start`/`end`, returning the SAME event id
 *     and link (they do not change). If the meeting has no `google_event_id` — it predates the
 *     driver being switched on — it must fall through to `schedule()` rather than fail.
 *   - `cancel()` → `events.delete`, then `CalendarOutcome::remoteEventRemoved()`. Part D §12 and
 *     spec §47 both name this explicitly: *"the Google event is **deleted/cancelled through the
 *     API**"*. A 404 or 410 from Google means somebody already deleted it and is a SUCCESS, not
 *     a failure — the desired state is reached.
 *   - Authentication is the part that is blocked: per-organiser OAuth needs a
 *     `google_oauth_tokens` table (Part D §20 says it exists *"only with the OAuth variant"*,
 *     which is why this slice does not create it), and domain-wide delegation needs a Workspace
 *     administrator to grant the scope. Either way the driver will need the ORGANISER, which is
 *     why every method here takes the whole `Meeting` and not a bag of fields.
 *
 * `MeetingCalendarLinkTest` asserts this contract rather than `ManualLink`'s behaviour where it
 * can, so the second driver arrives with a test already written for it.
 */
interface CalendarLink
{
    /**
     * The driver's name, as `.env`'s `GOOGLE_CALENDAR_DRIVER` spells it.
     *
     * Read by Admin → Settings, which shows the calendar driver read-only (Part D §20: it lives
     * in `.env` and cannot switch at runtime, so it is not a `settings` key).
     */
    public function name(): string;

    /**
     * Does this driver produce a Meet link by itself?
     *
     * `false` for the manual driver, and the screens read it rather than asking `name() ===
     * 'manual'`: it is what decides whether the create form shows a *Create Meet Link* button
     * and a paste box, or shows nothing because the link will simply appear.
     */
    public function createsLinksItself(): bool;

    /**
     * The URL the *Create Meet Link* button opens, or null when there is no such button.
     *
     * Part D §12: *"MVP: 'Create Meet Link' opens Google's instant-meeting flow and the
     * organizer pastes the link back"*. It is the driver's to answer, so no Vue file holds a
     * hard-coded Google URL.
     */
    public function startUrl(): ?string;

    /**
     * A meeting has just been created. Returns whatever the calendar produced for it.
     *
     * Called INSIDE `MeetingService::schedule()`'s transaction, before the row is saved with
     * the outcome applied, so a driver that throws leaves no meeting behind.
     */
    public function schedule(Meeting $meeting): CalendarOutcome;

    /**
     * A meeting's time has changed. Returns whatever the calendar now holds for it.
     *
     * Only called when the time actually moved — an agenda typo costs no API call.
     */
    public function reschedule(Meeting $meeting): CalendarOutcome;

    /**
     * A meeting has been called off.
     *
     * The driver that can delete the remote event does so and says it did
     * (`remoteEventRemoved()`). The driver that cannot says that instead, with the sentence a
     * human has to act on (`manualActionNeeded()`). Both are ordinary returns: "I cannot reach
     * Google because there is no Google here" is the manual driver working correctly.
     */
    public function cancel(Meeting $meeting): CalendarOutcome;
}
