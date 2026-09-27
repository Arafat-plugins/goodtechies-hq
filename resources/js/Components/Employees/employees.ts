import type { StatusKey } from '@/Components/StatusBadge.vue';

/**
 * The Employees / Users & Roles payloads, the endpoints that write them, and the formatters.
 *
 * Follows `Components/Tasks/taskDetail.ts` and `Components/Attendance/attendance.ts`: the
 * shapes are declared once, the URLs are spelled once, and the two screens import from here
 * rather than restating either.
 *
 * ## Nothing in this file decides what anybody may do
 *
 * `EmployeeController` resolves every ability through `EmployeePolicy` and sends the answers
 * as `permissions`. A key that is absent means **no** — the control is not drawn. There is no
 * role test, no permission-key test and no `is_admin` anywhere in `Components/Employees/`,
 * and there must not be one: a screen that decides for itself is a second copy of the rule
 * that drifts from the one the endpoint enforces (Part C, DESIGN.md §5.11).
 *
 * ## There is no salary here
 *
 * Not a key, not a column, not a placeholder. `employee_salaries` is Phase 9's and lives
 * behind `/salaries` with its own permission. If a figure ever appears in this payload the
 * screen is not the thing to fix.
 *
 * ## Deactivation is not deletion
 *
 * Part B §3 rule 11: *"Employee departure = `status = inactive`, never delete."* The word
 * "delete" does not appear on these screens, inactive people stay on the list, and the
 * confirmation states the four consequences rather than asking "are you sure".
 */

/* ------------------------------------------------------------------ status */

export type EmployeeStatus = 'active' | 'inactive';

/**
 * The two tones an employee's status can wear.
 *
 * The same two-way mapping `Pages/Admin/Clients/Index.vue` makes inline for a client, kept in
 * one place here because three call sites need it. It is a *tone*, never the carrier of the
 * meaning: every surface that uses it prints the word beside it (DESIGN.md §5.6), which is the
 * bug this repo has fixed twice.
 */
export function statusTone(status: EmployeeStatus | string | null | undefined): StatusKey {
    return status === 'active' ? 'done' : 'todo';
}

/* ------------------------------------------------------------------- types */

/** A named thing the payload points at: a project, a manager, a role option. */
export interface NamedRef {
    id: number;
    name: string;
}

/** `{ value, label }` — a select's options, composed on the server. */
export interface Option {
    value: string;
    label: string;
}

/** One weekday, as `Weekday::cases()` sends it (the Work Schedule editor's shape). */
export interface WeekdayOption {
    value: string;
    label: string;
    short: string;
}

/**
 * The working week, as the schedule editor already serializes it
 * (`ScheduleService::rowFor()`), so the two screens read one shape.
 */
export interface EmployeeSchedule {
    working_days: string[];
    working_hours_per_day: number;
    /** `HH:mm`, or null for a flexible day — somebody with no start time is never Late. */
    start_time: string | null;
    office_or_remote: string;
}

/**
 * One row of the Employees list.
 *
 * Every `*_label` is the server's word. The `*_label ?? humanise(*)` fallbacks below exist so
 * a payload that omits a label prints something readable rather than nothing; they are
 * formatting, not meaning, and the server's word always wins when it is there.
 */
export interface EmployeeRow {
    id: number;
    name: string;
    employee_number?: string | null;
    email?: string | null;

    role?: string | null;
    role_label?: string | null;

    tracking_mode?: string | null;
    tracking_mode_label?: string | null;

    /** "Sun, Mon, Tue · 8 h/day · starts 09:00", composed on the server. */
    schedule_summary?: string | null;
    schedule?: EmployeeSchedule | null;

    status: EmployeeStatus;
    status_label?: string | null;

    /** How many project-level permission grants this person holds. */
    project_permissions_count?: number;
    /** How many projects they are a member of. */
    projects_count?: number;

    /**
     * What deactivating this person would leave behind. It travels on **list rows as well as
     * the detail**, which is why the confirmation can state the consequence from either screen.
     */
    impact?: EmployeeDeactivationImpact;

    /** True when the viewer is looking at their own record — the self-guard's other half. */
    is_you?: boolean;

