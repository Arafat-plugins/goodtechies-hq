import { formatDuration } from '@/Components/Timer/timer';

/**
 * Admin → Workforce → Workload: the payload's types and the two sentences it needs.
 *
 * **Counts only.** There is no ratio here, no percentage, no variance and no comparison
 * helper — not because none was needed but because a helper that divided tracked by estimated
 * would be the productivity score Part H forbids, whatever it was called at the call site.
 * The two figures travel as two figures and are printed as two figures.
 */

export interface WorkloadEmployee {
    id: number;
    name: string;
    employee_number: string | null;
    role: string | null;
    /** Tasks still owed — `TaskBucket::Open`, the same query `/admin/tasks?bucket=open` runs. */
    open_count: number;
    /** `TaskBucket::Overdue`, computed at query time and never stored. */
    overdue_count: number;
    /** Summed over the open tasks, in minutes. Zero when nothing carries an estimate. */
    estimated_minutes: number;
    /** How many of those tasks carry no estimate at all — the sum's own health warning. */
    unestimated_count: number;
    /** Approved hours on those same tasks, from `time_entries` (decision 4-7). */
    tracked_seconds: number;
}

export interface WorkloadProject {
    id: number;
    name: string;
    client: string;
    open_count: number;
    overdue_count: number;
}

export interface WorkloadTotals {
    open_count: number;
    overdue_count: number;
    employee_count: number;
}

export { formatDuration };

/** Minutes as the same "4h 13m" every other duration on the app is printed as. */
export function formatMinutes(minutes: number): string {
    return formatDuration(minutes * 60);
}

/**
 * What an estimate is worth, said plainly.
 *
 * A sum over a set where four of nine tasks carry no estimate is a smaller number that looks
 * like less work, so the count of unestimated tasks is printed with it rather than beside it in
 * a column somebody may have hidden. "No estimates yet" when the whole set is unestimated,
 * because "0m" there would be a claim nobody made.
 */
export function estimateNote(row: WorkloadEmployee): string | null {
    if (row.unestimated_count === 0) {
        return null;
    }

    if (row.estimated_minutes === 0) {
        return `${row.unestimated_count} ${row.unestimated_count === 1 ? 'task has' : 'tasks have'} no estimate`;
    }

    return `${row.unestimated_count} more ${row.unestimated_count === 1 ? 'task has' : 'tasks have'} no estimate`;
}
