import { nextTick } from 'vue';

/**
 * Where the keyboard goes when an overlay opened from a menu closes — decision 5-20, generalised.
 *
 * ## The bug, in one sentence
 *
 * reka's Dialog restores focus to whatever held it when the dialog opened. When the dialog was
 * opened from a `DropdownMenuItem`, that element is a menu item the `⋯` menu has **already
 * unmounted**, so the restore lands on `<body>` and a keyboard user is dropped at the top of the
 * document — mid-table, with every Tab they had spent to get there gone.
 *
 * It was fixed on `Admin/Holidays/Index.vue`, then again on `Shared/Finance/Income.vue` and
 * `Expenses.vue`, then met from the other direction on `Shared/Payroll/Show.vue` (decision 9-27,
 * where a transition deletes its own trigger). Three copies of a fix is a fix that is missing
 * somewhere, and it was: every other row menu in the application still had it.
 *
 * ## What this does instead
 *
 * It captures the element focus should come BACK to, at the moment the overlay is asked for and
 * while the menu is still open — and it captures the menu's **trigger**, not the menu item:
 *
 *   - While a dropdown menu is open its trigger carries `aria-expanded="true"` next to
 *     `aria-haspopup="menu"`, which reka writes. That is the `⋯` button, the one element in this
 *     story that is still on screen after the menu has gone. The last match in document order wins,
 *     so a submenu's trigger beats its parent's.
 *   - With no menu open, the active element IS the trigger — an ordinary button — so the same call
 *     covers a dialog opened from a plain control whose button the write then removes (the *Withdraw*
 *     on a leave request, the *Lock* on a payroll month).
 *
 * No accessible-name lookup, which is what the first three copies did (`button[aria-label^="Actions
 * for "]`, matched against a label the screen rebuilt): an element reference cannot be spelled
 * wrong, and it cannot pick the hidden one of the two triggers `DataTable` renders — the table's and
 * the stacked card list's — because the one that was open is the one that was on screen.
 *
 * ## And the other half: the open is deferred by a tick
 *
 * reka dismisses the menu on select and restores focus to the trigger as it goes. Opening a dialog
 * in the same tick races that, and the focus trap ends up holding an element the menu is about to
 * unmount. `setTimeout(…, 0)` lets the menu finish first. That half was already copied into nine
 * files; it is here so the pair travels together — deferring without restoring leaves the bug, and
 * restoring without deferring is a race.
 *
 * ## Using it
 *
 * ```ts
 * const menu = useMenuDialog('holidays-add');            // the fallback, when the row is gone
 * function askRemove(row: Row): void {
 *     menu.openFromMenu(() => (pending.value = row));    // from @select on the menu item
 * }
 * function close(): void {
 *     pending.value = null;
 *     menu.returnFocus();                                // however the overlay closed
 * }
 * ```
 *
 * `returnFocus()` is called on **every** way out — Cancel, Esc, and the write that succeeded — and
 * the last of those is the one that is easy to forget: a save that closes the dialog by *navigating*
 * runs no close handler at all, so the call belongs in `onFinish`/`onSuccess` as well.
 */

/** The element a fallback names: an id, a getter, or nothing. */
export type MenuFocusFallback = string | (() => HTMLElement | null | undefined) | null;

/**
 * The `<main>` every layout gives `id="main-content" tabindex="-1"`.
 *
 * The default fallback, and a real one: when the trigger is gone and the caller has named nothing
 * better, focus lands on the region the overlay was about rather than on `<body>`. It is the skip
 * link's target, so it is already a focus stop the shell maintains.
 */
const MAIN_CONTENT_ID = 'main-content';

/** Is this element still in the document AND on screen? */
function focusable(element: HTMLElement | null): element is HTMLElement {
    return element !== null
        && element.isConnected
        && (element.offsetParent !== null || element.getClientRects().length > 0);
}

/**
 * The trigger of the dropdown menu that is open right now.
 *
 * Read while the menu is still open, which is the only moment it can be read: `aria-expanded` goes
 * back to `false` the instant it closes.
 */
export function openMenuTrigger(): HTMLElement | null {
    const triggers = document.querySelectorAll<HTMLElement>('[aria-haspopup="menu"][aria-expanded="true"]');

    return triggers.length === 0 ? null : (triggers[triggers.length - 1] ?? null);
}

function resolve(fallback: MenuFocusFallback): HTMLElement | null {
    if (typeof fallback === 'function') {
        return fallback() ?? null;
    }

    if (typeof fallback === 'string') {
        return document.getElementById(fallback);
    }

    return null;
}

export interface MenuDialog {
    /**
     * Open an overlay from a menu item (or from any control the write might remove).
     *
     * Captures where focus has to come back to, then defers `open` by a tick so the menu can finish
     * dismissing before the dialog claims the focus trap.
     */
    openFromMenu: (open: () => void) => void;
    /** Put the keyboard back. Call it on every way the overlay closes. */
    returnFocus: () => void;
}

export function useMenuDialog(fallback: MenuFocusFallback = null): MenuDialog {
    let trigger: HTMLElement | null = null;

    return {
        openFromMenu(open: () => void): void {
            trigger = openMenuTrigger()
                ?? (document.activeElement instanceof HTMLElement ? document.activeElement : null);

            setTimeout(open, 0);
        },

        returnFocus(): void {
            const captured = trigger;

            trigger = null;

            void nextTick(() => {
                // The trigger first, then what the caller named, then the main region. Each step is
                // only taken when the one before it is gone or off screen — a `⋯` button whose row
                // the write deleted, a card list that is hidden at this width.
                const target = focusable(captured)
                    ? captured
                    : (resolve(fallback) ?? document.getElementById(MAIN_CONTENT_ID));

                target?.focus();
            });
        },
    };
}
