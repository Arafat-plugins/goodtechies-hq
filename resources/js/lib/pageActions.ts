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
