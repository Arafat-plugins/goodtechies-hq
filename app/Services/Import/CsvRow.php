<?php

namespace App\Services\Import;

use Illuminate\Support\Carbon;

/**
 * One data row of an export, addressed by column name rather than by position.
 *
 * `get()` takes a list of aliases and returns the first one the file actually has. That is not
 * laziness about the format: this repo does not have the client's real export yet (GATE A), so
 * every column name in the two readers is an assumption, and an alias list is the difference
 * between "their file has `Time Logged` where we guessed `Time Spent`" costing a one-line change
 * and costing a re-read of the whole import.
 *
 * The parsers below are deliberately narrow. Each one answers a question the export asks in more
 * than one shape — a date as an epoch or as text, a duration as milliseconds or as `3:15:00` —
 * and returns null rather than a guess when it cannot read the value. A null is reportable; a
 * guess is not.
 */
final class CsvRow
{
    /**
     * @param  list<string|null>  $values
     * @param  array<string, int>  $index  normalised header => column position
     */
    public function __construct(
        public readonly int $line,
        private readonly array $values,
        private readonly array $index,
    ) {}

    /**
     * The first of these columns the file has, trimmed; null when none of them is present or all
     * of them are blank.
     */
    public function get(string ...$aliases): ?string
    {
        foreach ($aliases as $alias) {
            $key = CsvFile::normalise($alias);

            if (! array_key_exists($key, $this->index)) {
                continue;
            }

            $value = trim((string) ($this->values[$this->index[$key]] ?? ''));

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * A date column as a date, or null.
     *
     * ClickUp writes both shapes and which one you get depends on the export options: the
     * machine columns (`Due Date`) hold a Unix timestamp in **milliseconds**, the human ones
     * (`Due Date Text`) hold something like `Fri, September 25, 2026`. Asana writes plain
     * `2026-09-25`. All three land here, and anything else lands as null.
     */
    public function date(string ...$aliases): ?Carbon
    {
        $value = $this->get(...$aliases);

        if ($value === null) {
            return null;
        }

        if (preg_match('/^-?\d{10,16}$/', $value) === 1) {
            $number = (int) $value;

            // Ten digits is seconds (to the year 2286), thirteen is milliseconds. Anything
            // longer is microseconds, which ClickUp does not write but a re-export might.
            $seconds = match (true) {
                abs($number) >= 1_000_000_000_000_000 => intdiv($number, 1_000_000),
                abs($number) >= 1_000_000_000_000 => intdiv($number, 1_000),
                default => $number,
            };

            try {
                return Carbon::createFromTimestamp($seconds, config('app.timezone'));
            } catch (\Throwable) {
                return null;
            }
        }

        try {
            return Carbon::parse($value, config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * A tracked-time column as whole seconds, or null when the column is absent or unreadable.
     *
     * Three shapes, because three are in the wild:
     *   - `11400000`  — ClickUp's `Time Logged`, milliseconds. A bare integer is ALWAYS read as
     *     milliseconds, never as seconds: reading 11400000 as seconds would import 132 days.
     *   - `3:10:00`   — ClickUp's `Time Logged Text`, h:mm:ss (or mm:ss).
     *   - `3h 10m`    — what a person types, and what some exports write.
     *
     * Zero is a value, not an absence: it means "the task has no tracked time" and the report
     * says so, rather than the row being reported as unreadable.
     */
    public function seconds(string ...$aliases): ?int
    {
        $value = $this->get(...$aliases);

        if ($value === null) {
            return null;
        }

        if (preg_match('/^\d+$/', $value) === 1) {
            return intdiv((int) $value, 1000);
        }

        if (preg_match('/^(\d+):([0-5]?\d)(?::([0-5]?\d))?$/', $value, $parts) === 1) {
            return isset($parts[3])
                ? ((int) $parts[1] * 3600) + ((int) $parts[2] * 60) + (int) $parts[3]
                : ((int) $parts[1] * 60) + (int) $parts[2];
        }

        if (preg_match('/^(?:(\d+)\s*h)?\s*(?:(\d+)\s*m)?\s*(?:(\d+)\s*s)?$/i', $value, $parts) === 1
            && trim($parts[0]) !== '') {
            return ((int) ($parts[1] ?? 0) * 3600)
                + ((int) ($parts[2] ?? 0) * 60)
                + ((int) ($parts[3] ?? 0));
        }

        return null;
    }

    /**
     * A list column — ClickUp writes assignees and tags as a JSON-ish array in one cell
     * (`["Tapu","Yaseen"]`), Asana writes them comma-separated. Both come back as a plain list.
     *
     * @return list<string>
     */
    public function list(string ...$aliases): array
    {
        $value = $this->get(...$aliases);

        if ($value === null) {
            return [];
        }

        if (str_starts_with($value, '[')) {
            $decoded = json_decode($value, true);

            if (is_array($decoded)) {
                $flat = [];

                foreach ($decoded as $item) {
                    // ClickUp has also been seen exporting `[{"username":"Tapu"}]`.
                    $flat[] = is_array($item)
                        ? trim((string) ($item['username'] ?? $item['name'] ?? $item['email'] ?? ''))
                        : trim((string) $item);
                }

                return array_values(array_filter($flat, fn (string $one): bool => $one !== ''));
            }

            // Not JSON after all: fall through and treat the brackets as punctuation.
            $value = trim($value, '[]');
        }

        $parts = array_map(
            fn (string $one): string => trim($one, " \t\n\r\0\x0B\"'"),
            explode(',', $value),
        );

        return array_values(array_filter($parts, fn (string $one): bool => $one !== ''));
    }
}
