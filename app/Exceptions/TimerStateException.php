<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when the timer's own state forbids the write — starting a second one, pausing what is
 * already paused, stopping what is already stopped.
 *
 * The sibling of `TaskStateException`, and distinct from `AuthorizationException` for the same
 * reason: the person is perfectly well allowed to use the timer, the timer is simply not in a
 * state that accepts this. A refusal here comes back as a flash error on the page the request
 * came from, never as a 403 — a 403 would tell a remote employee they may not track time.
 */
class TimerStateException extends RuntimeException
{
    /**
     * The one the partial unique index exists for. Reachable from the check in the service AND
     * from the catch block behind it, because two requests can pass the check in the same
     * millisecond and only the index can settle it.
     */
    public static function alreadyOpen(): self
    {
        return new self(
            'A timer is already going. Stop it before you start another one — '
            .'only one session can be open at a time.'
        );
    }

    public static function alreadyStopped(): self
    {
        return new self('That session has already been stopped.');
    }

    /**
     * A decision — approve or reject — asked about a session that is still going.
     *
     * There is no agreed length to rule on yet: the number would go on moving after the
     * sign-off. The same reason `TimeEntryPolicy::update()` refuses to correct an open entry.
     */
    public static function stillRunning(): self
    {
        return new self(
            'That session is still going, so there are no final hours to approve or refuse yet. '
            .'Stop it first.'
        );
    }

    public static function notRunning(): self
    {
        return new self('That session is already paused.');
    }

    public static function notPaused(): self
    {
        return new self('That session is not paused, so there is nothing to resume.');
    }

    public static function endsBeforeItStarts(): self
    {
        return new self('The finish time has to be after the start time.');
    }

    public static function inTheFuture(): self
    {
        return new self('Time cannot be recorded for a moment that has not happened yet.');
    }

    /**
     * A manual entry, or an edit, that would claim more hours in a day than there are.
     */
    public static function longerThanADay(): self
    {
        return new self('A single entry cannot be longer than 24 hours. Split it across the days it covers.');
    }
}
