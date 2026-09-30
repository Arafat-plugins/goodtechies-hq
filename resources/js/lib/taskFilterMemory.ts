/**
 * Each person's Tasks filters, kept until they remove them (brief 008).
 *
 * `tableState.ts`'s rule holds: the URL carries the shareable state and localStorage the
 * personal state. The filters are both. The URL is still where they live, so a filtered view
 * is a link somebody can send. This module only remembers the last set a person had, and
 * puts it back on a Tasks URL that arrives without one:
 *
 * - **Save.** Every Tasks list page that loads (List, Board, Calendar, Gantt) writes the
 *   filter parameters its URL carries, including none at all. So removing a chip, *Clear all*
 *   and going back to *All tasks* save the smaller set, and an explicit link (a shared URL,
 *   a dashboard tile, the `/my-tasks` redirect) replaces the saved set.
 * - **Restore.** A visit to a Tasks list page from outside Tasks, with no filter parameter
 *   of its own, is rewritten to carry the saved set **before it is sent**. The unfiltered page
 *   is never requested, so it is never painted. A first full load (a typed URL, a bookmark)
 *   is swapped for the filtered URL before the app mounts.
 *
 * One set per person, keyed by user id, shared by the four views. A second person on the
 * same browser has their own key, and logging out does not clear it. Only ids and keys are
 * stored, never task text. Overlay state (`?detail=`, `?new=`) and view state (`group_by`,
 * the Calendar/Gantt window `date_from`/`date_to`) are not filters here, so they are never
 * saved. Neither is `search`: it has no chip, so a remembered one could not be removed.
 *
 * This file has no imports, so `npm run test:js` can run it. `app.ts` wires it to the router.
 */

/** The parameters that make up a person's Tasks filter set: the chips and the scope. */
export const TASK_FILTER_KEYS = [
    'scope',
    'status',
    'priority',
    'project_id',
    'assignee_id',
    'tag_id',
    'bucket',
    'overdue',
    'archived',
    // Flow F2: "Show subtasks".
    'subtasks',
] as const;

export type TaskFilterSet = Record<string, string>;

const LIST_PATH = /^\/(?:admin|employee)\/tasks(?:\/(?:board|calendar|gantt))?\/?$/;

/** Ids, enum keys and `1`: a stored value that looks like anything else is dropped. */
const SAFE_VALUE = /^[A-Za-z0-9_-]{1,64}$/;

/** The four Tasks list views on either surface. A task's own page (`/tasks/12`) is not one. */
export function isTaskListPath(pathname: string): boolean {
    return LIST_PATH.test(pathname);
}

/** The filter parameters a query string carries, empty values left out. */
export function filtersOf(search: string | URLSearchParams): TaskFilterSet {
    const params = typeof search === 'string' ? new URLSearchParams(search) : search;
    const set: TaskFilterSet = {};

    for (const key of TASK_FILTER_KEYS) {
        const value = params.get(key);

        if (value !== null && value !== '' && SAFE_VALUE.test(value)) {
            set[key] = value;
        }
    }

    return set;
}

/**
 * `set` without what Due today / Overdue used to save from *Add filter*: `overdue=1` and a
 * `bucket` of `overdue` or `due_today` (brief 017). Those now belong to the scope dropdown, whose
 * `scope=` is still saved. The server still reads the old keys, so a link carrying them works;
 * they are just never remembered or put back, and an old saved set loses them on read.
 */
export function savableFilters(set: TaskFilterSet): TaskFilterSet {
    const next: TaskFilterSet = { ...set };

    delete next.overdue;

    if (next.bucket === 'overdue' || next.bucket === 'due_today') {
        delete next.bucket;
    }

    return next;
}

export function hasFilters(set: TaskFilterSet | null): set is TaskFilterSet {
    return set !== null && Object.keys(set).length > 0;
}

/** `url` with `set` added. Every other parameter (`detail`, `group_by`, …) is kept. */
export function withFilters(url: URL, set: TaskFilterSet): URL {
    const next = new URL(url.href);

    for (const [key, value] of Object.entries(set)) {
        next.searchParams.set(key, value);
    }

    return next;
}

/**
 * The set to restore for a visit to `target`, or null to let the visit go as it is.
 *
 * Only a visit into Tasks from somewhere else is restored. A visit from one Tasks view to
 * another is the filter bar or the view switcher at work, and the parameters it carries are
 * the set, even when there are none (*Clear all*).
 */
export function restoreFor(target: URL, fromPathname: string | null, saved: TaskFilterSet | null): TaskFilterSet | null {
    if (!isTaskListPath(target.pathname)) {
        return null;
    }

    if (fromPathname !== null && isTaskListPath(fromPathname)) {
        return null;
    }

    if (hasFilters(filtersOf(target.searchParams))) {
        return null;
    }

    return hasFilters(saved) ? saved : null;
}

/* ------------------------------------------------------------------ storage */

export function storageKey(userId: number): string {
    return `hq.tasks.filters.${userId}`;
}

/** The saved set, or null when this person never saved one (or storage is unavailable). */
export function readSavedFilters(userId: number): TaskFilterSet | null {
    let raw: string | null;

    try {
        raw = window.localStorage.getItem(storageKey(userId));
    } catch {
        return null;
    }

    if (raw === null) {
        return null;
    }

    try {
        const parsed: unknown = JSON.parse(raw);

        if (parsed === null || typeof parsed !== 'object' || Array.isArray(parsed)) {
            return null;
        }

        const params = new URLSearchParams();

        for (const [key, value] of Object.entries(parsed as Record<string, unknown>)) {
            if (typeof value === 'string') {
                params.set(key, value);
            }
        }

        return savableFilters(filtersOf(params));
    } catch {
        return null;
    }
}

export function writeSavedFilters(userId: number, set: TaskFilterSet): void {
    try {
        window.localStorage.setItem(storageKey(userId), JSON.stringify(savableFilters(set)));
    } catch {
        /* Private mode: the filters just do not outlive this page. */
    }
}
