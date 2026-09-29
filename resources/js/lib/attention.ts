/**
 * Is somebody actually looking at this page?
 *
 * Reliability slice 5. Hidden tabs already stop polling (`Realtime/live.ts`), but a tab that is
 * visible behind another window kept reading — and a read of an open thread is what marks it
 * read. Every background read that could mark something read now says whether the window has
 * focus, and the server only moves the unread line when it does (`MessageController`).
 */

/** The request header the server reads. `1` = the window is focused and the tab visible. */
export const FOCUS_HEADER = 'X-HQ-Focused';

export function windowFocused(): boolean {
    if (typeof document === 'undefined') {
        return false;
    }

    return document.visibilityState === 'visible' && document.hasFocus();
}

/** The header, spelled once, for a fetch() or an Inertia `router.reload({ headers })`. */
export function focusHeaders(): Record<string, string> {
    return { [FOCUS_HEADER]: windowFocused() ? '1' : '0' };
}
