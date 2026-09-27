<?php

namespace App\Support;

use JsonSerializable;

/**
 * One column of a report (report contract §3).
 *
 * The `key` is how a row names this cell, so a row is `array<string, …>` keyed by column and
 * never a positional tuple — a tuple is how a column added in the middle of a builder silently
 * shifts every figure one place to the right.
 *
 * A column exists **only when the viewer may read it**. That is the field half of Part C §1 —
 * *a field the requester may not see is absent from the payload, never null* — and the reason
 * the builder decides the column list rather than the renderer hiding cells: a renderer that
 * hides a cell has already been sent the value.
 */
final readonly class ReportColumn implements JsonSerializable
{
    /**
     * @param  string  $key  how rows and totals name this cell
     * @param  string  $label  the column heading
     * @param  ReportFormat  $format  how the one generic screen renders it
     * @param  'start'|'end'|null  $align  overrides `ReportFormat::align()`, which is right
     *                                     for every column this phase has
     * @param  array<string, string>  $labels  for a {@see ReportFormat::Status} column only:
     *                                         the tone → **the app's own word** for it. See
     *                                         {@see status()} for why this exists.
     * @param  string|null  $linkKey  the name of ANOTHER key on the row holding this cell's
     *                                href. See {@see linkedBy()}.
     */
    public function __construct(
        public string $key,
        public string $label,
        public ReportFormat $format = ReportFormat::Text,
        private ?string $align = null,
        public array $labels = [],
        public ?string $linkKey = null,
    ) {}

    /**
     * Make this column's cells links, taking the href from `$linkKey` on the same row.
     *
     * **Every count of something openable is a link** — the rule every other screen in this
     * app follows, and the one the reports surface could not follow until now: a reader saw
     * *"Overdue 7"* with nowhere to click. It is stated as a **second key on the row** rather
     * than as a nested `{value, href}` cell because a row stays flat scalars that way, which
     * is what lets `totals` share the row's shape and lets a cell be compared, summed and
     * serialised without unwrapping. The href key is not itself a column, so it is carried and
     * never printed.
     *
     * The href is the **builder's**, which is the only place it could safely be: the builder
     * already knows the scope the row came from, so it cannot link somewhere the reader may
     * not go. Nothing in Vue builds a URL from an id.
     */
    public function linkedBy(string $key): self
    {
        return new self($this->key, $this->label, $this->format, $this->align, $this->labels, $key);
    }

    /** @return 'start'|'end' */
    public function align(): string
    {
        /** @var 'start'|'end' */
        return $this->align ?? $this->format->align();
    }

    public static function text(string $key, string $label): self
    {
        return new self($key, $label, ReportFormat::Text);
    }

    public static function number(string $key, string $label): self
    {
        return new self($key, $label, ReportFormat::Number);
    }

    public static function money(string $key, string $label): self
    {
        return new self($key, $label, ReportFormat::Money);
    }

    public static function minutes(string $key, string $label): self
    {
        return new self($key, $label, ReportFormat::Minutes);
    }

    public static function date(string $key, string $label): self
    {
        return new self($key, $label, ReportFormat::Date);
    }

    public static function percent(string $key, string $label): self
    {
        return new self($key, $label, ReportFormat::Percent);
    }

    /**
     * A status column, and the words it prints.
     *
     * **`$labels` is not decoration, and leaving it empty is a bug you can see on screen.**
     * `StatusBadge` carries a default word per tone, and those defaults are not this app's
     * vocabulary: `TaskStatus::Waiting` is *"Waiting / Blocked"* and the badge's default is
     * *"Waiting"*; `TaskStatus::Completed` is *"Completed"* and the badge says *"Done"*. The
     * Task report puts a donut built from `TaskStatus::label()` directly above a table built
     * from these cells, so an unlabelled status column prints two different words for one
     * status a few centimetres apart — which is how this was found.
     *
     * So a status column is given the enum's own labels, once per column rather than once per
     * row, and the badge is told what to say. A tone with no entry here still falls back to the
     * badge's default, which is why the Project and Payroll columns could be `Text` instead:
     * their statuses are not task statuses at all, and forcing them through an eight-tone
     * vocabulary would have been a worse lie than a plain word.
     *
     * @param  array<string, string>  $labels  tone → the word to print
     */
    public static function status(string $key, string $label, array $labels = []): self
    {
        return new self($key, $label, ReportFormat::Status, null, $labels);
    }

    /**
     * @return array{key: string, label: string, format: string, align: string, labels?: array<string, string>, link_key?: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'format' => $this->format->value,
            'align' => $this->align(),
            ...($this->linkKey === null ? [] : ['link_key' => $this->linkKey]),
            // Absent when there are none, rather than an empty object on all fifteen of the
            // columns that are not statuses.
            ...($this->labels === [] ? [] : ['labels' => $this->labels]),
        ];
    }
}
