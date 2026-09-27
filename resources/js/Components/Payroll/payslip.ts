import type { StatusKey } from '@/Components/StatusBadge.vue';

/**
 * **My Payslip and salary settings — the contracts** (master prompt Part D §14, Phase 9,
 * slice 2b).
 *
 * The payroll workbench's own module is `Components/Payroll/payroll.ts` and is a different
 * slice's file. This one belongs to the two self-service screens and to the salary settings
 * screen; import from either, never copy a shape between them.
 *
 * ## Three rules that hold for everything declared here
 *
 *  1. **Money is a string and stays one.** Every amount below is the exact decimal string
 *     PostgreSQL holds in `decimal(12, 2)`. It is rendered by `formatMoney()` from
 *     `Components/Finance/finance.ts` — the one place money becomes text in this application —
 *     and a `toFixed()` on a parsed float anywhere near a payslip is the defect that rule
 *     exists to prevent. Nothing here adds two amounts together: `net_salary` is PostgreSQL's
 *     generated column, and a total computed in Vue would be a second opinion about somebody's
 *     pay.
 *  2. **Nothing is re-derived from a status.** Whether a month reads as a payslip or as a
 *     provisional figure is `release`, resolved on the server by `PayslipController`; the
 *     status badge's tone is `period.state`, resolved by `PayrollStatus::tone()`. A `computed`
 *     holding a second copy of either map is how the screen and the server come to disagree
 *     (decision 2-37).
 *  3. **`admin_notes` is not in any type here, and must not be added.** Decision 9-10:
 *     `PayrollItemResource` leaves the key *absent* for everybody but an ADMIN — including for
 *     the employee the note is about. A payslip cannot print a key it is never sent.
 */

/** The month a payslip belongs to, and where that month has got to. */
export interface PayslipPeriod {
    id: number;
    month: string | null;
    /** `September 2026`. The server formats it; no screen parses the date to make a title. */
    label: string;
    status: string | null;
    status_label: string | null;
    /** The `StatusBadge` key, resolved by `PayrollStatus::tone()`. Never re-derived here. */
    state: StatusKey | null;
}

/** Who the line is about. Two keys: a payslip is not a staff directory (decision 9-10). */
export interface PayslipEmployee {
    id: number;
    name: string | null;
}

/**
 * Whether this month counts as a **released** payslip, and the sentence that says so.
 *
 * `released` is true for `paid` and for nothing else — Part D §14 ties release to the money
 * having moved, `approved` items are still editable by an Admin, a lock can be reversed, and
 * `paid` is the only terminal status. See `PayslipController`'s class note for the argument.
 *
 * `label` and `note` are **words**, not a colour: the difference between "this is what you were
 * paid" and "this is what you are likely to be paid" has to survive greyscale, a screen reader
 * and a printer (DESIGN.md §5.6).
 */
export interface PayslipRelease {
    released: boolean;
    label: string;
    note: string;
}

/** One payroll item as its own employee reads it: `PayrollItemResource`, plus `release`. */
export interface PayslipRow {
    id: number;
    period: PayslipPeriod | null;
    employee: PayslipEmployee | null;
    base_salary: string;
    allowance: string;
    bonus: string;
    deduction: string;
    advance: string;
    leave_impact: string;
    /** PostgreSQL's generated column. Never computed here (see rule 1). */
    net_salary: string;
    permissions: { can_update: boolean; can_annotate: boolean };
    release: PayslipRelease;
}

/**
 * Why the leave impact is what it is.
 *
 * `impact` is the **stored** figure and is never recomputed from the days: what a payslip says
 * was deducted is what was deducted. The two counts are the same two numbers
 * `PayrollService::leaveImpactCents()` divided — unpaid days over payable days — so the
 * explanation cannot drift from the deduction it explains.
 */
export interface LeaveExplanation {
    unpaid_days: number;
    payable_days: number;
    impact: string | null;
    has_impact: boolean;
}

/** One `employee_salaries` row, as the salary settings screen reads it. */
export interface SalaryRow {
    id: number;
    base_salary: string;
    allowance: string;
    /** The day this figure starts applying. It **is** the change (decision 9-1). */
    effective_from: string | null;
    /** A name, because "who set this" is the question and an id is not an answer. */
    set_by: string | null;
}

/** One active employee on the salary settings screen. */
export interface SalaryEmployee {
    id: number;
    name: string;
    role: string | null;
    /** What they are on today, or `null` for a joiner whose salary nobody has set yet. */
    current: SalaryRow | null;
    /** The most recent few rows, newest first. */
    history: SalaryRow[];
    /** How many rows there are in all, so the screen can say when it is showing a subset. */
    history_total: number;
}

/**
 * The Admin dashboard's payroll card, Phase 9's half of Row 3.
 *
 * It is declared here rather than added to `FinanceMonthSummary`, and the Dashboard intersects
 * the two: `Components/Finance/financeReport.ts` is Phase 8's file and this slice does not own
 * it, and a payroll period is not a finance rollup — the two totals come from different tables
 * and `monthlyRollup()` has never read `payroll_items`.
 *
 * `payroll` is `null` when the month has **no period yet**, which is the ordinary state of
 * every 1st before `hq:create-payroll-draft` runs. The card prints an em-dash and
 * `payroll_note` says which of the two blanks it is — never a zero, which would claim the
 * agency paid nobody this month.
 */
export interface DashboardPayrollCard {
    /** `SUM(payroll_items.net_salary)` for this month's period, summed by PostgreSQL. */
    payroll?: string | null;
    /** `September 2026 · Draft · 5 people`, or why there is no figure. Words, from the server. */
    payroll_note?: string;
    /** The workbench, when that route exists. `Route::has()`-guarded server-side. */
    payroll_href?: string | null;
}

/** The three My Payslip URLs, spelled once. */
export function payslipRoutes(id: number): { show: string; pdf: string } {
    return {
        show: `/payslip/${id}`,
        pdf: `/payslip/${id}/pdf`,
    };
}

/** The salary settings URLs, spelled once. */
export const SALARIES_INDEX = '/salaries';

export function salaryUpdateRoute(employeeId: number): string {
    return `/salaries/${employeeId}`;
}

/**
 * `2026-09-01` → `1 September 2026`.
 *
 * Day-month-year, spelled out, for the reason every date in this application is: `01/09/2026`
 * means two different days depending on who is reading it, and a salary's start date is the one
 * field on these screens where being off by a month is a payroll error.
 */
export function formatEffectiveDate(date: string | null): string {
    if (!date) {
        return 'Unknown';
    }

    const parsed = new Date(`${date}T00:00:00`);

    if (Number.isNaN(parsed.getTime())) {
        return date;
    }

    return parsed.toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' });
}

/** `1 day` / `3 days`, so no template has to decide about the plural. */
export function formatDayCount(days: number): string {
    return `${days} ${days === 1 ? 'day' : 'days'}`;
}
