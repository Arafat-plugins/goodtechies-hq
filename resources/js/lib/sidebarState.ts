import { ref } from 'vue';
import type { Ref } from 'vue';

/**
 * What the sidebar remembers about this one viewer.
 *
 * Two things, both personal and both meaningless to anyone else: which nav groups they
 * keep open, and whether they run the sidebar as a rail. They live in `localStorage`
 * under `hq.nav.*`, next to the table preferences in `tableState.ts`.
 *
 * Every call is wrapped: Safari's private mode throws on write, a blocked-cookies
 * setting throws on read, and neither is a reason for the shell to break — a viewer
 * who cannot store simply gets the defaults on every load.
 */

/** `hq.nav.rail` — `'1'` when the sidebar is collapsed to the icon rail. */
const RAIL_KEY = 'hq.nav.rail';

function read(key: string): string | null {
    if (typeof window === 'undefined') {
        return null;
    }

    try {
        return window.localStorage.getItem(key);
    } catch {
        return null;
    }
}

function write(key: string, value: string): void {
    if (typeof window === 'undefined') {
        return;
    }

    try {
        window.localStorage.setItem(key, value);
    } catch {
        /* Private mode: the preference just does not survive this session. */
    }
}

/** `My work` → `my-work`, so a group label is safe to put in a storage key. */
export function navSlug(label: string): string {
    return label
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-|-$/g, '');
}

/** `hq.nav.<role>.<group>` — one key per role per group, per the T4 spec. */
export function groupStateKey(role: string, groupLabel: string): string {
    return `hq.nav.${navSlug(role) || 'guest'}.${navSlug(groupLabel)}`;
}

/** The stored open/closed state of one group, or `null` when the viewer never chose. */
export function readGroupOpen(role: string, groupLabel: string): boolean | null {
    const raw = read(groupStateKey(role, groupLabel));

    if (raw === '1') {
        return true;
    }

    if (raw === '0') {
        return false;
    }

    return null;
}

export function writeGroupOpen(role: string, groupLabel: string, open: boolean): void {
    write(groupStateKey(role, groupLabel), open ? '1' : '0');
}

/**
 * The rail flag, shared by every component that needs the sidebar's width — the sidebar
 * itself and the three layouts, which pad their content by it. One module-level ref, so
 * the layout re-renders the moment the toggle is pressed; `localStorage` is read once,
 * lazily, on the first use in the browser.
 */
const rail: Ref<boolean> = ref(false);
let loaded = false;

export function useSidebarRail(): Ref<boolean> {
    if (!loaded && typeof window !== 'undefined') {
        loaded = true;
        rail.value = read(RAIL_KEY) === '1';
    }

    return rail;
}

export function setSidebarRail(collapsed: boolean): void {
    rail.value = collapsed;
    write(RAIL_KEY, collapsed ? '1' : '0');
}
