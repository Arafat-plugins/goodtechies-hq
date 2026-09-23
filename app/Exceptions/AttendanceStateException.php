<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when the day's own state forbids the write — clocking in twice, clocking out of a day
 * nobody clocked into, an edit to a day the schedule says nobody works.
 *
 * The sibling of TaskStateException, and distinct from AuthorizationException for the same
 * reason: the person is perfectly well allowed to clock in, the day is simply not in a state
 * that accepts another one. A refusal here comes back as a flash error on the page the request
 * came from, not as a 403 — the phone at the office door gets a sentence, not a status code.
 */
class AttendanceStateException extends RuntimeException
{
    public static function alreadyClockedIn(string $at): self
    {
        return new self("You already clocked in at {$at} today.");
    }

    public static function alreadyClockedOut(string $at): self
    {
        return new self("You already clocked out at {$at} today.");
    }

    public static function notClockedIn(): self
    {
        return new self('You have not clocked in today, so there is nothing to clock out of.');
    }

    /**
     * The one refusal that is about the schedule rather than about the record. It names the day
     * because "today is not a working day" is a sentence somebody reads at the door and has to
     * be able to argue with.
     */
    public static function notAWorkingDay(string $weekday): self
    {
        return new self("Your schedule has no working hours on {$weekday}. Ask an Admin to change your work schedule if that is wrong.");
    }

    public static function noSchedule(): self
    {
        return new self('You have no work schedule yet, so there is nothing to clock in against. Ask an Admin to set one up.');
    }

    /**
     * Somebody whose work is tracked by the remote timer, or not tracked at all, pressing a
     * clock-in they should never have been offered. It is a state refusal and not a 403: the
     * endpoint's policy has already said they may manage their own attendance — they simply do
     * not have the kind of attendance a clock records.
     */
    public static function notClocked(): self
    {
        return new self('Your work is not tracked by clocking in and out.');
    }

    /**
     * An edit aimed at somebody the office clock does not track. It is the second-person
     * refusal's sibling, written about a third party because it is an Admin who reads it.
     */
    public static function notOfficeAttendance(string $name): self
    {
        return new self("{$name}'s work is not tracked by clocking in and out, so there is no attendance day to record.");
    }

    public static function clockOutBeforeClockIn(): self
    {
        return new self('A clock-out cannot be earlier than the clock-in it ends.');
    }

    public static function futureDay(): self
    {
        return new self('A day that has not happened yet cannot be recorded.');
    }
}
