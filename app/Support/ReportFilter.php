<?php

namespace App\Support;

/**
 * The four things a report may be narrowed by (report contract §1 and §2).
 *
 * It is a closed list on purpose. Sixteen reports each inventing their own query string is
 * sixteen filter bars that spell "employee" four ways; one enum is one bar, built from
 * `ReportKey::filters()`, and a key that is not a case here never reaches a query at all —
 * `ReportRequest` validates exactly these and `ReportFilters` carries exactly these.
 *
 * **An id here is never an authorization question.** The value arrives from a query string and
 * is dropped into a query that is already scoped by the model's own `visibleTo()`. An id the
 * viewer may not see therefore matches nothing and the report is empty — which is the whole
 * point: a 404 or a 403 on a filter would confirm that the row exists (Part C §1).
 */
enum ReportFilter: string
{
    /**
     * `from` and `to`, both `Y-m-d`. The only filter that is two query keys, because a range
     * with one end is a different question from a range with two and the contract gives the
     * screen one control for it.
     */
    case DateRange = 'date_range';

    case Employee = 'employee';
    case Project = 'project';
    case Client = 'client';

    public function label(): string
    {
        return match ($this) {
            self::DateRange => 'Date range',
            self::Employee => 'Employee',
            self::Project => 'Project',
            self::Client => 'Client',
        };
    }

    /**
     * The query-string key this filter reads — `employee`, `project`, `client`.
     *
     * The date range has none of its own: it is `from` and `to`, named by the two constants
     * below so that nothing spells them by hand.
     */
    public function key(): ?string
    {
        return match ($this) {
            self::DateRange => null,
            self::Employee => 'employee',
            self::Project => 'project',
            self::Client => 'client',
        };
    }

    /** The two query keys the date range is made of. */
    public const FROM = 'from';

    public const TO = 'to';
}
