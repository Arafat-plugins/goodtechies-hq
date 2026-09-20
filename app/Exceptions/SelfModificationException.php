<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a user tries to change their own role or deactivate themselves.
 */
class SelfModificationException extends RuntimeException
{
    public static function forAction(string $action): self
    {
        return new self("You cannot {$action} your own account. Ask another administrator.");
    }
}
