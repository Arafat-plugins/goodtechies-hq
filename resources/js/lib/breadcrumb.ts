import type { NavGroup, NavItem } from '@/navigation/types';
import { isActiveHref, isLiveItem } from '@/navigation/types';

/**
 * The top bar's breadcrumb, derived from the nav tree and the current URL — never
 * hard-coded per page.
 *
 * Three crumbs at most: the nav group, the nav row, and one trailing crumb for what the
 * URL adds on top of that row (`/admin/clients/1` → the client's name, `/admin/clients/create`
 * → `Create`). The last crumb is always the current page and therefore never a link.
 *
 * Everything here is a pure function of its arguments, so it is unit-testable without a
 * browser: `buildBreadcrumbs(adminNav, '/admin/clients/1', …)` is the whole contract.
 */

export interface Crumb {
    label: string;
    /** Absent on the current page and on a group, which is a heading, not a route. */
    href?: string;
}

export interface BreadcrumbOptions {
    /** A crumb the page supplies itself; it wins over anything derived from the URL. */
    trailing?: string | null;
    /** The Inertia page props, used to name a record the URL only identifies by id. */
    pageProps?: Record<string, unknown> | null;
}

interface NavMatch {
    group: NavGroup;
    item: NavItem;
}

/** The path without its query string, which `isActiveHref` tolerates but slicing does not. */
export function pathOf(url: string): string {
    return url.split(/[?#]/)[0] ?? url;
}

/**
 * The deepest live nav row this URL sits under. Longest href wins, so `/admin/projects/1`
 * would prefer a future `/admin/projects/1/tasks` row over `/admin/projects`.
 */
export function matchNavItem(groups: NavGroup[], url: string): NavMatch | null {
    const path = pathOf(url);
    let best: NavMatch | null = null;

    for (const group of groups) {
        for (const item of group.items) {
            if (!isLiveItem(item) || !isActiveHref(path, item.href)) {
                continue;
            }

            if (!best || (item.href?.length ?? 0) > (best.item.href?.length ?? 0)) {
                best = { group, item };
            }
        }
    }

    return best;
}

/** `1`, `42`, a uuid — a segment that identifies a record rather than naming a screen. */
export function isIdSegment(segment: string): boolean {
    return /^\d+$/.test(segment) || /^[0-9a-f]{8}-[0-9a-f]{4}-/i.test(segment);
}

/** `financial-reports` → `Financial reports`. Sentence case, not title case. */
export function humanizeSegment(segment: string): string {
    const words = segment.replace(/[-_]+/g, ' ').trim();

    return words.charAt(0).toUpperCase() + words.slice(1);
}

/**
 * The name of the one record this page is about, taken from the Inertia resource the page
 * already receives (`{ data: { name } }` — what `ClientResource` and `ProjectResource`
 * serialize). Two candidate resources is ambiguous, so it returns null and the caller
 * falls back to the id; no page has to be edited to get its own name in the breadcrumb.
 */
export function resourceName(pageProps: Record<string, unknown> | null | undefined): string | null {
    if (!pageProps) {
        return null;
    }

    const found: string[] = [];

    for (const value of Object.values(pageProps)) {
        const data = (value as { data?: unknown } | null)?.data;

        if (!data || typeof data !== 'object' || Array.isArray(data)) {
            continue;
        }

        const record = data as Record<string, unknown>;
        const name = record.name ?? record.title;

        if (typeof name === 'string' && name.trim() !== '') {
            found.push(name.trim());
        }
    }

    return found.length === 1 ? found[0]! : null;
}

/** What the URL adds beyond the matched nav row, as one crumb. */
export function trailingCrumb(
    url: string,
    href: string,
    pageProps?: Record<string, unknown> | null,
): string | null {
    const rest = pathOf(url).slice(href.length).replace(/^\/+|\/+$/g, '');

    if (rest === '') {
        return null;
    }

    const segments = rest.split('/');
    const last = segments[segments.length - 1]!;

    if (!isIdSegment(last)) {
        return humanizeSegment(last);
    }

    return resourceName(pageProps) ?? `#${last}`;
}

export function buildBreadcrumbs(
    groups: NavGroup[],
    url: string,
    options: BreadcrumbOptions = {},
): Crumb[] {
    const match = matchNavItem(groups, url);

    if (!match) {
        // The only live screen outside every role's nav tree.
        return pathOf(url).startsWith('/profile') ? [{ label: 'Profile' }] : [];
    }

    const crumbs: Crumb[] = [];
    const groupLabel = match.group.label;

    // A one-group nav has no sections to name (`Menu`), and a group that repeats its only
    // row's label ("Company / Company Dashboard") says nothing twice.
    if (groups.length > 1 && groupLabel.toLowerCase() !== match.item.label.toLowerCase()) {
        crumbs.push({ label: groupLabel });
    }

    crumbs.push({ label: match.item.label, href: match.item.href });

    const trailing =
        options.trailing?.trim() || trailingCrumb(url, match.item.href!, options.pageProps);

    if (trailing) {
        crumbs.push({ label: trailing });
    }

    // The last crumb is the page you are on, so it is text, not a link.
    const last = crumbs[crumbs.length - 1]!;
    crumbs[crumbs.length - 1] = { label: last.label };

    return crumbs;
}
