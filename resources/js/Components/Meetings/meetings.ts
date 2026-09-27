import type { StatusKey } from '@/Components/StatusBadge.vue';

/**
 * Everything meeting-shaped that more than one screen needs: the payload's types, the five
 * endpoints, and the handful of formatters.
 *
 * **Two slices import this file** — the list / calendar / form slice that wrote it, and the
 * detail slice that reads it. Nothing here is specific to either, and nothing here is deleted
 * once exported.
 */

/* ------------------------------------------------------------------ the payload */

/**
 * A meeting's state, as `Meeting::tone()` resolves it on the server: `waiting` while it is
 * still to come, `done` once the clock has passed it, `cancelled` when it was called off.
 *
 * It is a `StatusKey`, so it goes straight into `StatusBadge` and **nothing here re-derives it**
 * (DESIGN.md §4.2). Note that the clock is already folded in: a cancelled meeting reads
 * `cancelled` whether or not its end time has passed, because it did not happen.
 *
 * Always printed with `state_label` beside it — a tone alone is state carried by colour (§5.6).
 */
export type MeetingState = Extract<StatusKey, 'waiting' | 'done' | 'cancelled'>;

/** A participant's answer, as `RsvpStatus` spells it. */
export type MeetingRsvp = 'pending' | 'accepted' | 'declined';

export interface MeetingPerson {
    id: number | null;
    name: string;
}

/** One invitable person, as the form's picker lists them: id and name, and nothing else. */
export interface MeetingInvitee {
    id: number;
    name: string;
}

export interface MeetingAttendee {
    id: number;
    name: string;
    rsvp: MeetingRsvp;
}

/** The linked project or task, as `MeetingService::linkedContextFor()` sends it: id and name. */
export interface MeetingLink {
    id: number;
    name?: string;
    title?: string;
}

/**
 * One meeting, exactly as `MeetingResource` sends it.
 *
 * **`project` and `task` are optional, and that is load-bearing.** They are *absent* — not null
 * — when this viewer may not see the link, because somebody can be in a meeting about a project
 * they are not on, and a `null` would say "there is a project here and you may not have it".
 * Declare them optional, read them with `?.`, and never write `project: null` anywhere.
 */
export interface Meeting {
    id: number;
    title: string;
    start_at: string | null;
    end_at: string | null;
    status: 'scheduled' | 'cancelled';
    agenda: string | null;
    meet_link: string | null;
    has_meet_link: boolean;
    state: MeetingState;
    state_label: string;
    duration_minutes: number;
    organizer: MeetingPerson;
    is_organizer: boolean;
    is_participant: boolean;
    participants: MeetingAttendee[];
    my_rsvp: MeetingRsvp | null;
    permissions: {
        can_update: boolean;
        can_cancel: boolean;
        can_rsvp: boolean;
    };
    project?: { id: number; name: string };
    task?: { id: number; title: string };
}

/** One day of the calendar or the list, with the meetings that start on it. */
export interface MeetingDay {
    /** `YYYY-MM-DD`. */
    date: string;
    /** *Today*, *Tomorrow*, *Yesterday*, or the full date — written by the server, in its clock. */
    label: string;
    is_today: boolean;
    meetings: Meeting[];
}

/** The List's two halves. `has_more` is the server saying it stopped counting. */
export interface MeetingSection {
    key: 'upcoming' | 'past';
    label: string;
    has_more: boolean;
    days: MeetingDay[];
}

/**
 * The window the server actually queried — never inferred from the meetings that came back.
 * A grid that guessed its own month would be wrong on the first empty one.
 */
export interface MeetingWindow {
    /** First and last day the payload covers, inclusive, `YYYY-MM-DD`. */
    from: string;
    to: string;
    /** What the heading prints: *September 2026*, or *21 Sep – 27 Sep 2026*. */
    label: string;
    /** The value that goes back in the URL: `2026-09` for a month, `2026-09-21` for a week. */
    key: string;
    previous: string;
    next: string;
    /** The key of the month or week the server's clock is in, so *Today* knows when to light up. */
    current: string;
    /** Every day the view draws, in order — so no grid does date arithmetic of its own. */
    days: string[];
    /**
     * Today, in the server's clock and as `YYYY-MM-DD`.
     *
     * A grid cannot read this off the `days` payload, which only carries the days that have
     * meetings — so a today with nothing on it would never have been marked.
     */
    today: string;
    /** Month view only: the month itself, inside the grid's wider span. */
    month_from?: string;
    month_to?: string;
}

export type MeetingView = 'list' | 'month' | 'week';

export interface MeetingFilters {
    scope: 'mine' | 'all';
    when: 'all' | 'upcoming' | 'past';
}

/* ------------------------------------------------------------------ the endpoints */

/**
 * Every URL a meeting has, in one place.
 *
 * Written as paths rather than through a route helper because these are the contract between
 * the two Phase 7 slices: the list links to a detail page and an edit form, and the detail page
 * posts to routes this file already names. Anything that changes here changes once.
 */
