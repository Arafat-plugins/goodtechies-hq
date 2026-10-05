import { router } from '@inertiajs/vue3';

/**
 * Polish 018: is the page REALLY being left?
 *
 * Two `beforeunload` guards raise the browser's "Leave site?" box: unsaved input
 * (`lib/unsavedGuard.ts`) and the clock/timer guard (`Timer/TaskTimerPulse.vue`). The browser
 * fires `beforeunload` for things that are not leaving goodERP at all, and the box appeared for
 * each of them:
 *
 * 1. **A download.** Clicking a file whose answer is `Content-Disposition: attachment` (a message
 *    attachment, a task file, an APK) fires `beforeunload` first; the page then stays where it is.
 * 2. **A move inside the app turned into a full load.** After a deploy, Inertia answers a click on
 *    Dashboard with 409 + `X-Inertia-Location` and assigns `window.location` — a real unload in
 *    the middle of an ordinary in-app click (the person already passed the in-app check for
 *    unsaved input, so asking again is a second, browser-worded prompt for the same click).
 * 3. **A plain same-origin link** (`<a href="/…">` that is not an Inertia `<Link>`): the person is
 *    still in goodERP, so "you are leaving without clocking out" is not true.
 *
 * This module watches clicks and Inertia visits once, and answers two questions for the guards.
 */

const DOWNLOAD_GRACE_MS = 3000;
const VISIT_GRACE_MS = 30_000;

let downloadAt = 0;
let sameOriginLinkAt = 0;
let visitStartedAt = 0;
let installed = false;

function looksLikeDownload(anchor: HTMLAnchorElement, url: URL): boolean {
    if (anchor.hasAttribute('download')) {
        return true;
    }

    // goodERP's own download routes: `GET /files/{file}` (every message attachment and record
    // file, signed) and `GET /payslip/{item}/pdf`. Not the `/…/files` LIST pages.
    return /^\/files\/\d+$|^\/payslip\/\d+\/pdf$/.test(url.pathname);
}

function onClick(event: MouseEvent): void {
    if (event.defaultPrevented || event.button !== 0) {
        return;
    }

    const anchor = (event.target as Element | null)?.closest?.('a[href]') as HTMLAnchorElement | null;

    if (anchor === null) {
        return;
    }

    let url: URL;

    try {
        url = new URL(anchor.href, window.location.href);
    } catch {
        return;
    }

    if (looksLikeDownload(anchor, url)) {
        downloadAt = Date.now();

        return;
    }

    const newTab = anchor.target === '_blank' || event.ctrlKey || event.metaKey || event.shiftKey;

    if (!newTab && url.origin === window.location.origin) {
        sameOriginLinkAt = Date.now();
    }
}

export function installLeaveIntent(): void {
    if (installed || typeof window === 'undefined') {
        return;
    }

    installed = true;
    // Capture phase, so it runs before any handler that stops the click.
    document.addEventListener('click', onClick, true);

    router.on('start', (event) => {
        const visit = event.detail.visit as { async?: boolean; prefetch?: boolean };

        if (!visit.async && !visit.prefetch) {
            visitStartedAt = Date.now();
        }
    });
    // Cleared by every ending that keeps this page — but NOT by `finish`: a 409 location visit
    // assigns `window.location` with no `success`, and that unload is the one to recognise.
    const settled = (): void => {
        visitStartedAt = 0;
    };

    router.on('success', settled);
    router.on('error', settled);
    router.on('cancel', settled);
    router.on('httpException', settled);
    router.on('networkError', settled);
}

/** A download was just clicked: the page is not going anywhere. */
export function isDownloading(): boolean {
    return Date.now() - downloadAt < DOWNLOAD_GRACE_MS;
}

/**
 * This unload is the person's own in-app navigation (an Inertia click that became a full load,
 * or a plain link to another goodERP page) — they are moving inside goodERP, not leaving it.
 */
export function isInAppNavigation(): boolean {
    const now = Date.now();

    return (visitStartedAt > 0 && now - visitStartedAt < VISIT_GRACE_MS) || now - sameOriginLinkAt < DOWNLOAD_GRACE_MS;
}

/** An Inertia visit the person started is in flight (its own in-app unsaved check already ran). */
export function isInertiaVisitInFlight(): boolean {
    return visitStartedAt > 0 && Date.now() - visitStartedAt < VISIT_GRACE_MS;
}
