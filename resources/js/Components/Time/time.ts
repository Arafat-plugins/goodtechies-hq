import type { TimeEntry } from '@/Components/Timer/timer';

/**
 * The Admin → Workforce → Time payloads and the endpoints that write them.
 *
 * Shared by `Pages/Admin/Time/Index.vue` and the components under `Components/Time/`. Follows
 * `Components/Attendance/attendance.ts`.
 *
 * ## Nothing here decides anything
 *
 * Whether a row may be approved is `entry.permissions.can_decide` — `TimeEntryPolicy::approve`
 * resolved per record on the server. It is never derived from a role, from `entry_type` or from
 * whether the viewer looks like an Admin (decisions 2-28, 2-31). Why a row is waiting is
 * `entry.waiting_because`, also the server's, in the server's sentences.
 *
 * ## Nothing here is a score
 *
 * Every number below is a duration in seconds or a count of rows. There is no rate, no
 * percentage of a target presented as a verdict, and no ordering of people by anything but
 * their name (Part H §1).
 */

/** An entry as the Admin queue receives it: `TimeEntryResource` with its owner loaded. */
export type AdminTimeEntry = TimeEntry & {
    employee: { id: number; name: string };
    permissions: { can_update: boolean; can_decide: boolean };
};

/**
 * One row of a breakdown — an employee, a project or a task, with what was tracked against it.
 *
 * `pending_week_seconds` is beside `week_seconds`, never inside it. Decision 4-7: a total asks
 * `approved_at is not null` and nothing else, so hours waiting for a sign-off are reported in
 * their own column and in words — visible, and not counted.
 */
export interface TimeBreakdownRow {
    id: number;
    name: string;
    /** A second line of context: the project a task belongs to. Absent on the other two. */
    meta?: string | null;
    href: string;
    today_seconds: number;
    week_seconds: number;
    pending_week_seconds: number;
}

export interface TimeDateNav {
    value: string;
    label: string;
    previous: string;
    next: string;
    today: string;
}

export interface TimeWeek {
    from: string;
    to: string;
    label: string;
}

/**
 * Every Admin time endpoint, spelled once.
 *
 * The two decisions hang off the ENTRY. Approving is an act on one row, and the row already
 * knows whose day and which task it is — an employee id in the URL would be a second answer to
 * a question the record has already answered.
 */
export const adminTimeRoutes = {
    index: (date?: string | null): string => (date ? `/admin/time?date=${date}` : '/admin/time'),
    approve: (entryId: number): string => `/admin/time/entries/${entryId}/approve`,
    reject: (entryId: number): string => `/admin/time/entries/${entryId}/reject`,
} as const;

/**
 * `14:30` on `2026-09-25` — the clock time of an ISO moment, in the browser's locale.
 *
 * Returns a dash rather than `Invalid Date` for a null, because an entry with no end yet is a
 * real state on this screen and it must not read as a broken row.
 */
export function clockTime(iso: string | null | undefined): string {
    if (!iso) {
        return '—';
    }

    const at = new Date(iso);

    return Number.isNaN(at.getTime())
        ? '—'
        : at.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
}

/** `Thu 25 Sep` — a work date, short. */
export function shortDate(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    const at = new Date(`${value}T00:00:00`);

    return Number.isNaN(at.getTime())
        ? '—'
        : at.toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' });
}
