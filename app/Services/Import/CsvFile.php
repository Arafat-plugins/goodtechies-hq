<?php

namespace App\Services\Import;

use Generator;

/**
 * A CSV export, read with `fgetcsv` and nothing else (Phase 12: no new dependency).
 *
 * Two things it does beyond opening the file, and both exist because a real export has been
 * through a spreadsheet before it reaches us:
 *
 *   - **The byte-order mark.** Excel writes one in front of the first header, so `Task ID`
 *     arrives as `\xEF\xBB\xBFTask ID` and every lookup for the identity column misses. It is
 *     stripped from the first header only, which is the only place it can legally appear.
 *   - **Header lookup is case- and space-insensitive.** ClickUp writes `Task Name`, a person who
 *     re-saved the file may write `task name`, and neither of those is a different column. The
 *     REAL column names are still recorded and reported, so nobody has to guess which spelling
 *     the file actually used.
 *
 * Rows are yielded one at a time. A ClickUp workspace export is small, but the thing that makes
 * an import trustworthy is that it reads the same file the same way every run, not that it is
 * fast, and a generator keeps the line number attached to the row — which is what every line of
 * the import report points at.
 */
final class CsvFile
{
    /** @var list<string> the header row exactly as the file spells it */
    private array $headers = [];

    /** @var array<string, int> normalised header => column index */
    private array $index = [];

    public function __construct(private readonly string $path) {}

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @throws ImportFileException
     */
    public function open(): void
    {
        if ($this->headers !== []) {
            return;
        }

        if (! is_file($this->path) || ! is_readable($this->path)) {
            throw ImportFileException::unreadable($this->path);
        }

        $handle = fopen($this->path, 'rb');

        if ($handle === false) {
            throw ImportFileException::unreadable($this->path);
        }

        try {
            $first = fgetcsv($handle, escape: '');
        } finally {
            fclose($handle);
        }

        if (! is_array($first) || $first === [null]) {
            throw ImportFileException::empty($this->path);
        }

        $headers = [];

        foreach ($first as $position => $header) {
            $header = (string) $header;

            if ($position === 0) {
                $header = preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header;
            }

            $header = trim($header);
            $headers[] = $header;

            $key = self::normalise($header);

            // First spelling wins: a file with two `Status` columns is a file where the second
            // one is a duplicate, and silently preferring the later (usually empty) one is how
            // an import reports "no status" for every row.
            if ($key !== '' && ! array_key_exists($key, $this->index)) {
                $this->index[$key] = $position;
            }
        }

        if ($this->index === []) {
            throw ImportFileException::empty($this->path);
        }

        $this->headers = $headers;
    }

    /**
     * The header row as the file spells it — printed in the import report so the assumed
     * column names and the real ones can be compared without opening the export.
     *
     * @return list<string>
     */
    public function headers(): array
    {
        $this->open();

        return $this->headers;
    }

    /**
     * Does the file carry any of these columns? Used to tell a ClickUp export from an Asana one
     * before a single row is read.
     */
    public function hasAnyColumn(string ...$names): bool
    {
        $this->open();

        foreach ($names as $name) {
            if (array_key_exists(self::normalise($name), $this->index)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every data row, with the file's own line number on it.
     *
     * A completely blank line is skipped rather than reported: spreadsheets leave them at the
     * end of a file and an import report full of "line 314 was empty" is a report nobody reads.
     * A row that has content but is missing columns is NOT skipped here — the readers report it.
     *
     * @return Generator<int, CsvRow>
     *
     * @throws ImportFileException
     */
    public function rows(): Generator
    {
        $this->open();

        $handle = fopen($this->path, 'rb');

        if ($handle === false) {
            throw ImportFileException::unreadable($this->path);
        }

        try {
            fgetcsv($handle, escape: '');
            $line = 1;

            while (($values = fgetcsv($handle, escape: '')) !== false) {
                $line++;

                if (! is_array($values) || $values === [null]) {
                    continue;
                }

                $filled = array_filter($values, fn (mixed $value): bool => trim((string) $value) !== '');

                if ($filled === []) {
                    continue;
                }

                yield new CsvRow($line, $values, $this->index);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * The spelling a lookup uses: lower case, no surrounding space, runs of whitespace and
     * underscores collapsed to one space. `Task Name`, `task_name` and `TASK  NAME` are the
     * same column; `Task Name` and `Task Content` are not.
     */
    public static function normalise(string $header): string
    {
        $header = preg_replace('/[\s_]+/u', ' ', trim($header)) ?? $header;

        return mb_strtolower(trim($header));
    }
}
