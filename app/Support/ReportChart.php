<?php

namespace App\Support;

use JsonSerializable;

/**
 * One chart on a report (report contract §3).
 *
 * Three kinds and no fourth this phase: `bar` is `Components/Charts/BarCompare.vue`, `donut` is
 * `DonutBreakdown.vue`, `area` is `AreaTrend.vue`. The kind is a string union in the contract
 * rather than an enum, so it is kept a union here by making the constructor private and giving
 * it three named doors — a builder cannot reach a fourth kind by spelling one.
 *
 * A series value may be an **int** (a count, a duration in minutes) or a **string** (money,
 * which arrives from PostgreSQL as `numeric(12,2)::text` and is never turned into a float on
 * the way to a chart any more than on the way to a cell).
 *
 * **A chart carries a `ReportFormat`**, and it is not decoration: a chart whose axis reads
 * `432` above a table reading `432h` is a chart the reader has to be told how to read, and a
 * unit smuggled into the title is a caption doing a scale's job. The three wrappers take a
 * `valueFormat` for exactly this, so the axis, the tooltip, the legend and the screen-reader
 * table all say the same thing. It defaults to `Number`, which is right for a count — the
 * commonest chart here by far.
 *
 * `tone` is optional and is a `StatusBadge` tone key — the same eight the rest of the
 * application paints statuses with, so a donut of tasks by status is the colours the board
 * already uses rather than a second palette for the same eight words.
 */
final readonly class ReportChart implements JsonSerializable
{
    public const BAR = 'bar';

    public const DONUT = 'donut';

    public const AREA = 'area';

    /**
     * @param  'bar'|'donut'|'area'  $kind
     * @param  list<array{label: string, value: int|string, tone?: string}>  $series
     */
    private function __construct(
        public string $kind,
        public string $title,
        public array $series,
        public ReportFormat $format = ReportFormat::Number,
    ) {}

    /**
     * @param  list<array{label: string, value: int|string, tone?: string}>  $series
     */
    public static function bar(string $title, array $series, ReportFormat $format = ReportFormat::Number): self
    {
        return new self(self::BAR, $title, $series, $format);
    }

    /**
     * @param  list<array{label: string, value: int|string, tone?: string}>  $series
     */
    public static function donut(string $title, array $series, ReportFormat $format = ReportFormat::Number): self
    {
        return new self(self::DONUT, $title, $series, $format);
    }

    /**
     * @param  list<array{label: string, value: int|string, tone?: string}>  $series
     */
    public static function area(string $title, array $series, ReportFormat $format = ReportFormat::Number): self
    {
        return new self(self::AREA, $title, $series, $format);
    }

    public function isEmpty(): bool
    {
        return $this->series === [];
    }

    /**
     * @return array{kind: string, title: string, format: string, series: list<array{label: string, value: int|string, tone?: string}>}
     */
    public function jsonSerialize(): array
    {
        return [
            'kind' => $this->kind,
            'title' => $this->title,
            'format' => $this->format->value,
            'series' => $this->series,
        ];
    }
}
