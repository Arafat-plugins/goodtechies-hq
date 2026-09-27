import { financeRoutes } from '@/Components/Finance/finance';

/**
 * The two payloads that exist only on the **reporting** side of finance — the by-project cut
 * and the trend — plus the one helper a chart needs.
 *
 * Everything else these screens use already lives in `Components/Finance/finance.ts`:
 * `MonthlyRollup`, `RollupSide`, `FinanceMonth`, `formatMoney()` and `financeRoutes`. This
 * module deliberately re-declares none of it. A second money formatter or a second month type
 * would be the exact defect that file's own note is about — two screens rounding differently,
 * or disagreeing about what a month is — so the rule here is: **if the ledger screens could
 * want it, it belongs in `finance.ts`, not in this file.**
 *
 * What is left is what only a report has: income grouped by project, a series of months, the
 * Company dashboard's Row 3, and the one place a decimal string becomes a number.
 */

/**
 * One row of the by-project cut.
 *
 * **Four keys, and Part C is the reason.** A project is a name and a domain here — never the
 * client, a contact, a price, a status, or a count of anything. That is the same list
 * `FinanceProject` carries and the same discipline `AccountantProjectResource` documents.
 *
 * `project_id` is null on exactly one row: income that was never billed against a project.
 */
export interface FinanceProjectLine {
    project_id: number | null;
    name: string;
    domain: string | null;
    /** An exact decimal string, like every other amount in finance. */
    total: string;
}

/**
 * Income for one month, grouped by project.
 *
 * **Income only.** Part D §20 gives `expenses` no project link, so there is no per-project cost
 * to put beside these figures; the screen says so in words rather than showing an empty column
 * that would read as though projects cost nothing.
 */
export interface FinanceProjectCut {
    rows: FinanceProjectLine[];
    total: string;
}

/**
 * One month of the trend.
 *
 * **Every month in the window is a point, zeroes included** — the opposite of the rule the
 * category lists follow, and deliberately so: a category with no rows is a thing that did not
 * happen, while a month with no rows is a month that still happened. Dropping it would draw a
 * line straight from the month before to the month after.
 */
export interface FinanceTrendPoint {
    month: string;
    label: string;
    /** "Sep 26" — the x-axis tick, which has to fit at 360 px. */
    short_label: string;
    income: string;
    expense: string;
    net: string;
}

export interface FinanceTrend {
    months: number;
    from: string;
    to: string;
    points: FinanceTrendPoint[];
}

/**
 * The Company dashboard's Row 3 (Part D §3).
 *
 * `payroll` is **null** until Phase 9 builds `payroll_periods`, and `payroll_phase` says which
 * phase that is — the card renders a marked placeholder from those two and never a zero, which
 * would read as "we paid nobody this month".
 *
 * Every key is optional because the whole block is absent from the payload for a viewer without
 * `finance.view` (Part C §1: a field the requester may not see is absent, not null).
 */
export interface FinanceMonthSummary {
    month?: string;
    label?: string;
    income?: string;
    expense?: string;
    payroll?: string | null;
    payroll_phase?: number;
    operating_result?: string;
    /** `settings.currency`, so Row 3 wears the same symbol as every finance screen. */
    currency?: string;
    href?: string;
}

/**
 * A money string as a plain number, **for a chart's geometry only**.
 *
 * Charts measure lengths and a length is a number; every figure a person reads is the server's
 * string, rendered by `formatMoney()`. Nothing here adds two of these together — that would be
 * a total computed in Vue, which is the one thing `finance.ts` says never happens.
 */
export function moneyValue(amount: string): number {
    const value = Number.parseFloat(amount);

    return Number.isFinite(value) ? value : 0;
}

/**
 * The report's URL with its trend length on it.
 *
 * `financeRoutes.report()` takes the month, because that is all a ledger screen links with. The
 * report has a second piece of window — how many months the trend covers — and it is in the URL
 * for the same reason the month is: a trend whose length nobody can see is a chart nobody can
 * cite. This wraps that route rather than spelling `/finance/report` a second time.
 */
export function financeTrendHref(month: string, months?: number): string {
    const base = financeRoutes.report(month);

    return months === undefined ? base : `${base}${base.includes('?') ? '&' : '?'}months=${months}`;
}
