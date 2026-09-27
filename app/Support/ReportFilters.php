<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use JsonSerializable;

/**
 * The validated answer to "what was this report asked" (report contract §2).
 *
 * `ReportRequest` produces one and `ReportService::build()` consumes one, so a builder never
 * touches the query string and can never honour a key the contract does not have. Two rules
 * are held here rather than in eight builders:
 *
 *   - **A filter a report does not accept is dropped**, not carried and ignored. A hand-typed
 *     `?employee=3` on the Finance report is gone by the time a query is written, so there is
 *     no builder that could one day start reading it.
 *   - **The range always has both ends.** The contract's default is the current calendar
 *     month, and a report whose `filters()` omits `DateRange` simply never asks for them —
 *     `Overdue` is a question about today and this object cannot make it a question about a
 *     window.
 *
 * ## An id here is data, never an authorization decision
 *
 * `employeeId`, `projectId` and `clientId` are dropped into queries that are already scoped by
 * the model's own `visibleTo()`. An id the viewer may not see therefore matches nothing and
 * the report comes back empty. It is deliberately **not** an error: a 404 would confirm that
 * the row exists, and a 403 would confirm it and name the reason (Part C §1).
 */
final readonly class ReportFilters implements JsonSerializable
{
    public function __construct(
        public Carbon $from,
        public Carbon $to,
        public ?int $employeeId = null,
        public ?int $projectId = null,
        public ?int $clientId = null,
        /** The day "overdue", "today" and "this week" are relative to. A parameter, so a report is testable at a fixed date. */
        public ?Carbon $asOf = null,
    ) {}

    /**
     * Build the filters for one report from already-validated input, dropping everything that
     * report does not accept.
     *
     * @param  array<string, mixed>  $input  the validated `from`, `to`, `employee`, `project`, `client`
     */
    public static function for(ReportKey $key, array $input, ?Carbon $asOf = null): self
    {
        $asOf ??= Carbon::today();

        [$from, $to] = $key->accepts(ReportFilter::DateRange)
            ? self::range($input, $asOf)
            // A report with no date range still carries one, because `asOf` has to be somewhere
            // and a builder that ignores the window is clearer than a nullable pair every
            // builder has to check. `Overdue` never reads these two.
            : [$asOf->copy()->startOfMonth(), $asOf->copy()->endOfMonth()];

        return new self(
            from: $from,
            to: $to,
            employeeId: self::idFor($key, ReportFilter::Employee, $input),
            projectId: self::idFor($key, ReportFilter::Project, $input),
            clientId: self::idFor($key, ReportFilter::Client, $input),
            asOf: $asOf,
        );
    }

    /** The day this report is relative to. */
    public function asOf(): Carbon
    {
        return ($this->asOf ?? Carbon::today())->copy()->startOfDay();
    }

    /** Is the window exactly one whole calendar month? The Finance report's single-month case. */
    public function isWholeMonth(): bool
    {
        return $this->from->isSameDay($this->from->copy()->startOfMonth())
            && $this->to->isSameDay($this->from->copy()->endOfMonth());
    }

    /**
     * Every calendar month the window touches, first day first.
     *
     * A window of 3–8 September is one month; 28 August to 2 September is two. The Finance and
     * Payroll reports are both stated per month, so this is where "which months" is decided
     * once rather than twice.
     *
     * @return list<Carbon>
     */
    public function months(): array
    {
        $months = [];

        for (
            $cursor = $this->from->copy()->startOfMonth();
            $cursor->lessThanOrEqualTo($this->to);
            $cursor->addMonthNoOverflow()
        ) {
            $months[] = $cursor->copy();
        }

        return $months;
    }

    /**
     * The window, as the two `Y-m-d` strings a query wants.
     *
     * @return array{string, string}
     */
    public function dateStrings(): array
    {
        return [$this->from->toDateString(), $this->to->toDateString()];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{Carbon, Carbon}
     */
    private static function range(array $input, Carbon $asOf): array
    {
        $from = self::date($input[ReportFilter::FROM] ?? null) ?? $asOf->copy()->startOfMonth();
        $to = self::date($input[ReportFilter::TO] ?? null) ?? $asOf->copy()->endOfMonth();

        // The request has already refused `to` before `from`; this is the belt for a caller
        // that is not HTTP, and it orders rather than throws because a report is a read.
        return $to->lessThan($from) ? [$to, $from] : [$from, $to];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private static function idFor(ReportKey $key, ReportFilter $filter, array $input): ?int
    {
        $name = $filter->key();

        if ($name === null || ! $key->accepts($filter)) {
            return null;
        }

        $value = $input[$name] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    private static function date(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value->copy()->startOfDay();
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        // `createFromFormat` THROWS on a string that is not the shape at all ("tomorrow"), and
        // silently ROLLS OVER one that is the shape but not a date (`2026-09-31` becomes the
        // first of October). Both have to end as null, so both are caught: the try handles the
        // first, and the round trip below handles the second — the same guard
        // `Admin/TimeController::day()` uses.
        try {
            $date = Carbon::createFromFormat('Y-m-d', trim($value));
        } catch (\Throwable) {
            return null;
        }

        return $date !== false && $date->toDateString() === trim($value)
            ? $date->startOfDay()
            : null;
    }

    /**
     * What the screen echoes back into its filter bar, so the controls show what was asked.
     *
     * @return array{from: string, to: string, employee: int|null, project: int|null, client: int|null}
     */
    public function jsonSerialize(): array
    {
        return [
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'employee' => $this->employeeId,
            'project' => $this->projectId,
            'client' => $this->clientId,
        ];
    }
}
