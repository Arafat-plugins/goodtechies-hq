import { router } from '@inertiajs/vue3';
import type { Density, SortState } from '@/Components/DataTable/types';

/**
 * The two places a list screen keeps its state.
 *
 * **The query string** holds everything that changes what the server returns — search,
 * filters, sort, page, rows per page — so any filtered view is a URL somebody can paste
 * into a message. **localStorage** holds only what is personal to the viewer and means
 * nothing to anyone else: which columns they hide and how tight they like their rows.
 *
 * Every `localStorage` call is wrapped: Safari's private mode throws on write, and a
 * blocked-cookies setting throws on read, and neither is a reason for a table to break.
 */

export type QueryValue = string | number | boolean | null | undefined;

/** The current query string as a flat record. Empty before the client has a `window`. */
export function currentQuery(): Record<string, string> {
    if (typeof window === 'undefined') {
        return {};
    }

    const query: Record<string, string> = {};

    new URLSearchParams(window.location.search).forEach((value, key) => {
        query[key] = value;
    });

    return query;
}

/** Reads one parameter out of a URL (defaults to the current one). */
export function queryParam(key: string, url?: string): string | null {
    const search = url === undefined ? currentQuery() : queryOf(url);

    return search[key] ?? null;
}

/** Splits any Inertia `page.url` into its query parameters. */
export function queryOf(url: string): Record<string, string> {
    const query: Record<string, string> = {};
    const mark = url.indexOf('?');

    if (mark === -1) {
        return query;
    }

    new URLSearchParams(url.slice(mark + 1)).forEach((value, key) => {
        query[key] = value;
    });

    return query;
}

/** Reads `?sort=&dir=` out of a URL. `dir` is `asc` unless it says otherwise. */
export function sortFrom(url: string): SortState | null {
    const query = queryOf(url);
    const key = query.sort;

    if (!key) {
        return null;
    }

    return { key, dir: query.dir === 'desc' ? 'desc' : 'asc' };
}

/**
 * Merges `patch` into the current query string and navigates.
 *
 * `null`, `undefined`, `''` and `false` drop their key, so a cleared filter leaves the URL
 * rather than sitting in it as `client_id=`. Changing anything but the page resets `?page`,
 * because page 4 of the old filter is not page 4 of the new one.
 */
export function pushQuery(patch: Record<string, QueryValue>, options: { keepPage?: boolean } = {}): void {
    if (typeof window === 'undefined') {
        return;
    }

    const next = currentQuery();

    for (const [key, value] of Object.entries(patch)) {
        if (value === null || value === undefined || value === '' || value === false) {
            delete next[key];
        } else {
            next[key] = value === true ? '1' : String(value);
        }
    }

    if (options.keepPage !== true) {
        delete next.page;
    }

    router.get(window.location.pathname, next, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

/**
 * Merges `patch` into the query string **without asking the server for anything**.
 *
 * `pushQuery()` is for state the server answers — a filter, a sort, a page. This is for state
 * only the browser holds: which record an overlay is showing. The two look the same in the
 * address bar, which is the point (the view is still a URL somebody can send), but a visit
 * here would be wrong twice over. It is a round trip for a payload that has not changed; and
 * a list that re-renders mid-visit swaps its rows for a loading skeleton, which destroys the
 * row element an overlay has to give focus back to when it closes.
 *
 * The existing history entry's state object is carried over untouched, so Inertia's own
 * back/forward handling still finds what it put there.
 */
export function syncQuery(patch: Record<string, QueryValue>): void {
    if (typeof window === 'undefined') {
        return;
    }

    const next = currentQuery();

    for (const [key, value] of Object.entries(patch)) {
        if (value === null || value === undefined || value === '' || value === false) {
            delete next[key];
        } else {
            next[key] = value === true ? '1' : String(value);
        }
    }

    const search = new URLSearchParams(next).toString();

    try {
        window.history.replaceState(
            window.history.state,
            '',
            window.location.pathname + (search === '' ? '' : `?${search}`),
        );
    } catch {
        // A browser that refuses the rewrite loses only the shareable URL, not the overlay.
    }
}

/** Drops every parameter — the "Clear all" of a filter bar. */
export function resetQuery(keep: string[] = []): void {
    if (typeof window === 'undefined') {
        return;
    }

    const current = currentQuery();
    const next: Record<string, string> = {};

    for (const key of keep) {
        if (current[key] !== undefined) {
            next[key] = current[key];
        }
    }

    router.get(window.location.pathname, next, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function storageKey(id: string, part: 'columns' | 'density' | 'groups', scope?: string): string {
    return scope === undefined ? `hq.table.${id}.${part}` : `hq.table.${id}.${part}.${scope}`;
}

function read(key: string): string | null {
    try {
        return window.localStorage.getItem(key);
    } catch {
        return null;
    }
}

function write(key: string, value: string): void {
    try {
        window.localStorage.setItem(key, value);
    } catch {
        /* Private mode: the preference just does not survive this session. */
    }
}

/** The column keys this viewer has hidden on this table, or null when they never chose. */
export function readHiddenColumns(id: string): string[] | null {
    const raw = read(storageKey(id, 'columns'));

    if (raw === null) {
        return null;
    }

    try {
        const parsed: unknown = JSON.parse(raw);

        return Array.isArray(parsed) ? parsed.filter((key): key is string => typeof key === 'string') : null;
    } catch {
        return null;
    }
}

export function writeHiddenColumns(id: string, hidden: string[]): void {
    write(storageKey(id, 'columns'), JSON.stringify(hidden));
}

export function readDensity(id: string): Density | null {
    const raw = read(storageKey(id, 'density'));

    return raw === 'comfortable' || raw === 'compact' ? raw : null;
}

export function writeDensity(id: string, density: Density): void {
    write(storageKey(id, 'density'), density);
}

/**
 * Which groups this viewer has opened or closed on a grouped table.
 *
 * Only the groups they actually clicked are stored — `{ completed: true }`, not a full map
 * — so a group that appears for the first time next week still follows the table's own
 * default instead of inheriting somebody's month-old click on a different group.
 *
 * `scope` is the group-by variant. Two variants can mint the same key (a project id and an
 * employee id are both `"7"`), so each variant gets its own record rather than one of them
 * silently collapsing the other's groups.
 *
 * Which groups start open is *not* stored: it is a rule `DataTable` applies, and storing it
 * would freeze today's rule into every viewer's browser.
 */
export function readGroupState(id: string, scope: string): Record<string, boolean> {
    const raw = read(storageKey(id, 'groups', scope));

    if (raw === null) {
        return {};
    }

    try {
        const parsed: unknown = JSON.parse(raw);

        if (parsed === null || typeof parsed !== 'object' || Array.isArray(parsed)) {
            return {};
        }

        const state: Record<string, boolean> = {};

        for (const [key, value] of Object.entries(parsed as Record<string, unknown>)) {
            if (typeof value === 'boolean') {
                state[key] = value;
            }
        }

        return state;
    } catch {
        return {};
    }
}

export function writeGroupState(id: string, scope: string, state: Record<string, boolean>): void {
    write(storageKey(id, 'groups', scope), JSON.stringify(state));
}