    /**
     * Resolved by `EmployeePolicy` on the server, on every row. Absent is **no**, which is the
     * safe half — the same contract `Client.permissions` states.
     */
    permissions?: {
        can_change_role?: boolean;
        can_change_tracking_mode?: boolean;
        can_deactivate?: boolean;
        can_reactivate?: boolean;
        can_reset_password?: boolean;
        can_manage_permissions?: boolean;
    };

    /** The detail page, when the server names it; otherwise built from the id. */
    url?: string | null;
}

/** One project this employee is a member of. */
export interface EmployeeProjectMembership {
    id: number;
    name: string;
    role_on_project?: string | null;
    status?: string | null;
    status_label?: string | null;
    url?: string | null;
}

/**
 * One row of `user_project_permissions`: this person may do this one thing on this one
 * project, on top of whatever their role gives them.
 */
export interface EmployeeProjectGrant {
    id: number;
    project: NamedRef & { url?: string | null };
    permission: { key: string; label?: string | null };
    granted_by?: string | null;
    granted_at?: string | null;
    /** Absent means this viewer may not take it away — so no control is drawn. */
    can_revoke?: boolean;
}

/**
 * What deactivating this person would cost, counted by the server.
 *
 * The count is the whole point of the confirmation: their open tasks do not disappear and do
 * not reassign themselves, so somebody has to pick them up. `open_tasks_url` is the server's
 * own link to exactly the tasks it counted; `openTasksUrl()` falls back to the two filters
 * `/admin/tasks` really reads, so the number is never a dead end.
 */
export interface EmployeeDeactivationImpact {
    open_task_count?: number;
    open_tasks_url?: string | null;
}

/** The employee detail payload. Optional keys may be absent by role — absent is not drawn. */
export interface EmployeeDetail extends EmployeeRow {
    phone?: string | null;
    employment_type?: string | null;
    employment_type_label?: string | null;
    joining_date?: string | null;
    manager?: NamedRef | null;
    last_login_at?: string | null;
    deactivated_at?: string | null;

    projects?: EmployeeProjectMembership[];
    project_permissions?: EmployeeProjectGrant[];
}

/**
 * The generated first-sign-in password, handed over exactly once.
 *
 * It arrives on the redirect after `POST /admin/employees` and **on no other response**: the
 * server flashes it under one key, `EmployeeController::show()` reads it once, and the same
 * response tells Inertia to encrypt and then discard its history state so Back cannot restore
 * it either. There is no email in the MVP (Part H §1) and no password-reset route, so this one
 * hand-over IS the delivery — which is why the panel spends its words on *give this to them
 * now* rather than on *keep this safe*.
 *
 * It exists nowhere else: not in `audit_logs`, not in any resource, and not in the database in
 * plaintext. If this object is absent, no panel is drawn.
 */
export interface FirstSignInCredential {
    name?: string | null;
    email?: string | null;
    password: string;
    /**
     * True when this is an Admin **re-issuing** a password rather than a new hire's first one.
     *
     * The two hand over the same thing and need the same care, but they carry a different
     * consequence: a reset ends that person's existing sessions, and a new hire has none to
     * end. The panel says whichever is true.
     */
    reissued?: boolean;
}

/** The options a create form needs. Absent means the form is not offered at all. */
export interface EmployeeFormOptions {
    roles: Option[];
    tracking_modes: Option[];
    employment_types: Option[];
    managers?: NamedRef[];
    weekdays?: WeekdayOption[];
}

/* -------------------------------------------------------------- endpoints */

/** Every Employees URL, spelled once. */
export const employeeRoutes = {
    index: '/admin/employees',
    store: '/admin/employees',
    show: (id: number): string => `/admin/employees/${id}`,
    deactivate: (id: number): string => `/admin/employees/${id}/deactivate`,
    reactivate: (id: number): string => `/admin/employees/${id}/reactivate`,
    resetPassword: (id: number): string => `/admin/employees/${id}/reset-password`,
    role: (id: number): string => `/admin/employees/${id}/role`,
    trackingMode: (id: number): string => `/admin/employees/${id}/tracking-mode`,
    grant: (id: number): string => `/admin/employees/${id}/permissions`,
    revoke: (id: number, grantId: number): string => `/admin/employees/${id}/permissions/${grantId}`,
};

