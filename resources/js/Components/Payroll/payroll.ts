/**
 * The payroll payloads, the endpoints that write them, and the rules a payroll screen renders.
 *
 * Everything here describes `App\Http\Resources\{PayrollItem,PayrollPeriod}Resource`,
 * `App\Support\PayrollStatus` and `App\Http\Controllers\Shared\PayrollController`. It is
 * imported by the payroll workbench **and by My Payslip and the salary screen**, so every
 * export is general: nothing in this module knows which screen is asking, and nothing in it
 * assumes the reader is an Admin.
 *
 * ## Four rules this module exists to keep
 *
 *  1. **Money is formatted in exactly one place, and this is not a second one.**
 *     `formatMoney()` is re-exported from `Components/Finance/finance.ts` rather than
 *     reimplemented — a `toFixed(2)` in a template is how two screens come to round differently
 *     (decision 8-4), and payroll and finance printing a figure two ways would be that defect
 *     across two modules instead of two components. Amounts arrive as exact decimal strings
 *     from `decimal(12, 2)` and are parsed once, there.
 *
 *  2. **No total is ever computed here.** A month's net total is `SUM(net_salary)` in
 *     PostgreSQL, sent as `PayrollPeriod.net_total`, and a line's `net_salary` is a
 *     `GENERATED ALWAYS … STORED` column the database computes on write (decision 9-3). There
 *     is deliberately no `sum()` and no subtraction in this file. A screen that worked out a
 *     net from the five figures beside it would be a second implementation of a database
 *     expression, and the day the two disagreed neither would be provably right.
 *
 *  3. **Which controls exist is the SERVER's answer, asked twice.** `permissions` is
 *     `PayrollPeriodPolicy` resolved per requester; `available_transitions` and
 *     `allows_calculation` are `PayrollStatus` resolved per status. `payrollActions()` below
 *     intersects the two and adds nothing — no role test, no status list of its own, no
 *     `user.role === 'ADMIN'`. A control that 403s is worse than no control, and a control Vue
 *     decided to show is a control the server never agreed to.
 *
 *  4. **`admin_notes` is an OPTIONAL key and its absence is the privacy rule.** The server
 *     sends it to an Admin and leaves the key out entirely for everybody else — not null, not
 *     masked, absent (decision 9-10). So it is typed `admin_notes?: string | null` and every
 *     reader must test `'admin_notes' in item`, never `item.admin_notes !== null`, which is
 *     true for the right answer and the wrong one alike.
 */

import { formatMoney } from '@/Components/Finance/finance';
import type { StatusKey } from '@/Components/StatusBadge.vue';

/** The one place money becomes text in this application. Re-exported, never reimplemented. */
export { formatMoney } from '@/Components/Finance/finance';
export { FINANCE_DEFAULT_CURRENCY as PAYROLL_DEFAULT_CURRENCY } from '@/Components/Finance/finance';

/* ------------------------------------------------------------------ the payloads */

/** `App\Support\PayrollStatus`. Six, and nothing outside this list is a payroll status. */
export type PayrollStatusValue = 'draft' | 'calculated' | 'reviewed' | 'approved' | 'locked' | 'paid';

/**
 * The month a payroll item belongs to, as `PayrollItemResource` sends it: **five keys**.
 *
 * Deliberately not a `PayrollPeriod` — that payload carries the company's totals and the state
 * machine's controls, which is exactly what somebody opening their own payslip must not be
 * handed. A payslip needs to say *September 2026, paid*, and this is that sentence.
 */
export interface PayrollPeriodStamp {
    id: number;
    /** `YYYY-MM-DD`, always the first of the month. Never parsed here — see `formatMonthLabel`. */
    month: string | null;
    /** "September 2026", formatted by the server. */
    label: string;
    status: PayrollStatusValue | null;
    status_label: string | null;
    /** The `StatusBadge` key, resolved on the server (decision 2-37). */
    state: StatusKey | null;
}

