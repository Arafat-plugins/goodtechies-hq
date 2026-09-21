<?php

namespace App\Exceptions;

use App\Support\TaskStatus;
use RuntimeException;

/**
 * Thrown when a task's own state forbids the write — an archived task being edited, a move the
 * status machine does not have, a completion with nobody's work summary behind it.
 *
 * The sibling of ProjectStateException, and distinct from AuthorizationException for the same
 * reason: the user may well be allowed, the task is simply not in a state that accepts it. A
 * refusal here comes back as a flash error on the page the request came from, not as a 403.
 */
class TaskStateException extends RuntimeException
{
    public static function archived(string $action): self
    {
        return new self("This task is archived and cannot be {$action}. Unarchive it first.");
    }

    public static function alreadyArchived(): self
    {
        return new self('This task is already archived.');
    }

    public static function notArchived(): self
    {
        return new self('This task is not archived.');
    }

    public static function transition(?TaskStatus $from, TaskStatus $to): self
    {
        return new self(sprintf(
            'A task cannot go from %s to %s.',
            $from?->label() ?? 'an unknown status',
            $to->label(),
        ));
    }

    /**
     * The status machine was bypassed: something wrote `tasks.status` without going through
     * Task::applyTransition(), which is the one door TaskService::transition() uses.
     *
     * This is a programming error rather than a user error, and it is deliberately loud: it is
     * what makes "every drag goes through the same checks as the form" a property of the code
     * rather than a promise in a comment.
     */
    public static function statusWrittenOutsideTheMachine(): self
    {
        return new self(
            'A task status was written without going through the transition machine. '
            .'Use TaskService::transition(); Task::applyTransition() is its only door.',
        );
    }

    public static function workSummaryRequired(TaskStatus $to): self
    {
        return new self(sprintf(
            'A work summary is required before a task can be moved to %s.',
            $to->label(),
        ));
    }

    public static function primaryWorkSummaryRequired(string $primary): self
    {
        return new self(sprintf(
            'Completion needs %s\'s work summary — they are the primary assignee. '
            .'Hand the task over first if somebody else is finishing it.',
            $primary,
        ));
    }

    public static function reasonRequired(TaskStatus $to): self
    {
        return new self(sprintf('Moving a task to %s has to say why.', $to->label()));
    }

    public static function notAnAssignee(): self
    {
        return new self('A task can only be handed over to somebody already assigned to it.');
    }

    public static function alreadyPrimary(string $name): self
    {
        return new self("{$name} is already the primary assignee.");
    }

    public static function dependencyCycle(): self
    {
        return new self('That dependency would make the two tasks wait for each other.');
    }

    public static function dependsOnItself(): self
    {
        return new self('A task cannot depend on itself.');
    }

    public static function dependencyAcrossProjects(): self
    {
        return new self('A task can only depend on another task in the same project.');
    }

    public static function tooManyAssignees(int $max): self
    {
        return new self("A task takes at most {$max} assignees.");
    }

    public static function primaryNotAssigned(): self
    {
        return new self('The primary assignee has to be one of the assignees.');
    }

    /**
     * A tag is global or belongs to exactly one project, and a task only takes the ones its own
     * project can use.
     */
    public static function tagNotUsable(): self
    {
        return new self('A tag has to be a global one or one of this project\'s own.');
    }
}
