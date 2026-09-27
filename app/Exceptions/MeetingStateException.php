<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a meeting — or the change being made to it — is not one this application can
 * accept.
 *
 * The sibling of `TaskStateException`, `LeaveStateException` and `AttendanceStateException`,
 * and distinct from `AuthorizationException` for the same reason all three are: the person is
 * usually perfectly entitled to schedule a meeting, this particular one is simply not a
 * meeting. A refusal here comes back as a flash error on the form it came from, never as a 403.
 *
 * An eighth exception class rather than a bare `InvalidArgumentException`, because the
 * controller slice has to tell these two failure modes apart in one `catch` and a
 * `RuntimeException` from anywhere would match.
 */
class MeetingStateException extends RuntimeException
{
    /**
     * The end is not after the start.
     *
     * The database says this too (`meetings_end_after_start`), and this is not redundant: the
     * constraint is the promise and this is the sentence a person reads. Reaching the database
     * with a backwards range would produce a Postgres error string on a form.
     */
    public static function endBeforeStart(): self
    {
        return new self('A meeting has to end after it starts.');
    }

    /** A time was missing or unparseable. */
    public static function timeMissing(): self
    {
        return new self('A meeting needs a start time and an end time.');
    }

    /**
     * Somebody pasted something that is not a link to a Meet room.
     *
     * The shape check lives in `MeetLink::looksValid()` and is called from here and from the
     * Form Request — see that class for what is accepted and why `meet.google.com/new` is not.
     */
    public static function meetLinkNotRecognised(): self
    {
        return new self(
            'That does not look like a Google Meet link. A joining link looks like '
            .'https://meet.google.com/abc-defg-hij.',
        );
    }

    /** A meeting that has been called off is read-only. */
    public static function alreadyCancelled(): self
    {
        return new self('This meeting has already been cancelled.');
    }

    /**
     * An action item was converted into a task for a project the meeting has no link to.
     *
     * Part D §12 says a converted task arrives with its *"pre-linked project"*, which is the
     * meeting's. Letting the caller name a different one would turn Convert to Task into a
     * general task-creation endpoint that happens to stamp a meeting id on the result.
     */
    public static function actionItemProjectMismatch(): self
    {
        return new self('An action item becomes a task on the meeting\'s own project.');
    }
}
