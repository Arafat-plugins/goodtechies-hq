<?php

namespace App\Exceptions;

use App\Support\PayrollStatus;
use RuntimeException;

/**
 * Thrown when a payroll period or item — or the change being made to it — is not one this
 * application can accept.
 *
 * The sibling of `TaskStateException`, `LeaveStateException`, `AttendanceStateException`,
 * `MeetingStateException` and `FinanceStateException`, and distinct from
 * `AuthorizationException` for the same reason all five are: the Accountant is perfectly
 * entitled to press Calculate, this particular period is simply not one that can be calculated.
 * A refusal here comes back as a flash error on the screen it came from, never as a 403.
 *
 * ## Every refused transition is a SENTENCE, not a silent no-op
 *
 * That is the rule this class exists to keep. A state machine that quietly does nothing when a
 * move is not allowed produces the worst bug report in the world — *"I pressed Approve and
 * nothing happened"* — with no log line, no error and no way to tell a permission problem from
 * a status problem from a dead button. So `PayrollService` throws on **every** refused move,
 * and every sentence below names the status the period is actually in, because that is the one
 * fact the person at the screen does not have.
 */
class PayrollStateException extends RuntimeException
{
    /**
     * A move the machine does not have.
     *
     * `PayrollStatus::TRANSITIONS` is the map and `PayrollPeriod::applyTransition()` is the door
     * — this is what comes back out of it. The legal moves from the current status are in the
     * sentence, because the next question is always *"then what can I do?"*.
     */
    public static function transition(?PayrollStatus $from, PayrollStatus $to): self
    {
        $allowed = $from === null
            ? []
            : array_map(fn (PayrollStatus $status): string => $status->label(), $from->transitions());

        return new self(sprintf(
            'A %s payroll period cannot be moved to %s. %s',
            $from?->label() ?? 'new',
            $to->label(),
            $allowed === []
                ? 'It is final: nothing follows it.'
                : 'From here it can only become '.implode(' or ', $allowed).'.',
        ));
    }

    /**
     * `status` went dirty on an existing row without going through `applyTransition()`.
     *
     * The fifth model in this repo to guard its status this way (decision 2-9). The message
     * names the method because whoever sees this is a developer, not an Accountant.
     */
    public static function statusWrittenOutsideTheMachine(): self
    {
        return new self(
            'A payroll period\'s status may only be changed through PayrollPeriod::applyTransition(), '
            .'which PayrollService is the only caller of. Writing it directly would skip the guards, '
            .'the audit trail and the finance lock.',
        );
    }

    /** Calculate was pressed on a period that is past the point of being calculated. */
    public static function notCalculable(PayrollStatus $status): self
    {
        return new self(sprintf(
            'This period is %s, so its figures can no longer be calculated. Calculate applies to a Draft or Calculated period.',
            strtolower($status->label()),
        ));
    }

    /** Somebody tried to reverse a lock on a period that is not locked. */
    public static function notLocked(PayrollStatus $status): self
    {
        return new self(sprintf(
            'This period is %s, not locked, so there is no lock to reverse.',
            strtolower($status->label()),
        ));
    }

    /**
     * A lock reversal with no reason given.
     *
     * Part D §14 and Part C §4 both require one — *"only ADMIN can reverse a lock (requires
     * reason, audit-logged)"* — and `payroll_periods_reversal_is_whole` is the database's half
     * of it. This is the sentence.
     */
    public static function reversalNeedsAReason(): self
    {
        return new self('Reversing a lock needs a reason. It is recorded on the period and in the audit log.');
    }

    /** An item was edited in a period that no longer accepts edits from this person. */
    public static function itemIsReadOnly(PayrollStatus $status): self
    {
        return new self(sprintf(
            'This payroll period is %s, so its figures can no longer be changed.',
            strtolower($status->label()),
        ));
    }

    /**
     * Two periods for one month.
     *
     * `payroll_periods.month` is UNIQUE, so this is the sentence in front of a constraint the
     * database enforces against every writer.
     */
    public static function monthAlreadyHasAPeriod(string $month): self
    {
        return new self(sprintf('%s already has a payroll period. A month has exactly one.', $month));
    }

    /** A draft was asked for somebody with no salary on record. */
    public static function noSalaryOnRecord(string $employee, string $month): self
    {
        return new self(sprintf(
            '%s has no salary recorded as at %s, so there is nothing to pay them from. Set a salary first.',
            $employee,
            $month,
        ));
    }
}