/** Who may make each move, straight from `PayrollPeriodPolicy`. Six booleans, one per verb. */
export interface PayrollPeriodPermissions {
    can_calculate: boolean;
    can_review: boolean;
    can_approve: boolean;
    can_lock: boolean;
    can_reverse_lock: boolean;
    can_mark_paid: boolean;
    /** Polish 005: may this viewer move this Draft to another month? */
    can_change_month?: boolean;
}

/** One month of payroll, as the workbench knows it. `PayrollPeriodResource`. */
export interface PayrollPeriod {
    id: number;
    month: string | null;
    label: string;
    status: PayrollStatusValue | null;
    status_label: string | null;
    state: StatusKey | null;
    /** What the state machine allows from here. A screen renders it; it never computes it. */
    available_transitions: PayrollStatusValue[];
    /** Does this status close the month to the finance ledger? `locked` and `paid` do. */
    closes_the_month: boolean;
    /** May Calculate be pressed? Not a transition, so it cannot be read off the list above. */
    allows_calculation: boolean;
    locked_at: string | null;
    /** The most recent lock reversal, or null if there has never been one. */
    lock_reversal: { reason: string; by: string | null } | null;
    /** Present only where the caller counted. **Absent is not zero.** */
    items_count?: number;
    /** `SUM(net_salary)` as an exact decimal string. Present only where the caller summed. */
    net_total?: string;
    permissions: PayrollPeriodPermissions;
}

/**
 * One employee's line. `PayrollItemResource` — eleven keys, plus `admin_notes` for an Admin.
 *
 * `net_salary` is read-only for **everybody, always**: PostgreSQL computes it. `leave_impact`
 * is read-only too — it is Calculate's, from approved unpaid leave. Neither is ever posted.
 */
export interface PayrollItem {
    id: number;
    period: PayrollPeriodStamp | null;
    /** Two keys and no more: a payslip is not a staff directory. */
    employee: { id: number; name: string | null } | null;
    base_salary: string;
    allowance: string;
    bonus: string;
    deduction: string;
    advance: string;
    /** Computed by Calculate from approved unpaid leave. Read-only on every screen. */
    leave_impact: string;
    /** Computed by PostgreSQL. Read-only on every screen, and never sent back. */
    net_salary: string;
    /**
     * Part D §14's "personal notes". **The key is absent for everybody but an Admin** — test
     * with `'admin_notes' in item`, never against null. See rule 4 in the module note.
     */
    admin_notes?: string | null;
    permissions: { can_update: boolean; can_annotate: boolean };
}

/**
 * What a line's leave impact is made of: unpaid days, over the month's payable working days.
 *
 * The two numbers `PayrollService::leaveImpactCents()` divides with, sent so the person reading
 * a deduction can check the arithmetic instead of raising a ticket about it.
 */
export interface PayrollLeaveBreakdown {
    unpaid_days: number;
    payable_days: number;
}

/** The breakdowns for one period, keyed by payroll item id (as a string, JSON-object style). */
export type PayrollLeaveMap = Record<string, PayrollLeaveBreakdown>;

/** One rung of the state machine's ladder, in order. Sent by the server, never built here. */
export interface PayrollStatusStep {
    value: PayrollStatusValue;
    label: string;
    state: StatusKey;
}

/** The month a Create-draft control would be for. */
export interface PayrollCurrentMonth {
    /** `YYYY-MM`. */
    value: string;
    label: string;
    has_period: boolean;
    /** Polish 005: the latest month that may be drafted (`YYYY-MM`) — never one not yet begun. */
    max: string;
    /** Polish 005: every month that already has a payroll (`YYYY-MM`). */
    taken: string[];
}

/** `2026-09` → `September 2026` (polish 005). */
export function formatMonthValue(value: string): string {
    const [year, month] = value.split('-').map(Number);

    if (!year || !month) {
        return value;
    }

    return new Intl.DateTimeFormat('en-GB', { month: 'long', year: 'numeric' }).format(new Date(year, month - 1, 1));
}

