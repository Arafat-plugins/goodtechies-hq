/**
 * How long a task has left, or how late it is, as one unit: `5 days left`, `3 hours overdue`.
 *
 * Pure TypeScript with **no imports** — not Vue, not the `@/` alias — so Node runs it directly
 * (`tests/js/dueCountdown.test.ts`). The cases live in `tests/fixtures/due-countdown-cases.json`,
 * which `tests/Feature/Tasks/DueCountdownAgreementTest.php` also reads, so this file's
 * `overdue` and the server's `Task::isOverdue()` / `scopeOverdue()` are held to one answer.
 *
 * **The deadline.** `due_date` is a date with no time. The server calls a task overdue when
 * `due_date < today` in the app time zone, so the task is owed until the END of its day there:
 * 00:00 of the next day in `config('app.timezone')` — never the browser's zone, which is why the
 * zone is a parameter and nothing here reads the local clock's offset.
 *
 * **The label.** The largest whole unit that fits, floored, singular for 1, never `0`. A week is
 * 7 days and a month 30, so 28 and 29 days both read `4 weeks`: the floor is kept on purpose.
 */

/** Statuses that owe nothing, and so show no countdown. Mirrors `TaskStatus::closed()`. */
const CLOSED_STATUSES: readonly string[] = ['completed', 'cancelled'];

const MINUTE = 60_000;
const HOUR = 60 * MINUTE;
const DAY = 24 * HOUR;
const WEEK = 7 * DAY;
const MONTH = 30 * DAY;

export interface DueCountdown {
    label: string;
    overdue: boolean;
}

/** `Y-m-d` → its parts, or null for anything that is not one. */
function dateParts(dueDate: string | null | undefined): [number, number, number] | null {
    const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(dueDate ?? '');

    return match === null ? null : [Number(match[1]), Number(match[2]), Number(match[3])];
}

/** How far `timeZone`'s wall clock is ahead of UTC at the instant `utcMs`, in ms. */
function zoneOffset(utcMs: number, timeZone: string): number {
    const parts: Record<string, number> = {};

    for (const part of new Intl.DateTimeFormat('en-US', {
        timeZone,
        hourCycle: 'h23',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    }).formatToParts(new Date(utcMs))) {
        parts[part.type] = Number(part.value);
    }

    const wall = Date.UTC(parts.year, parts.month - 1, parts.day, parts.hour, parts.minute, parts.second);

    return wall - Math.floor(utcMs / 1000) * 1000;
}

/**
 * The instant a date-only due date runs out: 00:00 of the following day in `timeZone`, as epoch
 * ms. The offset is read twice so a zone whose offset changes that night still lands on its own
 * midnight.
 */
export function deadlineOf(dueDate: string | null | undefined, timeZone: string): number | null {
    const parts = dateParts(dueDate);

    if (parts === null) {
        return null;
    }

    const wallMidnight = Date.UTC(parts[0], parts[1] - 1, parts[2] + 1);
    const first = wallMidnight - zoneOffset(wallMidnight, timeZone);
    const second = wallMidnight - zoneOffset(first, timeZone);

    return Number.isNaN(second) ? null : second;
}

function isClosed(status: string | null | undefined): boolean {
    return status !== null && status !== undefined && CLOSED_STATUSES.includes(status);
}

/** The server's overdue rule at the instant `now`: past its deadline and still open. */
export function isOverdueAt(
    dueDate: string | null | undefined,
    status: string | null | undefined,
    now: number,
    timeZone: string,
): boolean {
    const deadline = deadlineOf(dueDate, timeZone);

    return deadline !== null && !isClosed(status) && now >= deadline;
}

function plural(count: number, unit: string): string {
    return `${count} ${unit}${count === 1 ? '' : 's'}`;
}

/** The one unit a span of `ms` (≥ 1 minute) is spoken in. */
function span(ms: number): string {
    if (ms < HOUR) {
        return plural(Math.floor(ms / MINUTE), 'minute');
    }

    if (ms < DAY) {
        return plural(Math.floor(ms / HOUR), 'hour');
    }

    if (ms < WEEK) {
        return plural(Math.floor(ms / DAY), 'day');
    }

    if (ms < MONTH) {
        return plural(Math.floor(ms / WEEK), 'week');
    }

    return plural(Math.floor(ms / MONTH), 'month');
}

/**
 * The card's label, or null when the card shows nothing: no due date, a malformed one, or a
 * task that is Completed or Cancelled.
 */
export function dueCountdown(
    dueDate: string | null | undefined,
    status: string | null | undefined,
    now: number,
    timeZone: string,
): DueCountdown | null {
    const deadline = deadlineOf(dueDate, timeZone);

    if (deadline === null || isClosed(status)) {
        return null;
    }

    const overdue = now >= deadline;
    const distance = Math.abs(deadline - now);

    if (distance < MINUTE) {
        return { label: overdue ? 'just overdue' : 'less than a minute left', overdue };
    }

    return { label: `${span(distance)} ${overdue ? 'overdue' : 'left'}`, overdue };
}
