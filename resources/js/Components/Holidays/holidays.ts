/**
 * The holiday payload, the endpoints that write it, and the words the screens print.
 *
 * Everything here describes `App\Http\Resources\HolidayResource`. Nothing in this module
 * computes a date: the weekday, the past/today flags and the day count all arrive from the
 * server, because the server's timezone is the agency's (`Asia/Dhaka`) and the browser's is
 * whatever the laptop was last set to. A screen that formatted its own dates would print a
 * different holiday to somebody on a plane.
 *
 * What IS here is the turning of those numbers into sentences, which is a screen's job and is
 * shared by three of them — the admin list and both dashboards' "Upcoming holidays" cards.
 */

/** One holiday, exactly as `HolidayResource` sends it. */
export interface Holiday {
    id: number;
    /** `YYYY-MM-DD`, the agency's calendar day. */
    date: string;
    name: string;
    /** "Thursday", from `App\Support\Weekday` — never re-derived from `date` here. */
    weekday: string;
    is_past: boolean;
    is_today: boolean;
    /** Whole days from today. Negative in the past. */
    days_away: number;
    permissions: {
        can_update: boolean;
        can_delete: boolean;
    };
}

/** The three write endpoints. One base, so nothing here spells the prefix twice. */
export const holidayRoutes = {
    index: (year?: number) => (year === undefined ? '/admin/holidays' : `/admin/holidays?year=${year}`),
    store: () => '/admin/holidays',
    update: (id: number) => `/admin/holidays/${id}`,
    destroy: (id: number) => `/admin/holidays/${id}`,
};

/**
 * `2026-12-16` → `16 December 2026`.
 *
 * The one piece of date formatting on the client, and it is a re-ordering of parts the server
 * already sent rather than a parse: `new Date('2026-12-16')` is parsed as UTC midnight and
 * then printed in the viewer's zone, which turns 16 December into 15 December for anybody west
 * of Greenwich. Splitting the string cannot do that.
 */
const MONTHS = [
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

export function formatHolidayDate(date: string): string {
    const [year, month, day] = date.split('-');
    const name = MONTHS[Number(month) - 1];

    if (!name || !day || !year) {
        return date;
    }

    return `${Number(day)} ${name} ${year}`;
}

/** `2026-12-16` → `16 Dec`. The compact form, for a dashboard card's leading column. */
export function formatHolidayDayMonth(date: string): string {
    const [, month, day] = date.split('-');
    const name = MONTHS[Number(month) - 1];

    if (!name || !day) {
        return date;
    }

    return `${Number(day)} ${name.slice(0, 3)}`;
}

/**
 * How far off it is, in words: "Today", "Tomorrow", "In 12 days", "6 days ago".
 *
 * **This is the second encoding**, not decoration. A dashboard card that only emphasised the
 * nearest holiday would say nothing in greyscale and nothing to a screen reader (DESIGN.md §6
 * rule 6), and "Today" is also the single most useful word the card can print — a holiday that
 * is today is the one somebody opened the page to check.
 */
export function holidayWhen(holiday: Holiday): string {
    if (holiday.is_today) {
        return 'Today';
    }

    const days = holiday.days_away;

    if (days === 1) {
        return 'Tomorrow';
    }

    if (days === -1) {
        return 'Yesterday';
    }

    return days > 0 ? `In ${days} days` : `${Math.abs(days)} days ago`;
}

/**
 * The sentence the delete confirmation asks. **It names the holiday and its date**, because a
 * list of twenty-one rows, several of them called "Eid ul-Adha holiday", is a list where "Are
 * you sure?" is not a question anybody can answer.
 */
export function holidayDeletePrompt(holiday: Holiday): string {
    return `Remove ${holiday.name} from ${formatHolidayDate(holiday.date)}?`;
}