/* --------------------------------------------------------------------- the fields */

/**
 * The five figures the Accountant fills and adjusts — `PayrollItem::ADJUSTABLE`, in order.
 *
 * `leave_impact` and `net_salary` are **not** on this list and must never be added to it: one
 * is computed from approved leave and the other by the database, and a form that posted either
 * is refused with a 422 by `AdjustPayrollItemRequest` (which prohibits them rather than
 * dropping them, so the mistake is visible).
 */
export const PAYROLL_ADJUSTABLE_ALL = ['base_salary', 'allowance', 'bonus', 'deduction', 'advance'] as const;

export type PayrollAdjustableField = (typeof PAYROLL_ADJUSTABLE_ALL)[number];

/**
 * Polish 002: the client removed allowance. It is shown (and typed) only where a line still
 * carries a non-zero one — an older month — so every figure on screen still adds up to the net.
 */
export const PAYROLL_ADJUSTABLE: readonly PayrollAdjustableField[] = ['base_salary', 'bonus', 'deduction', 'advance'];

/** The adjustable figures for these lines: allowance only when one of them still has some. */
export function payrollAdjustableFor(items: readonly { allowance: string }[]): readonly PayrollAdjustableField[] {
    return items.some((item) => Number(item.allowance) !== 0) ? PAYROLL_ADJUSTABLE_ALL : PAYROLL_ADJUSTABLE;
}

/** Every money column for these lines, in reading order. */
export function payrollMoneyFieldsFor(items: readonly { allowance: string }[]): PayrollMoneyField[] {
    return [...payrollAdjustableFor(items), 'leave_impact', 'net_salary'];
}

/** Every money column on a payroll line, read-only ones included, in reading order. */
export type PayrollMoneyField = PayrollAdjustableField | 'leave_impact' | 'net_salary';


/** The column heading for each figure. One spelling, used by the table and by the dialog. */
export const PAYROLL_FIELD_LABELS: Record<PayrollMoneyField, string> = {
    base_salary: 'Base',
    allowance: 'Allowance',
    bonus: 'Bonus',
    deduction: 'Deduction',
    advance: 'Advance',
    leave_impact: 'Leave impact',
    net_salary: 'Net',
};

/** The longer name, for a form label and for a screen reader out of context. */
export const PAYROLL_FIELD_LONG_LABELS: Record<PayrollMoneyField, string> = {
    base_salary: 'Base salary',
    allowance: 'Allowance',
    bonus: 'Bonus',
    deduction: 'Deduction',
    advance: 'Advance',
    leave_impact: 'Leave impact',
    net_salary: 'Net salary',
};

/** Which figures are subtracted, so a table can say so in words as well as with a minus sign. */
export const PAYROLL_SUBTRACTED: PayrollMoneyField[] = ['deduction', 'advance', 'leave_impact'];

/* -------------------------------------------------------------------- the routes */

/**
 * Every payroll endpoint, written once — including the two this module's other readers own, so
 * that no screen spells `/payslip` or `/salaries` twice.
 */
export const payrollRoutes = {
    index: () => '/payroll',
    store: () => '/payroll',
    show: (period: number) => `/payroll/${period}`,
    item: (period: number, item: number) => `/payroll/${period}/items/${item}`,
    /** Polish 005: move a Draft to the month it pays for. */
    month: (period: number) => `/payroll/${period}/month`,
    calculate: (period: number) => `/payroll/${period}/calculate`,
    review: (period: number) => `/payroll/${period}/review`,
    approve: (period: number) => `/payroll/${period}/approve`,
    lock: (period: number) => `/payroll/${period}/lock`,
    reverseLock: (period: number) => `/payroll/${period}/reverse-lock`,
    paid: (period: number) => `/payroll/${period}/paid`,

    /** My Payslip — one person's own periods. Not behind `payroll.draft`. */
    payslip: () => '/payslip',
    /** Salary settings per employee. Admin only. */
    salaries: () => '/salaries',
};

