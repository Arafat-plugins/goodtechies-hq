<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Polish 029: a request to correct a day that cannot be made or answered as asked. Comes back
 * as a flash error on the page, never a 403 — the person is allowed, the day is not in a state
 * that accepts it.
 */
class AttendanceCorrectionException extends RuntimeException
{
    public static function futureDay(): self
    {
        return new self('That day has not happened yet.');
    }

    public static function tooOld(int $days): self
    {
        return new self("Only the last {$days} days can be corrected. Ask an Admin directly.");
    }

    public static function notCorrectable(): self
    {
        return new self('Only a Late, Half day or Absent day can be sent for correction.');
    }

    public static function alreadyPending(): self
    {
        return new self('A correction for that day is already waiting for an answer.');
    }

    public static function alreadyDecided(): self
    {
        return new self('That request has already been answered.');
    }
}
