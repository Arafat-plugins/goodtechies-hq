<?php

namespace App\Services\Import;

use RuntimeException;

/**
 * The export itself is the problem: it is not there, it is not readable, or it has no header
 * row. Every one of these is a sentence the person running the command can act on without
 * reading a stack trace.
 */
final class ImportFileException extends RuntimeException
{
    public static function unreadable(string $path): self
    {
        return new self(sprintf('Could not read %s — check the path and that the file is readable.', $path));
    }

    public static function empty(string $path): self
    {
        return new self(sprintf('%s has no header row, so there is nothing to map columns by.', $path));
    }

    /**
     * @param  list<string>  $expected
     */
    public static function notRecognised(string $path, string $source, array $expected): self
    {
        return new self(sprintf(
            '%s does not look like a %s export: none of its columns is %s. '
            .'Export the view as CSV with the default columns, or pass the matching --from.',
            $path,
            $source,
            implode(', ', $expected),
        ));
    }
}
