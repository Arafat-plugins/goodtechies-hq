<?php

namespace App\Services\Import\Draft;

/**
 * One row that did not become what it wanted to be, and why.
 *
 * Two kinds, and the difference matters to whoever reads the report:
 *
 *   - **skipped** — the row was not imported at all. Its status had no mapping, or its parent
 *     was skipped, or it had no title. Nothing about it is in the database.
 *   - **unmapped** — the row WAS imported, but part of it could not be carried across. An
 *     assignee that matches no employee; tracked time for somebody who does not use the timer.
 *     The task exists; the named piece does not.
 *
 * Collapsing the two would produce the report the brief calls useless: "312 rows imported" with
 * no way to tell which of them arrived whole.
 */
final class RowIssue
{
    public const SKIPPED = 'skipped';

    public const UNMAPPED = 'unmapped';

    private function __construct(
        public readonly string $kind,
        public readonly int $line,
        public readonly string $subject,
        public readonly string $why,
    ) {}

    public static function skipped(int $line, string $subject, string $why): self
    {
        return new self(self::SKIPPED, $line, $subject, $why);
    }

    public static function unmapped(int $line, string $subject, string $why): self
    {
        return new self(self::UNMAPPED, $line, $subject, $why);
    }
}
