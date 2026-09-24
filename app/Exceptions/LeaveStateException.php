<?php

namespace App\Exceptions;

use App\Support\LeaveStatus;
use RuntimeException;

/**
 * Thrown when the state of a leave request — or of the days it asks for — forbids the write.
 *
 * The sibling of `TaskStateException` and `AttendanceStateException`, and distinct from
 * `AuthorizationException` for the same reason: the person may well be allowed to apply for
 * leave, the request is simply not one this application can accept. A refusal here comes back
 * as a flash error on the page the request came from, never as a 403.
 *
 * Every message names the thing that is wrong and the number behind it, because these are read
 * by somebody who is trying to book a week off and needs to know what to change.
 */
class LeaveStateException extends RuntimeException
{
    /**
     * The status machine was bypassed: something wrote `leave_requests.status` without going
     * through `LeaveRequest::applyTransition()`, which is the one door `LeaveService` uses.
     *
     * A programming error rather than a user error, and deliberately loud — it is what makes
     * "a request's status moves through one door" a property of the code rather than a promise
     * in a comment (decision 2-9).
     */
    public static function statusWrittenOutsideTheMachine(): self
    {
        return new self(
            'A leave request status was written without going through the transition machine. '
            .'Use LeaveService; LeaveRequest::applyTransition() is its only door.',
        );
    }

    public static function transition(?LeaveStatus $from, LeaveStatus $to): self
    {
        return new self(sprintf(
            'A leave request cannot go from %s to %s.',
            $from?->label() ?? 'an unknown status',
            $to->label(),
        ));
    }

    /**
     * The range asked for holds none of this employee's working days — a request for a Friday
     * on a Sunday-to-Thursday week.
     */
    public static function noWorkingDays(string $from, string $to): self
    {
        return new self(sprintf(
            'There are no working days between %s and %s on this schedule, so there is no leave to take.',
            $from,
            $to,
        ));
    }

    public static function endBeforeStart(): self
    {
        return new self('A leave request cannot end before it starts.');
    }

    /**
     * Part D §9: "overlapping pending/approved requests are refused".
     */
    public static function overlaps(string $from, string $to, string $status): self
    {
        return new self(sprintf(
            'These dates overlap a leave request that is already %s (%s to %s).',
            mb_strtolower($status),
            $from,
            $to,
        ));
    }

    /**
     * A balance-capped type with not enough left. Named in days, both numbers, because "you do
     * not have enough" is not an answer anybody can act on.
     */
    public static function insufficientBalance(string $type, int $asked, int $available): self
    {
        return new self(sprintf(
            '%s leave asks for %d %s and %d %s left.',
            $type,
            $asked,
            $asked === 1 ? 'day' : 'days',
            $available,
            $available === 1 ? 'day is' : 'days are',
        ));
    }

    /**
     * A balance row was asked for on a type that has no cap. Unpaid and Other have no balance
     * (Part D §9), so there is nothing to hold a number.
     */
    public static function typeHasNoBalance(string $type): self
    {
        return new self(sprintf('%s leave has no balance, so there is no number to set.', $type));
    }

    public static function balanceCannotBeNegative(): self
    {
        return new self('A leave balance cannot be negative.');
    }

    /**
     * Deciding your own request. The same rule `TimerService` keeps for a time entry
     * (decision 4-21): the refusal is about ownership, not about a missing key.
     */
    public static function cannotDecideOwn(): self
    {
        return new self('You cannot rule on your own leave request. Ask the other Admin.');
    }
}
