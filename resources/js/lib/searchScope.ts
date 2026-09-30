/**
 * Which records the top-bar search looks in, decided by the page the person is on (brief 010).
 *
 * The client's rule: *"the searchbar will only search inside the current tab — full app search
 * only in the dashboard"*. So on Tasks the palette finds tasks, on Projects projects, and so on;
 * on a Dashboard it finds everything. A page with no searchable type of its own (Attendance,
 * Leave, Payroll, Settings, Reports, Profile, Time, …) also searches everything, which is the
 * fallback the client approved: an empty scope would be a search box that can find nothing.
 *
 * This is the ONE place the route → types map lives. It is pure (no imports) so
 * `tests/js/searchScope.test.ts` runs it under plain Node.
 *
 * It decides only what to ASK for. What a person may FIND is still decided entirely on the
 * server: `GET /search?type=…` narrows which groups run and every group that runs is scoped by
 * `SearchService` exactly as before, so a scope here can hide a group and never reveal a row.
 *
 * The Accountant's `/accountant/projects` is deliberately NOT mapped to `project`: that page is
 * a finance window and the Accountant holds no `projects.view`, so a project-scoped search there
 * could only ever answer nothing. It falls back to everything, which for them is the books.
 */

/** Mirrors `App\Support\SearchableType`. */
export type SearchType =
    | 'project'
    | 'task'
    | 'message'
    | 'meeting'
    | 'employee'
    | 'client'
    | 'file'
    | 'income'
    | 'expense';

export interface SearchScope {
    /** The types to search, or null for every type. */
    types: SearchType[] | null;
    /** Lower-case plural for the placeholder: `Search tasks…`. */
    noun: string;
    /** The scope label shown inside the palette. */
    label: string;
}

export const EVERYTHING: SearchScope = { types: null, noun: 'everything', label: 'Everything' };

/**
 * First match wins. Each prefix is a whole path segment (`/admin/tasks` matches `/admin/tasks`
 * and `/admin/tasks/12`, never `/admin/tasksx`). `(admin|employee)` covers both work surfaces;
 * the rest are shared routes, reached the same way from every shell.
 *
 * Dashboards are listed explicitly even though they would fall through to EVERYTHING anyway,
 * because "full search on the Dashboard" is the client's own rule and not a fallback.
 */
const RULES: ReadonlyArray<readonly [RegExp, SearchScope]> = [
    [/^\/(admin|employee|accountant)\/dashboard$/, EVERYTHING],
    [
        /^\/(admin|employee)\/(tasks|my-tasks)$|^\/admin\/recurring-tasks$/,
        { types: ['task'], noun: 'tasks', label: 'Tasks' },
    ],
    [/^\/(admin|employee)\/projects$/, { types: ['project'], noun: 'projects', label: 'Projects' }],
    [/^\/admin\/clients$/, { types: ['client'], noun: 'clients', label: 'Clients' }],
    [/^\/messages$/, { types: ['message'], noun: 'messages', label: 'Messages' }],
    [/^\/meetings$/, { types: ['meeting'], noun: 'meetings', label: 'Meetings' }],
    [/^\/team$|^\/admin\/employees$/, { types: ['employee'], noun: 'people', label: 'People' }],
    [/^\/finance\/income$/, { types: ['income'], noun: 'income', label: 'Income' }],
    [/^\/finance\/expenses$/, { types: ['expense'], noun: 'expenses', label: 'Expenses' }],
    [/^\/finance$/, { types: ['income', 'expense'], noun: 'finance', label: 'Finance' }],
];

/** The path of an Inertia `page.url` (or any URL), without query, hash or trailing slash. */
function pathOf(url: string): string {
    const path = url.split(/[?#]/)[0] ?? '';

    return path.length > 1 ? path.replace(/\/+$/, '') : path;
}

/** Every leading run of whole segments: `/a/b/c` → `/a/b/c`, `/a/b`, `/a`. */
function prefixesOf(path: string): string[] {
    const segments = path.split('/').filter((segment) => segment !== '');

    return segments.map((_, index) => `/${segments.slice(0, segments.length - index).join('/')}`);
}

/** The search scope for the page at `url`. */
export function searchScopeFor(url: string): SearchScope {
    for (const prefix of prefixesOf(pathOf(url))) {
        for (const [pattern, scope] of RULES) {
            if (pattern.test(prefix)) {
                return scope;
            }
        }
    }

    return EVERYTHING;
}

/** The `GET /search` address for a term in a scope; no `type` at all means every type. */
export function searchUrl(term: string, scope: SearchScope): string {
    const url = `/search?q=${encodeURIComponent(term)}`;

    return scope.types === null ? url : `${url}&type=${encodeURIComponent(scope.types.join(','))}`;
}
