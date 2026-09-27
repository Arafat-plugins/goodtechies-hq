<?php

namespace App\Support;

/**
 * The renderer's whole vocabulary (report contract §3).
 *
 * Seven cases, and a builder that wants an eighth raises it rather than inventing one. That is
 * the deal the contract strikes: sixteen reports share one screen, and the screen can only do
 * what this enum says, so no report can quietly grow a cell type that the other fifteen do not
 * have — and no report can render a figure in a way that contradicts how the rest of the
 * application renders the same figure.
 *
 * **`Money` is a decimal STRING.** It leaves PostgreSQL already `::numeric(12,2)::text` and
 * reaches the screen as that string. Nothing in PHP and nothing in Vue adds two money values
 * up; a total is `SUM()` in the query that produced the rows.
 */
enum ReportFormat: string
{
    /** A string, printed as-is. */
    case Text = 'text';

    /** An int, printed with grouped digits. */
    case Number = 'number';

    /** A decimal string from PostgreSQL, printed as `settings.currency` plus grouped digits. */
    case Money = 'money';

    /** An int, printed `4h 18m`. */
    case Minutes = 'minutes';

    /** `Y-m-d`, printed in the app's date format. */
    case Date = 'date';

    /** An int 0..100, printed `62%`. */
    case Percent = 'percent';

    /** A `StatusBadge` tone key, printed as the existing badge. */
    case Status = 'status';

    /**
     * Which edge of its cell this format sits on.
     *
     * A figure reads down a column when its digits line up, and a word reads down a column when
     * its first letter does. It lives here rather than on every `ReportColumn` so that sixteen
     * reports cannot each decide differently — `ReportColumn` may still override it, for the
     * one case (a trailing label) where the rule is wrong.
     *
     * @return 'start'|'end'
     */
    public function align(): string
    {
        return match ($this) {
            self::Number, self::Money, self::Minutes, self::Percent => 'end',
            self::Text, self::Date, self::Status => 'start',
        };
    }
}