/**
 * Where the open-task count goes.
 *
 * The server's own link first. The fallback is `/admin/tasks?assignee_id=<employee>&bucket=open`
 * — the two filters `TaskService::filters()` actually reads, and `assignee_id` really is the
 * employee id (`task_assignees.employee_id`), so the link carries the filters the number was
 * counted with rather than landing somewhere near it.
 */
export function openTasksUrl(employee: { id: number; impact?: EmployeeDeactivationImpact }): string {
    return employee.impact?.open_tasks_url ?? `/admin/tasks?assignee_id=${employee.id}&bucket=open`;
}

/** The detail page for a row. */
export function employeeUrl(employee: Pick<EmployeeRow, 'id' | 'url'>): string {
    return employee.url ?? employeeRoutes.show(employee.id);
}

/* -------------------------------------------------------------- formatting */

/** `REMOTE_EMPLOYEE` → `Remote employee`, `full_time` → `Full time`. */
export function humanise(value: string | null | undefined): string | null {
    if (!value) {
        return null;
    }

    const words = value.replace(/[_-]+/g, ' ').trim().toLowerCase();

    return words === '' ? null : words.charAt(0).toUpperCase() + words.slice(1);
}

/**
 * `REMOTE_EMPLOYEE` → *Remote employee*.
 *
 * `EmployeeResource` sends the raw role key and no label, so the word is composed here with the
 * same formula `EmployeeController::formOptions()` uses for the create form's select — the two
 * cannot read differently for a role that exists. A `role_label` on the resource would be the
 * better home, and this prefers it the moment it arrives.
 */
export function roleLabel(employee: Pick<EmployeeRow, 'role' | 'role_label'>): string {
    return employee.role_label ?? humanise(employee.role) ?? 'No role';
}

/**
 * `remote_timer` → *Remote timer*, `office_attendance` → *Office attendance*, `none` → *Not
 * tracked*.
 *
 * `EmployeeResource` sends the raw `tracking_mode` and no label, so the word is composed here.
 * The first two fall out of `humanise()`; `none` does not — "None" would read as *no answer*
 * where the answer is *nobody measures this person's time*, and it is the word
 * `EmployeeController::formOptions()` puts in the create form's own select. The right fix is a
 * `tracking_mode_label` on the resource, which this function already prefers the moment it
 * arrives.
 */
export function trackingModeLabel(employee: Pick<EmployeeRow, 'tracking_mode' | 'tracking_mode_label'>): string {
    if (employee.tracking_mode_label) {
        return employee.tracking_mode_label;
    }

    return employee.tracking_mode === 'none' ? 'Not tracked' : (humanise(employee.tracking_mode) ?? 'Not tracked');
}

export function statusLabel(employee: Pick<EmployeeRow, 'status' | 'status_label'>): string {
    return employee.status_label ?? humanise(employee.status) ?? 'Unknown';
}

const DATE = new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium' });
const DATE_TIME = new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium', timeStyle: 'short' });

export function formatDate(value: string | null | undefined): string | null {
    if (!value) {
        return null;
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? value : DATE.format(date);
}

export function formatDateTime(value: string | null | undefined): string | null {
    if (!value) {
        return null;
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? value : DATE_TIME.format(date);
}

/**
 * "Sun, Mon, Tue · 8 h/day · starts 09:00".
 *
 * The server's `schedule_summary` wins when it sends one. This exists for a payload that
 * sends the schedule row itself — the same sentence the Work Schedule editor prints, so the
 * two screens describe one week the same way.
 */
export function scheduleSummary(
    employee: Pick<EmployeeRow, 'schedule_summary' | 'schedule'>,
    weekdays: WeekdayOption[] = [],
): string | null {
    if (employee.schedule_summary) {
        return employee.schedule_summary;
    }

    const schedule = employee.schedule;

    if (!schedule) {
        return null;
    }

    const days = schedule.working_days
        .map((key) => weekdays.find((day) => day.value === key)?.short ?? humanise(key) ?? key)
        .join(', ');

    const start = schedule.start_time ? `starts ${schedule.start_time}` : 'no start time';

    return `${days || 'No working days'} · ${schedule.working_hours_per_day} h/day · ${start}`;
}