/* ------------------------------------------------------------------- the controls */

/** Which confirmation a move needs before it is sent. */
export type PayrollConfirmKind = 'none' | 'lock' | 'paid' | 'reverse';

export interface PayrollAction {
    key: 'calculate' | 'review' | 'approve' | 'lock' | 'reverse' | 'paid';
    /** The button's words. */
    label: string;
    /** One line saying what pressing it does — shown beside it, not only in a tooltip. */
    description: string;
    url: string;
    confirm: PayrollConfirmKind;
    variant: 'default' | 'outline' | 'destructive';
}

/**
 * **The moves this viewer may make on this period, in this state.**
 *
 * Every condition below reads a field the SERVER resolved — `permissions.*` is
 * `PayrollPeriodPolicy` asked for this requester, `available_transitions` and
 * `allows_calculation` are `PayrollStatus` asked for this status. Nothing here tests a role,
 * lists a status of its own or reimplements the transition map. See rule 3 in the module note.
 *
 * The two `approved` cases are told apart by the status rather than by the target, because
 * `available_transitions` holds `approved` for two completely different reasons: from
 * `reviewed` it is the approval, and from `locked` it is the lock reversal — the one backward
 * move in the machine.
 *
 * The endpoints ask the same policy again before they act, so a stale payload buys nobody
 * anything; this function exists so that a control which would be refused is never drawn.
 */
export function payrollActions(period: PayrollPeriod): PayrollAction[] {
    const actions: PayrollAction[] = [];
    const can = period.permissions;
    const next = period.available_transitions;

    if (can.can_calculate && period.allows_calculation) {
        actions.push({
            key: 'calculate',
            label: 'Calculate',
            description:
                'Works out every line’s leave impact from approved unpaid leave. Pressing it twice gives the same answer.',
            url: payrollRoutes.calculate(period.id),
            confirm: 'none',
            variant: period.status === 'draft' ? 'default' : 'outline',
        });
    }

    if (can.can_review && next.includes('reviewed')) {
        actions.push({
            key: 'review',
            label: 'Mark reviewed',
            description:
                'Says the figures have been read. The Accountant can still correct one until the month is approved.',
            url: payrollRoutes.review(period.id),
            confirm: 'none',
            variant: 'default',
        });
    }

    if (can.can_approve && period.status === 'reviewed' && next.includes('approved')) {
        actions.push({
            key: 'approve',
            label: 'Approve',
            description: 'Settles the figures. The month becomes read-only to the Accountant, and the approval is audit-logged.',
            url: payrollRoutes.approve(period.id),
            confirm: 'none',
            variant: 'default',
        });
    }

    // Polish 002: no separate Lock step — Mark paid on an Approved month closes and pays it.

    if (can.can_reverse_lock && period.status === 'locked' && next.includes('approved')) {
        actions.push({
            key: 'reverse',
            label: 'Reverse the lock',
            description: 'Reopens the month to finance. Admin only, and it needs a reason for the audit log.',
            url: payrollRoutes.reverseLock(period.id),
            confirm: 'reverse',
            variant: 'outline',
        });
    }

    if (can.can_mark_paid && next.includes('paid')) {
        actions.push({
            key: 'paid',
            label: 'Mark paid',
            description: 'Releases the payslips. Paid is final — the month can never be reopened to finance afterwards.',
            url: payrollRoutes.paid(period.id),
            confirm: 'paid',
            variant: 'destructive',
        });
    }

    return actions;
}

/**
 * Why this viewer has no controls on this period, in one sentence — or null when they do.
 *
 * Part D §14 makes an approved month *"read-only to the Accountant"*, and a screen that simply
 * stops offering buttons leaves the person wondering whether it is broken or whether they have
 * lost a permission. Naming the reason is the difference between a rule and a fault.
 */