export function meetingRoutes(id: number) {
    const base = `/meetings/${id}`;

    return {
        show: base,
        edit: `${base}/edit`,
        update: base,
        cancel: `${base}/cancel`,
        rsvp: `${base}/rsvp`,
        notes: `${base}/notes`,
        actionItems: `${base}/action-items`,
    };
}

/** The Meetings screen, with the view and the window in the query string (DESIGN.md §5.10). */
export function meetingsHref(params: Record<string, string | number | undefined | null> = {}): string {
    const search = new URLSearchParams();

    for (const [key, value] of Object.entries(params)) {
        if (value !== undefined && value !== null && value !== '') {
            search.set(key, String(value));
        }
    }

    const query = search.toString();

    return query === '' ? '/meetings' : `/meetings?${query}`;
}

export const MEETING_CREATE_HREF = '/meetings/create';

/* ------------------------------------------------------------------ the RSVP vocabulary */

/**
 * The words and the tones for an RSVP.
 *
 * `MeetingResource` sends the raw enum value, so this is the one place the three answers become
 * English and colour — shared by the list, the calendar and the detail page rather than written
 * out in each of them. It mirrors `App\Support\RsvpStatus::label()` and `::tone()`; if a fourth
 * answer is ever added there (`tentative` is the one that was left out), it is added here and
 * nowhere else.
 */
export const RSVP_LABEL: Record<MeetingRsvp, string> = {
    pending: 'No answer yet',
    accepted: 'Going',
    declined: 'Not going',
};

export const RSVP_TONE: Record<MeetingRsvp, StatusKey> = {
    pending: 'waiting',
    accepted: 'done',
    declined: 'cancelled',
};

/* ------------------------------------------------------------------ formatting */

const TIME = new Intl.DateTimeFormat('en-GB', { hour: '2-digit', minute: '2-digit', hour12: false });
const DAY_AND_MONTH = new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short' });
const FULL_DATE = new Intl.DateTimeFormat('en-GB', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
});

function asDate(iso: string | null): Date | null {
    if (iso === null || iso === '') {
        return null;
    }

    const date = new Date(iso);

    return Number.isNaN(date.getTime()) ? null : date;
}

/** `14:30`, or an em dash when there is no time to print. */
export function meetingTime(iso: string | null): string {
    const date = asDate(iso);

    return date === null ? '—' : TIME.format(date);
}

/** `14:30 – 15:00`, collapsing to one time when the end is missing. */
export function meetingTimeRange(start: string | null, end: string | null): string {
    const from = asDate(start);
    const to = asDate(end);

    if (from === null) {
        return '—';
    }

    if (to === null) {
        return TIME.format(from);
    }

    const sameDay = from.toDateString() === to.toDateString();

    return sameDay
        ? `${TIME.format(from)} – ${TIME.format(to)}`
        : `${TIME.format(from)} – ${DAY_AND_MONTH.format(to)} ${TIME.format(to)}`;
}

/** `45 min`, `1 h`, `1 h 30 min` — the shape a meeting's length is spoken in. */
export function meetingDuration(minutes: number): string {
    if (minutes < 60) {
        return `${minutes} min`;
    }

    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;

    return rest === 0 ? `${hours} h` : `${hours} h ${rest} min`;
}

/**
 * `Monday 21 September 2026` from a plain `YYYY-MM-DD`.
 *
 * Built in **UTC** and read back with `timeZone: 'UTC'`, because a calendar day has no time and
 * `new Date('2026-09-21')` west of Greenwich is the 20th. The grid draws days, so it counts days.
 */
export function meetingDayLabel(date: string): string {
    const [year, month, day] = date.split('-').map(Number);

    if (!year || !month || !day) {
        return date;
    }

    return new Intl.DateTimeFormat('en-GB', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
        timeZone: 'UTC',
    }).format(new Date(Date.UTC(year, month - 1, day)));
}

/** Just the number a calendar cell prints. The accessible name is always the full date. */
export function meetingDayNumber(date: string): number {
    return Number(date.slice(8, 10));
}

/** Is this `YYYY-MM-DD` inside `[from, to]`, both inclusive? Plain string comparison, ISO order. */
export function meetingDayWithin(date: string, from?: string, to?: string): boolean {
    if (from === undefined || to === undefined) {
        return true;
    }

    return date >= from && date <= to;
}

/** The Monday of the week a `YYYY-MM-DD` falls in, as `YYYY-MM-DD`. Monday-start, like the grid. */
export function meetingWeekOf(date: string): string {
    const [year, month, day] = date.split('-').map(Number);
    const at = new Date(Date.UTC(year ?? 1970, (month ?? 1) - 1, day ?? 1));
    const monday = (at.getUTCDay() + 6) % 7;

    at.setUTCDate(at.getUTCDate() - monday);

    return at.toISOString().slice(0, 10);
}

/** The short weekday headings a month grid wears, Monday first. */
export const WEEKDAY_NAMES = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as const;

export { FULL_DATE };
