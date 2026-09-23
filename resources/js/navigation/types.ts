import type { Component } from 'vue';
import type { Role } from '@/types';

/**
 * One sidebar row. An item with `phase` is not built yet: it renders disabled,
 * without a link, labelled "Arrives in Phase N". To enable it in a later phase,
 * add `href` and remove `phase` — the nav config is the only place to do that.
 */
export interface NavItem {
    label: string;
    href?: string;
    icon: Component;
    phase?: number;
    /**
     * What this row counts as "here", when that is wider than where it points.
     *
     * Tasks points at the Board because that is the default view, but List and Calendar are
     * the same destination to a reader — the row must stay lit on all three. Without this,
     * pointing a row at a sub-view silently unlights it everywhere else.
     */
    activePrefix?: string;
}

export interface NavGroup {
    label: string;
    items: NavItem[];
}

export const ROLE_LABELS: Record<Role, string> = {
    ADMIN: 'Admin',
    EMPLOYEE: 'Employee',
    REMOTE_EMPLOYEE: 'Remote employee',
    ACCOUNTANT: 'Accountant',
    MANAGER: 'Manager',
};

export function isActiveHref(url: string, href: string | undefined): boolean {
    if (!href) {
        return false;
    }

    return url === href || url.startsWith(`${href}/`) || url.startsWith(`${href}?`);
}

/** Does this row CLAIM the current URL? `activePrefix` widens the claim — see NavItem. */
export function isActiveItem(url: string, item: NavItem): boolean {
    return isActiveHref(url, item.activePrefix ?? item.href);
}

/**
 * The ONE row a URL belongs to: of every row that claims it, the most specific claim wins.
 *
 * `isActiveItem` answers "does this row claim this URL", and more than one row legitimately
 * can. `/admin/tasks/calendar` is claimed by the Tasks row (whose `activePrefix` is
 * `/admin/tasks`, decision C-3) and by the Calendar row that points straight at it;
 * `/admin/my-tasks?bucket=overdue` is claimed by both My Tasks and Overdue. Lighting both
 * would be a sidebar saying you are in two places, so the longer pattern — the one that
 * describes more of the URL — takes the row.
 *
 * This is the rule `matchNavItem()` in `lib/breadcrumb.ts` already used for the breadcrumb;
 * it is here so the sidebar and the breadcrumb cannot end up naming different rows.
 *
 * Rows without an `href` are the unbuilt ones and never match.
 */
export function activeItem(groups: NavGroup[], url: string): NavItem | null {
    let best: NavItem | null = null;
    let bestLength = -1;

    for (const group of groups) {
        for (const item of group.items) {
            if (!isActiveItem(url, item)) {
                continue;
            }

            // The matched PATTERN's length, not the href's: a row with an `activePrefix` makes
            // the wider claim and must lose to a row that named the exact page.
            const length = (item.activePrefix ?? item.href ?? '').length;

            if (length > bestLength) {
                best = item;
                bestLength = length;
            }
        }
    }

    return best;
}

/**
 * The label of the group that is pinned above the first group heading, with a separator
 * under it rather than a heading of its own (Decision 0.5-3, T4 §4). It is matched on the
 * nav data's own label so the data files stay untouched; a role without that group simply
 * has nothing pinned.
 */
export const PINNED_GROUP_LABEL = 'My work';

export function isPinnedGroup(group: NavGroup): boolean {
    return group.label.toLowerCase() === PINNED_GROUP_LABEL.toLowerCase();
}

/** An item is live once the nav data drops its `phase` key and gives it an `href`. */
export function isLiveItem(item: NavItem): boolean {
    return Boolean(item.href) && item.phase === undefined;
}

/**
 * The split behind the `Coming soon` disclosure, done at render time: the groups keep
 * only what is built, and a group left with nothing is dropped rather than rendered as an
 * empty disclosure. Phase 12's `Users & Roles` rejoins `Admin` the day it loses its
 * `phase` key — no change here.
 */
export function liveGroups(groups: NavGroup[]): NavGroup[] {
    return groups
        .map((group) => ({ label: group.label, items: group.items.filter(isLiveItem) }))
        .filter((group) => group.items.length > 0);
}

/** Every unbuilt row, in the order the nav data lists it. */
export function comingSoonItems(groups: NavGroup[]): NavItem[] {
    return groups.flatMap((group) => group.items.filter((item) => !isLiveItem(item)));
}

/**
 * Does the active row live in this group? It takes the already-resolved row rather than the
 * URL, so a group cannot report itself active because one of its rows made a claim that
 * another group's row then won — see activeItem().
 */
export function groupHasActive(group: NavGroup, active: NavItem | null): boolean {
    return active !== null && group.items.includes(active);
}
