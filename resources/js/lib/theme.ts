import { ref } from 'vue';
import type { Ref } from 'vue';

/**
 * The viewer's colour scheme — one of three states, stored per browser.
 *
 * `system` is the default and follows `prefers-color-scheme` live, so a viewer whose OS
 * flips at sunset flips with it without touching the menu. The class this module toggles
 * on `<html>` is the same one the inline script in `resources/views/app.blade.php` sets
 * before first paint; that script is what keeps a reload from flashing the wrong theme,
 * and this module is only what happens afterwards, while the SPA is alive.
 *
 * Storage is wrapped the same way `sidebarState.ts` wraps it: private mode throws on
 * write, blocked cookies throw on read, and neither is a reason for the shell to break —
 * the viewer simply gets `system` on every load.
 */

export type ThemeMode = 'light' | 'dark' | 'system';

/** `hq.theme` — the key the blade script reads before the bundle exists. */
export const THEME_KEY = 'hq.theme';

/** Menu order: the two explicit choices, then the one that defers to the OS. */
export const THEME_MODES: readonly ThemeMode[] = ['light', 'dark', 'system'] as const;

export function isThemeMode(value: unknown): value is ThemeMode {
    return value === 'light' || value === 'dark' || value === 'system';
}

function readStored(): ThemeMode {
    if (typeof window === 'undefined') {
        return 'system';
    }

    try {
        const raw = window.localStorage.getItem(THEME_KEY);

        return isThemeMode(raw) ? raw : 'system';
    } catch {
        return 'system';
    }
}

function writeStored(mode: ThemeMode): void {
    if (typeof window === 'undefined') {
        return;
    }

    try {
        window.localStorage.setItem(THEME_KEY, mode);
    } catch {
        /* Private mode: the choice just does not survive this session. */
    }
}

function mediaQuery(): MediaQueryList | null {
    if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') {
        return null;
    }

    return window.matchMedia('(prefers-color-scheme: dark)');
}

/** Pure: does this mode mean a dark document, given what the OS currently asks for? */
export function resolveDark(mode: ThemeMode, systemDark: boolean): boolean {
    return mode === 'dark' || (mode === 'system' && systemDark);
}

/** The single place the `dark` class is written, after first paint. */
export function applyTheme(mode: ThemeMode): void {
    if (typeof document === 'undefined') {
        return;
    }

    document.documentElement.classList.toggle('dark', resolveDark(mode, mediaQuery()?.matches ?? false));
}

/**
 * One module-level ref, shared by every component that shows or sets the theme, so the
 * menu's radio state and the document agree without a store.
 */
const mode: Ref<ThemeMode> = ref('system');
let loaded = false;
let watching = false;

/** Follow the OS while the mode is `system`; registered once, for the life of the tab. */
function watchSystem(): void {
    const query = mediaQuery();

    if (watching || !query) {
        return;
    }

    watching = true;
    query.addEventListener('change', () => {
        if (mode.value === 'system') {
            applyTheme('system');
        }
    });
}

export function useTheme(): Ref<ThemeMode> {
    if (!loaded && typeof window !== 'undefined') {
        loaded = true;
        mode.value = readStored();
        // The blade script already applied this; re-applying costs nothing and keeps the
        // document right in any context where that script did not run.
        applyTheme(mode.value);
        watchSystem();
    }

    return mode;
}

export function setTheme(next: ThemeMode): void {
    mode.value = next;
    writeStored(next);
    applyTheme(next);
}
