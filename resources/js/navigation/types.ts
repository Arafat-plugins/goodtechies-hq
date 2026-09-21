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

export function groupHasActive(group: NavGroup, url: string): boolean {
    return group.items.some((item) => isActiveHref(url, item.href));
}
