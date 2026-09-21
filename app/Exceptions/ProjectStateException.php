<?php

namespace App\Exceptions;

use App\Support\ProjectStatus;
use RuntimeException;

/**
 * Thrown when a project's own state forbids the write — an archived project being changed, or
 * a status transition the lifecycle does not allow. Distinct from AuthorizationException: the
 * user may well be allowed, the project is simply not in a state that accepts it.
 */
class ProjectStateException extends RuntimeException
{
    public static function archived(string $action): self
    {
        return new self("This project is archived and cannot be {$action}. Unarchive it first.");
    }

    public static function alreadyArchived(): self
    {
        return new self('This project is already archived.');
    }

    public static function notArchived(): self
    {
        return new self('This project is not archived.');
    }

    public static function transition(ProjectStatus $from, ProjectStatus $to): self
    {
        return new self(sprintf(
            'A project cannot go from %s to %s.',
            $from->label(),
            $to->label(),
        ));
    }
}
