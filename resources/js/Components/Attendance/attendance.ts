import type { StatusKey } from '@/Components/StatusBadge.vue';

/**
 * The attendance payloads, the endpoints that write them, and the formatters.
 *
 * Shared by the self page (`Pages/Shared/Attendance.vue`), the Admin roster
 * (`Pages/Admin/Attendance/Index.vue`) and the Work Schedule editor, so a status is spelled
 * one way on all three. Follows `Components/Tasks/taskDetail.ts`.
 *
 * ## Nothing here derives a status
 *
 * `status`, `status_label` and `tone` all arrive resolved by `AttendanceService::dayFor()` —
 * which is the one statement of "what was this day" (decision 2-37). There is no map from a
 * status to a colour in this file and there must not be one: a second copy drifts, and this
 * app has fixed a colour-only status bug twice already.
 *
 * ## Nothing here is a score
 *
 * `worked_minutes` and `tracked_minutes` are durations. There is no target percentage, no
 * ranking and no comparison between people anywhere in this feature (Part H §1).
 */

/** Part D §8's eight statuses. Null means the day has no record — see `AttendanceDay`. */
export type AttendanceStatusKey =
    | 'present'
    | 'late'
    | 'half_day'
    | 'absent'
    | 'leave'
    | 'holiday'
    | 'remote'
    | 'off_day';

/**
 * One day, as the server answers it. The same shape in a roster row, a month cell and the
 * clock widget's *today*.
 */
export interface AttendanceDay {
    date: string;
    weekday: string;
    day_of_month: number;

    /** Null on a day with no record yet — printed as *No record*, never as Absent. */
    status: AttendanceStatusKey | null;
    status_label: string | null;
    tone: StatusKey | null;

    /** `HH:mm`, or null. */
    clock_in: string | null;
    clock_out: string | null;
    worked_minutes: number | null;
    /**
     * Minutes the remote timer recorded. **Null means not known**, not zero: the timer is the
     * other half of Phase 4, and every surface prints this clause only when it is a number.
     */
    tracked_minutes: number | null;

    note: string | null;
    /** The name of whoever last corrected the row by hand, or null. */
    edited_by: string | null;

    scheduled: boolean;
    is_today: boolean;
    is_future: boolean;
}

export interface AttendancePerson {
    id: number;
    name: string;
    role: string | null;
    employee_number: string | null;
    tracking_mode: string;
}

/**
 * A roster row: who, plus their day, plus whether THIS viewer may correct it.
 *
 * `can_edit` is `AttendanceRecordPolicy::update` resolved per row on the server — the manage
 * permission, the scope, and whether the office clock owns this person's day at all. It is
 * never derived here from `tracking_mode` or from a role (decisions 2-28, 2-31).
 */
export type AttendanceRosterRow = AttendanceDay & { employee: AttendancePerson; can_edit: boolean };

export interface AttendanceScheduleSummary {
    working_days: string[];
    working_hours_per_day: number;
    /** `HH:mm`, or null — an employee with no start time cannot be late. */
    start_time: string | null;
    office_or_remote: string;
    late_grace_minutes: number;
}

export interface AttendanceMonth {
    value: string;
    label: string;
    previous: string;
    next: string;
}

export interface AttendanceStatusOption {
    value: AttendanceStatusKey;
    label: string;
    tone: StatusKey;
}

export interface AttendanceSummaryRow {
    key: string;
    label: string;
    count: number;
    tone?: StatusKey;
}

export interface WeekdayOption {
    value: string;
    label: string;
    short: string;
}

export interface ScheduleEditorRow {
    employee: AttendancePerson;
    schedule: {
        working_days: string[];
        working_hours_per_day: number;
        start_time: string | null;
        office_or_remote: string;
    } | null;
}

/**
 * Every attendance endpoint, spelled once.
 *
 * The clock and the month page are **shared** routes with no surface prefix: clocking in is a
 * fact about the person, not about the shell they are looking at, and both Admins clock in.
 * The roster and the correction are Admin-surface, because reading the agency's morning and
 * rewriting somebody's pay record are Admin acts.
 */
export const attendanceRoutes = {
    /** Somebody's month. No id means yours. */
    show: (employeeId?: number | null, month?: string | null): string => {
        const path = employeeId == null ? '/attendance' : `/attendance/${employeeId}`;

        return month ? `${path}?month=${month}` : path;
    },
    clockIn: '/attendance/clock-in',
    clockOut: '/attendance/clock-out',
    roster: (date?: string | null): string => (date ? `/admin/attendance?date=${date}` : '/admin/attendance'),
    /** The upsert, keyed by (employee, date) — the row's own identity. */
    editDay: (employeeId: number, date: string): string => `/admin/attendance/${employeeId}/${date}`,
    saveSchedule: (employeeId: number): string => `/admin/schedules/${employeeId}`,
} as const;

/**
 * A duration, as somebody reads it out loud: `8h 12m`, `45m`, `—`.
 *
 * Null is a dash and not `0m`. The difference carries meaning in both places this is used:
 * a day still open has no worked minutes yet, and a remote employee's tracked minutes are not
 * measured by this half of the phase at all.
 */
export function formatMinutes(minutes: number | null | undefined): string {
    if (minutes == null) {
        return '—';
    }

    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;

    if (hours === 0) {
        return `${rest}m`;
    }

    return rest === 0 ? `${hours}h` : `${hours}h ${rest}m`;
}

/** `09:00 – 17:30`, `09:00 – still in`, or `—`. */
export function formatShift(day: Pick<AttendanceDay, 'clock_in' | 'clock_out'>): string {
    if (!day.clock_in) {
        return '—';
    }

    return `${day.clock_in} – ${day.clock_out ?? 'still in'}`;
}

/**
 * The word a day wears when the server sent no status.
 *
 * A day with no record is *No record*, and a future one is *Not yet*. Neither is Absent:
 * Absent is what `hq:mark-absent` writes at 23:55, and a screen that guessed it at ten in the
 * morning would be putting a word on somebody's pay record that no job has written.
 */
export function noStatusLabel(day: Pick<AttendanceDay, 'is_future' | 'is_today'>): string {
    if (day.is_future) {
        return 'Not yet';
    }

    return day.is_today ? 'Not in yet' : 'No record';
}

/**
 * How many blank cells a month grid needs before its first day, so that the first of the month
 * lands under its own weekday column.
 *
 * The grid's columns start at Sunday, which is `Weekday`'s own order on the server — the same
 * order `working_days` is stored in. It is derived from the first day's *name*, not from a
 * `Date` object: parsing `2026-09-01` in the browser is a timezone conversion, and a month
 * grid that slid by a day either side of midnight is a bug nobody can reproduce.
 */
const WEEKDAY_ORDER = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

export function leadingBlanks(days: AttendanceDay[]): number {
    const first = days[0];

    return first ? Math.max(0, WEEKDAY_ORDER.indexOf(first.weekday)) : 0;
}

/** The seven column headings, short. */
export const WEEKDAY_HEADINGS = WEEKDAY_ORDER.map((day) => day.slice(0, 3));
