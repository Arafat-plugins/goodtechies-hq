<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a file, or the file being offered, is not in a state the write accepts — a type
 * the application does not store, a size over the limit, a new version of something that is not
 * the current version.
 *
 * The sibling of TaskStateException and ProjectStateException, and distinct from
 * AuthorizationException for the same reason: the user may well be allowed, the file is simply
 * not something this application will take. Over HTTP the Form Request says so first, against
 * the field; this is the same rule at the only place that writes bytes, so a job or a console
 * command cannot get round it.
 */
class FileStateException extends RuntimeException
{
    public static function tooLarge(int $bytes, int $limit): self
    {
        return new self(sprintf(
            'That file is %s and the limit is %s.',
            self::megabytes($bytes),
            self::megabytes($limit),
        ));
    }

    public static function empty(): self
    {
        return new self('That file is empty.');
    }

    /**
     * @param  list<string>  $allowed
     */
    public static function typeNotAllowed(string $extension, array $allowed): self
    {
        return new self(sprintf(
            '%s files are not accepted. Allowed: %s.',
            $extension === '' ? 'Extensionless' : strtoupper($extension),
            implode(', ', $allowed),
        ));
    }

    public static function mimeMismatch(string $extension, string $mime): self
    {
        return new self(sprintf(
            'That file says it is a .%s but its contents are %s.',
            $extension,
            $mime,
        ));
    }

    public static function notTheCurrentVersion(): self
    {
        return new self('Only the current version of a file can be replaced.');
    }

    public static function unknownOwner(string $class): self
    {
        return new self(sprintf('%s cannot own files.', class_basename($class)));
    }

    public static function upload(): self
    {
        return new self('That upload did not arrive intact. Try again.');
    }

    private static function megabytes(int $bytes): string
    {
        return round($bytes / 1048576, 1).' MB';
    }
}
