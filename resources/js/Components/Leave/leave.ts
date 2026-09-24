import type { StatusKey } from '@/Components/StatusBadge.vue';

/**
 * The leave payloads, the endpoints that write them, and the formatters.
 *
 * Shared by My Leave (`Pages/Shared/Leave.vue`, one page for all three shells), the Admin queue,
 * the leave calendar and the balances grid, so a status is spelled one way on all four. Follows
 * `Components/Attendance/attendance.ts` and `Components/Tasks/taskDetail.ts`.
 *
 * ## Nothing here derives a status, a tone or a permission
 *
 * `status`, `status_label` and `tone` arrive resolved by `LeaveStatus` on the server, and
 * `permissions` arrives resolved by `LeaveRequestPolicy` per record. There is no map from a
 * status to a colour in this file and there must not be one: a second copy drifts, and
 * DESIGN.md §1.4's measurement — two of the eight status tones ΔE 0.16 apart under
 * deuteranopia — is why the word is printed beside every one of them rather than a bare dot.
 *
 * ## Nothing here is a score
 *
 * A balance is a number of days for one person and a request is a record of one absence. There
 * is no total across people, no percentage and no comparison with anybody (Part H §1).
 */

/** Part D §9's four statuses. */
export type LeaveStatusKey = 'pending' | 'approved' | 'rejected' | 'correction_requested';

export interface LeaveTypeOption {
    id: number;
    name: string;
    /** Capped: a request may be refused for want of days, and an Admin sets a number per person. */
    has_balance: boolean;
    /** Every day of it lands in `unpaid_days`, which Phase 9's payroll reads. */
    is_unpaid: boolean;
}

export interface LeavePerson {
    id: number;
    name: string;
    role: string | null;
    employee_number: string | null;
}

export interface LeaveRequestRow {
    id: number;
    type: LeaveTypeOption | null;

    start_date: string;
    end_date: string;
    is_one_day: boolean;

    /** Working days on this employee's own schedule. Never a difference between two dates. */
    days: number;
    /** Of those, the ones that cost pay. */
    unpaid_days: number;

    reason: string;

    status: LeaveStatusKey;
    status_label: string;
    tone: StatusKey;

    decided_at: string | null;
    decision_note: string | null;
    approver: { id: number; name: string } | null;

    created_at: string | null;

    /** Absent on the reader's own rows — on My Leave it would be their own name every time. */
    employee: LeavePerson | null;

    permissions: { can_decide: boolean; can_resubmit: boolean };
}

export interface LeaveBalanceRow {
    type: LeaveTypeOption;
    balance_days: number;
    updated_at: string | null;
}

/** One person on one day of the leave calendar. */
export interface LeaveCalendarPerson {
    request_id: number;
    employee_id: number;
    name: string;
    type: string | null;
    status: LeaveStatusKey;
    status_label: string;
    tone: StatusKey;
}

export interface LeaveCalendarDay {
    date: string;
    weekday: string;
    day_of_month: number;
    is_today: boolean;
    is_past: boolean;
    people: LeaveCalendarPerson[];
}

export interface LeaveMonth {
    value: string;
    label: string;
    previous: string;
    next: string;
}

export interface LeaveBalanceGridRow {
    employee: LeavePerson;
    balances: { leave_type_id: number; balance_days: number }[];
    can_edit: boolean;
}

export interface LeaveStatusOption {
    value: LeaveStatusKey;
    label: string;
    tone: StatusKey;
}

/**
 * Every leave endpoint, spelled once.
 *
 * **My Leave is a SHARED route with no surface prefix**, because applying for leave is a fact
 * about the person and not about the shell they are looking at — Part C §1 gives that cell to
 * every role, the Accountant included, and decision 4-15 settled the same question for the
 * clock. The page picks its layout from `auth.user.surface`, so an Accountant applies in the
 * Accountant shell at the same URL an Admin uses.
 *
 * The queue, the calendar and the balances are Admin-surface, because reading the agency's
 * leave, ruling on it and setting somebody's days are Admin acts.
 */
export const leaveRoutes = {
    /** My Leave: balances, the apply form, and this person's own history. */
    mine: '/leave',
    apply: '/leave',
    /** Answering a correction request by amending and resubmitting — the same request. */
    resubmit: (id: number): string => `/leave/${id}`,

    queue: (status?: string | null): string => (status ? `/admin/leave?status=${status}` : '/admin/leave'),
    calendar: (month?: string | null): string =>
        month ? `/admin/leave/calendar?month=${month}` : '/admin/leave/calendar',

    approve: (id: number): string => `/admin/leave/${id}/approve`,
    reject: (id: number): string => `/admin/leave/${id}/reject`,
    correction: (id: number): string => `/admin/leave/${id}/correction`,

    balances: '/admin/leave/balances',
    /** The upsert, keyed by (employee, type) — the row's own identity. */
    setBalance: (employeeId: number, leaveTypeId: number): string =>
        `/admin/leave/balances/${employeeId}/${leaveTypeId}`,
} as const;

/**
 * A leave window as somebody reads it out loud: `5 Oct`, `4–6 Oct`, `28 Sep – 2 Oct`.
 *
 * The dates are `YYYY-MM-DD` and are split rather than parsed into a `Date`: parsing a bare date
 * in the browser is a timezone conversion, and a window that slid by a day either side of
 * midnight is a bug nobody can reproduce — the same trap `attendance.ts` documents for the month
 * grid's leading blanks.
 */
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

function parts(date: string): { day: number; month: number; year: number } | null {
    const [year, month, day] = date.split('-').map((value) => Number.parseInt(value, 10));

    if (!year || !month || !day) {
        return null;
    }

    return { day, month, year };
}

export function formatDay(date: string): string {
    const value = parts(date);

    return value ? `${value.day} ${MONTHS[value.month - 1]}` : date;
}

export function formatWindow(from: string, to: string): string {
    const start = parts(from);
    const end = parts(to);

    if (!start || !end) {
        return `${from} – ${to}`;
    }

    if (from === to) {
        return formatDay(from);
    }

    if (start.year === end.year && start.month === end.month) {
        return `${start.day}–${end.day} ${MONTHS[end.month - 1]}`;
    }

    return `${formatDay(from)} – ${formatDay(to)}`;
}

/** `2 days`, `1 day`. Zero never reaches this — a request of zero days is refused. */
export function formatDays(days: number): string {
    return `${days} ${days === 1 ? 'day' : 'days'}`;
}

/**
 * The sentence a balance reads as, including when it is zero.
 *
 * **Zero is an answer, not an empty state.** "0 days left" and a blank cell say different
 * things, and the second one reads as "this type does not apply to you".
 */
export function formatBalance(days: number): string {
    return `${days} ${days === 1 ? 'day' : 'days'} left`;
}
