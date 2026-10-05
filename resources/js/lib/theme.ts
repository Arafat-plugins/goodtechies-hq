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
 *
 * Since 2026-10-05 the choice is also kept on the account (`users.theme`, printed by
 * `app.blade.php` as `<html data-theme>`), and the account wins over this browser's copy.
 */

export type ThemeMode = 'light' | 'dark' | 'system';

/** `hq.theme` — the key the blade script reads before the bundle exists. */
export const THEME_KEY = 'hq.theme';

/** Menu order: the two explicit choices, then the one that defers to the OS. */
export const THEME_MODES: readonly ThemeMode[] = ['light', 'dark', 'system'] as const;

export function isThemeMode(value: unknown): value is ThemeMode {
    return value === 'light' || value === 'dark' || value === 'system';
}

/**
 * The account's choice, as `app.blade.php` printed it on `<html data-theme>` (2026-10-05), or
 * `null` when the person has never chosen. It is read from the document rather than from the
 * Inertia props so the very first paint and this module agree without waiting for a page.
 */
function readAccount(): ThemeMode | null {
    if (typeof document === 'undefined') {
        return null;
    }

    const raw = document.documentElement.getAttribute('data-theme');

    return isThemeMode(raw) ? raw : null;
}

function readLocal(): ThemeMode | null {
    try {
        const raw = window.localStorage.getItem(THEME_KEY);

        return isThemeMode(raw) ? raw : null;
    } catch {
        return null;
    }
}

/**
 * The account first, then this browser. The account is what makes the Android app's two
 * browsers (Chrome and its WebView backup screen, each with its own storage) and every other
 * device open in the same theme — the client saw the app open dark, then light, then dark.
 */
function readStored(): ThemeMode {
    if (typeof window === 'undefined') {
        return 'system';
    }

    const account = readAccount();

    if (account !== null) {
        writeLocal(account);

        return account;
    }

    return readLocal() ?? 'system';
}

function writeLocal(mode: ThemeMode): void {
    if (typeof window === 'undefined') {
        return;
    }

    try {
        window.localStorage.setItem(THEME_KEY, mode);
    } catch {
        /* Private mode: the choice just does not survive this session. */
    }
}

function csrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * Saves the choice on the account (`PUT /profile/theme`). Best effort: a failure leaves this
 * browser's own copy, which is what the app did before, and the next choice tries again. Only
 * the signed-in shell's user menu calls this, so there is always an account to save to.
 */
function saveToAccount(mode: ThemeMode): void {
    if (typeof window === 'undefined' || typeof fetch !== 'function') {
        return;
    }

    void fetch('/profile/theme', {
        method: 'PUT',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify({ theme: mode }),
    })
        .then((response) => {
            if (response.ok) {
                document.documentElement.setAttribute('data-theme', mode);
            }
        })
        .catch(() => {
            /* Offline: this browser keeps it; the account catches up on the next choice. */
        });
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

        // A choice made before the account kept one (only in this browser) moves onto the
        // account once, so the person's other browsers and devices pick it up.
        if (readAccount() === null && mode.value !== 'system') {
            saveToAccount(mode.value);
        }
    }

    return mode;
}

export function setTheme(next: ThemeMode): void {
    mode.value = next;
    writeLocal(next);
    applyTheme(next);
    saveToAccount(next);
}
