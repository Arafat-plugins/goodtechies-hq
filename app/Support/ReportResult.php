<?php

namespace App\Support;

use InvalidArgumentException;
use JsonSerializable;

/**
 * What a report answers (report contract §3).
 *
 * One shape for all sixteen, which is what lets one screen render them: a filter bar built
 * from the key's filters, a table built from `columns` and `rows`, a footer built from
 * `totals`, the charts, the notes, and — when there is nothing — the sentence in `empty`.
 *
 * ## Three charts is a cap nobody has to remember
 *
 * Part D §3 budgets three charts a screen, and this constructor throws on a fourth. A budget
 * enforced by a convention is a budget that holds until the fifteenth report; a budget enforced
 * by a constructor holds forever, and it fails in the builder's own test rather than in a
 * design review six screens later.
 *
 * ## `empty` is a sentence, not a flag
 *
 * Every report says in its own words what "no rows" means, because "No data" is ambiguous in
 * exactly the way that matters: it can mean *nothing happened in this window* or *nothing here
 * is yours to see*, and a reader who cannot tell the two apart will ask somebody to check.
 *
 * ## What is NOT here
 *
 * No export. Spec §44 puts PDF and CSV in post-MVP Phase 2, and there is not a disabled control
 * either — a greyed-out button is a promise with a date nobody set.
 */
final readonly class ReportResult implements JsonSerializable
{
    /** Part D §3's budget, enforced below. */
    public const MAX_CHARTS = 3;

    /**
     * The footer row, keyed by exactly the same columns as a data row.
     *
     * A builder passes only the cells it has something to say about; the constructor fills the
     * rest with null and drops anything that is not a column. That is a small kindness to the
     * builders and a real guarantee to the **one** renderer: a footer is always the same width
     * as the table, so a `<td>` cannot go missing and shift every figure after it one column
     * to the left.
     *
     * @var array<string, string|int|float|null>|null
     */
    public ?array $totals;

    /**
     * @param  list<ReportColumn>  $columns
     * @param  list<array<string, string|int|float|bool|null>>  $rows  keyed by `ReportColumn::$key`
     * @param  array<string, string|int|float|null>|null  $totals  the footer row; null when the
     *                                                             report has no meaningful total
     * @param  list<ReportChart>  $charts  0..3 — a fourth throws
     * @param  list<string>  $notes  caveats printed under the table
     * @param  string  $empty  the empty-state sentence
     */
    public function __construct(
        public array $columns,
        public array $rows,
        ?array $totals = null,
        public array $charts = [],
        public array $notes = [],
        public string $empty = 'Nothing to show for this window.',
    ) {
        if (count($this->charts) > self::MAX_CHARTS) {
            throw new InvalidArgumentException(sprintf(
                'A report may carry at most %d charts (Part D §3); %d were given.',
                self::MAX_CHARTS,
                count($this->charts),
            ));
        }

        $this->totals = $totals === null ? null : $this->footer($totals);
    }

    /**
     * @param  array<string, string|int|float|null>  $totals
     * @return array<string, string|int|float|null>
     */
    private function footer(array $totals): array
    {
        $footer = [];

        foreach ($this->columns as $column) {
            $footer[$column->key] = $totals[$column->key] ?? null;
        }

        return $footer;
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    /**
     * @return array{
     *     columns: list<ReportColumn>,
     *     rows: list<array<string, string|int|float|bool|null>>,
     *     totals: array<string, string|int|float|null>|null,
     *     charts: list<ReportChart>,
     *     notes: list<string>,
     *     empty: string,
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'columns' => $this->columns,
            // A report with no rows carries no totals and no charts, whatever the builder put
            // there. A footer reading "Total 0.00" under an empty table is a figure about
            // nothing, and a donut with no slices is a legend pretending to be a chart.
            'rows' => $this->rows,
            'totals' => $this->isEmpty() ? null : $this->totals,
            'charts' => $this->isEmpty()
                ? []
                : array_values(array_filter(
                    $this->charts,
                    fn (ReportChart $chart): bool => ! $chart->isEmpty(),
                )),
            'notes' => $this->notes,
            'empty' => $this->empty,
        ];
    }
}