export function payrollNoActionsReason(period: PayrollPeriod): string | null {
    if (payrollActions(period).length > 0) {
        return null;
    }

    if (period.status === 'paid') {
        return `${period.label} is paid. Paid is the last state a payroll month has: the figures, the payslips and the month’s finance ledger are all closed for good.`;
    }

    if (period.status === 'locked') {
        return `${period.label} is locked, so its figures and its income and expenses are closed. Only an Admin can reverse a lock.`;
    }

    if (period.status === 'approved') {
        return `${period.label} is approved, so its figures are settled and no longer yours to change. An Admin locks the month from here.`;
    }

    return `${period.label} is ${(period.status_label ?? '').toLowerCase()}. There is nothing for you to do on it — the next move belongs to somebody else.`;
}

/* --------------------------------------------------------------------- the words */

/** `2026-09` or `2026-09-01` → `September 2026`. A re-ordering of the server's string. */
const MONTH_NAMES = [
    'January',
    'February',
    'March',
    'April',
    'May',
    'June',
    'July',
    'August',
    'September',
    'October',
    'November',
    'December',
];

export function formatMonthLabel(month: string): string {
    const [year, index] = month.split('-');
    const name = MONTH_NAMES[Number(index) - 1];

    return name && year ? `${name} ${year}` : month;
}

/** "9 lines" / "1 line". A count in a sentence, pluralised once. */
export function payrollLines(count: number): string {
    return `${count} ${count === 1 ? 'line' : 'lines'}`;
}

/**
 * The short form beside a leave-impact figure: **what it is made of**, in five words.
 *
 * "2 unpaid of 22 payable days" — the numerator and the denominator of the division that
 * produced the deduction, so somebody who disagrees with the figure knows which of the two
 * numbers to argue with.
 */
export function leaveImpactSummary(breakdown: PayrollLeaveBreakdown | undefined): string {
    if (!breakdown || breakdown.unpaid_days <= 0) {
        return 'No unpaid leave';
    }

    return `${breakdown.unpaid_days} unpaid of ${breakdown.payable_days} payable days`;
}

/**
 * The long form, for the dialog and for a screen reader: the whole rule, in a sentence.
 *
 * **It quotes the server's own `leave_impact` and multiplies nothing.** The two day counts are
 * the ones `PayrollService` divided with, and the money is the figure it arrived at — so the
 * sentence explains the arithmetic without performing it a second time (rule 2).
 */
export function leaveImpactSentence(
    name: string,
    monthLabel: string,
    breakdown: PayrollLeaveBreakdown | undefined,
    impact: string,
    currency: string,
): string {
    if (!breakdown || breakdown.unpaid_days <= 0) {
        return `${name} took no unpaid leave in ${monthLabel}, so nothing is deducted for leave.`;
    }

    return (
        `${name} had ${breakdown.unpaid_days} unpaid leave ${breakdown.unpaid_days === 1 ? 'day' : 'days'} in ` +
        `${monthLabel}, out of ${breakdown.payable_days} payable working ${breakdown.payable_days === 1 ? 'day' : 'days'} ` +
        `on their own schedule. Calculate deducted that share of base salary, which came to ` +
        `${formatMoney(impact, currency)}.`
    );
}

/**
 * Does this payload carry the personal-notes column at all?
 *
 * `'admin_notes' in item`, never `item.admin_notes !== null` — the server sends the key to an
 * Admin and leaves it **out** for everybody else, and a null test passes for the right answer
 * and the wrong one alike (decision 9-10). Anybody who did not receive the key sees no field,
 * no placeholder and no empty box, and this is the one function that decides that.
 */
export function receivesAdminNotes(item: PayrollItem): boolean {
    return Object.prototype.hasOwnProperty.call(item, 'admin_notes');
}
