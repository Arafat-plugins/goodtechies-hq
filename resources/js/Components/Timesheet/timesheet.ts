import { formatDuration } from '@/Components/Timer/timer';

/**
 * The weekly timesheet's client half: the payload's types, the two routes spelled once, and
 * the sentences the grid and the list both have to say.
 *
 * ## Nothing here is a second source of anything
 *
 * Every number arrives summed by `TimesheetService`, which reads `time_entries` and asks the
 * model which bucket each entry is in. This file adds no arithmetic beyond joining a cell's
 * figures into a sentence, and it never decides what anybody may do — `permissions.can_add_time`
 * is `TimeEntryPolicy::create` resolved on the server for this subject.
 *
 * ## There is no score on this screen
 *
 * No rate, no percentage of target, no ranking. The target appears as the second half of
 * "18h 20m / 25h" — a total against a number the employee's own schedule set, which is a fact.
 */

/* ------------------------------------------------------------------- the payload */

export interface TimesheetRef {
    id: number;
    name: string;
}

/** Who the week belongs to. `is_self` is the server's answer, not a comparison made here. */
export interface TimesheetSubject {
    id: number;
    name: string;
    employee_number: string | null;
    is_self: boolean;
    tracking_mode: string;
}

export interface TimesheetWeek {
    start: string;
    end: string;
    label: string;
    previous: string;
    next: string;
    current: string;
    is_current: boolean;
    /** `sun` … `sat` — the employee's first working day, never a constant. */
    starts_on: string;
    starts_on_label: string;
    /** Why the week starts where it does, in the server's words. */
    starts_on_reason: string;
}

/** One column: a day, its four figures, and what the schedule expects of it. */
export interface TimesheetDay {
    date: string;
    weekday: string;
    short_label: string;
    label: string;
    long_label: string;
    is_working_day: boolean;
    is_today: boolean;
    is_future: boolean;
    target_seconds: number | null;
    counted_seconds: number;
    pending_seconds: number;
    rejected_seconds: number;
}

/** One cell: a day of one row. */
export interface TimesheetCell {
    date: string;
    entry_count: number;
    is_running: boolean;
    counted_seconds: number;
    pending_seconds: number;
    rejected_seconds: number;
}

/** One row: a task worked on this week, its seven cells and its own total. */
export interface TimesheetRow {
    key: string;
    task: { id: number; title: string } | null;
    project: { id: number; name: string } | null;
    cells: TimesheetCell[];
    counted_seconds: number;
    pending_seconds: number;
    rejected_seconds: number;
}

export interface TimesheetTotals {
    counted_seconds: number;
    pending_seconds: number;
    rejected_seconds: number;
    target_seconds: number | null;
    working_days: number;
    row_count: number;
}

/* ------------------------------------------------------------------ the endpoints */

export const timesheetRoutes = {
    /** The employee's own week. A day in the week, not its first day — the server decides that. */
    own: (week?: string | null): string => (week ? `/employee/timesheet?week=${week}` : '/employee/timesheet'),
    /** Somebody's week, from the Admin surface. */
    admin: (employeeId?: number | null, week?: string | null): string => {
        const path = employeeId == null ? '/admin/timesheet' : `/admin/timesheet/${employeeId}`;

        return week ? `${path}?week=${week}` : path;
    },
    workload: '/admin/workload',
} as const;

/* ------------------------------------------------------------------- formatting */

export { formatDuration };

/**
 * A cell as one sentence, for an `aria-label` and a `title`.
 *
 * It is the whole cell, not a shorter version of it: the grid cell has about 65 px at `md` and
 * a label can truncate, so this is the copy that never does. Pending and rejected hours are
 * named in words here for the same reason they are named in words on screen — a figure that is
 * not in the total has to say why, or it reads as lost time.
 */
export function cellSentence(row: TimesheetRow, cell: TimesheetCell, day: TimesheetDay): string {
    const what = row.task?.title ?? 'Task removed';
    const parts = [`${day.long_label} — ${what}`, `${formatDuration(cell.counted_seconds)} tracked`];

    if (cell.pending_seconds > 0) {
        parts.push(`${formatDuration(cell.pending_seconds)} waiting for approval`);
    }

    if (cell.rejected_seconds > 0) {
        parts.push(`${formatDuration(cell.rejected_seconds)} not approved`);
    }

    if (cell.is_running) {
        parts.push('a timer is running on this task');
    }

    return parts.join(' — ');
}

/**
 * A day column's heading as one sentence, including the target when the schedule set one.
 */
export function daySentence(day: TimesheetDay): string {
    const parts = [day.long_label, day.is_working_day ? 'a working day' : 'not a working day'];

    parts.push(`${formatDuration(day.counted_seconds)} tracked`);

    if (day.target_seconds !== null) {
        parts.push(`of ${formatDuration(day.target_seconds)} on the schedule`);
    }

    if (day.pending_seconds > 0) {
        parts.push(`${formatDuration(day.pending_seconds)} waiting for approval`);
    }

    return parts.join(' — ');
}

/**
 * "18h 20m of 25h" — a total against the schedule's number, or the total alone when there is
 * no target to read it against. Never a percentage: a fraction of a target presented as a
 * figure is the score Part H forbids, whatever it is labelled.
 */
export function againstTarget(seconds: number, target: number | null): string {
    return target === null ? formatDuration(seconds) : `${formatDuration(seconds)} of ${formatDuration(target)}`;
}
