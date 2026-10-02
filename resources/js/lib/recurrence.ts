/**
 * A Recurring project's deadline, previewed in the project form. The server computes the stored
 * value (`App\Support\ProjectRecurrenceFrequency::deadlineFrom`); this mirrors it on plain `YYYY-MM-DD`
 * strings — no `Date` in local time, so there is no timezone drift.
 *
 * Monthly never overflows: Jan 31 → Feb 28 (Feb 29 in a leap year), like Carbon's
 * `addMonthNoOverflow()`.
 */
export type ProjectRecurrenceFrequency = 'daily' | 'weekly' | 'biweekly' | 'monthly';

const ISO_DATE = /^(\d{4})-(\d{2})-(\d{2})$/;

function pad(value: number, width: number): string {
    return String(value).padStart(width, '0');
}

function isLeapYear(year: number): boolean {
    return (year % 4 === 0 && year % 100 !== 0) || year % 400 === 0;
}

function daysInMonth(year: number, month: number): number {
    return [31, isLeapYear(year) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31][month - 1];
}

/** `days` after the given calendar date, counted in UTC so no offset or DST change applies. */
function addDays(year: number, month: number, day: number, days: number): string {
    const date = new Date(Date.UTC(year, month - 1, day + days));

    return `${pad(date.getUTCFullYear(), 4)}-${pad(date.getUTCMonth() + 1, 2)}-${pad(date.getUTCDate(), 2)}`;
}

/**
 * The deadline one period after `startIso`, or null when the start is blank or not a valid
 * `YYYY-MM-DD` date, or the frequency is unknown.
 */
export function recurringDeadline(startIso: string, frequency: string | null | undefined): string | null {
    const match = ISO_DATE.exec(startIso.trim());

    if (!match) {
        return null;
    }

    const year = Number(match[1]);
    const month = Number(match[2]);
    const day = Number(match[3]);

    if (month < 1 || month > 12 || day < 1 || day > daysInMonth(year, month)) {
        return null;
    }

    switch (frequency) {
        case 'daily':
            return addDays(year, month, day, 1);
        case 'weekly':
            return addDays(year, month, day, 7);
        case 'biweekly':
            return addDays(year, month, day, 14);
        case 'monthly': {
            const nextYear = month === 12 ? year + 1 : year;
            const nextMonth = month === 12 ? 1 : month + 1;
            const nextDay = Math.min(day, daysInMonth(nextYear, nextMonth));

            return `${pad(nextYear, 4)}-${pad(nextMonth, 2)}-${pad(nextDay, 2)}`;
        }
        default:
            return null;
    }
}
