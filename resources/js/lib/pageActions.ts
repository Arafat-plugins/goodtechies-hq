import type { InjectionKey, ShallowRef } from 'vue';

/**
 * Polish 007 — "perfectly inline": a page's own actions (New client, Calendar / Balances, …)
 * sit at the end of the page's first toolbar row instead of on an empty row of their own.
 *
 * `PageShell` provides a slot for one host element; the first `PageActionsHost` that mounts
 * inside it claims the slot, and `PageShell` teleports its `#actions` there. With no host the
 * actions stay in `PageShell`'s own row, exactly as before.
 */
export interface PageActionsSlot {
    host: ShallowRef<HTMLElement | null>;
    claim: (el: HTMLElement) => boolean;
    release: (el: HTMLElement) => void;
}

export const PAGE_ACTIONS: InjectionKey<PageActionsSlot> = Symbol('page-actions');

/**
 * Polish 013: the same idea for a table's own controls (row density, Columns). The first
 * `DataTable` that asks for the host claimed by the page's `PageActionsHost` draws its view
 * options there — on the page's first row, just before the page actions — instead of on a row
 * of their own above the table.
 */
export interface TableToolsSlot {
    host: ShallowRef<HTMLElement | null>;
    /** Claimed by the toolbar row (PageActionsHost). */
    claimHost: (el: HTMLElement) => boolean;
    releaseHost: (el: HTMLElement) => void;
    /** Claimed by the one DataTable whose controls go there. */
    owner: ShallowRef<symbol | null>;
}

export const PAGE_TABLE_TOOLS: InjectionKey<TableToolsSlot> = Symbol('page-table-tools');
