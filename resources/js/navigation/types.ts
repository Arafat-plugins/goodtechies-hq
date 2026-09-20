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
