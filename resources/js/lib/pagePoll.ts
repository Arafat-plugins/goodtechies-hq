import { router } from '@inertiajs/vue3';
import { onScopeDispose, watch } from 'vue';
import { overlayOpen } from '@/Components/Realtime/live';
import { backoff, onReconnect } from '@/lib/net';
import { isSessionLive, sessionState } from '@/lib/session';
import { hasNewVersion } from '@/lib/version';

/**
 * Part 0.5's refresh rule, in one place.
 *
 * A screen is one Inertia response, so any list, queue, badge or count that *somebody else's*
 * action changes is wrong from the moment it is painted and says nothing about it — an Admin
 * with the leave queue open reads "Nothing waiting" while the request sits in the database.
 * Until that surface has a Reverb channel (Phase 6), the screen asks.
 *
 * This is the notification bell's behaviour (`Components/Notifications/notifications.ts`,
 * decision 2-39) lifted out of the bell, because the bell was about to be copied into six pages
 * and a copied interval is six places for the same four rules to drift:
 *
 * - **One interval, ever.** The state is module-scoped and reference-counted, so two components
 *   asking for the same page — or a component remounted by a layout change — cannot leave a
 *   second interval running behind them. Two callers on one page are merged into one request
 *   whose `only` is the union of what they asked for, not two requests racing.
 * - **Nobody looking, no request.** Cleared on `visibilitychange`, re-armed with one immediate
 *   read when the page comes back. A laptop lid closed on a Friday costs nothing until Monday.
 * - **One request in flight.** A slow answer does not stack up behind the next tick.
 * - **No request after the last unmount.** The listener goes with the interval.
 *
 * ## Why it never disturbs the reader
 *
 * `preserveState` keeps the component instances, so an open dialog stays open, a half-typed
 * form keeps its text and a filter keeps its value; `preserveScroll` keeps the scroll position;
 * `only` fetches nothing but the named props, so the controller is not asked to rebuild the
 * page. A refresh that cannot be noticed except by the number changing is the requirement, not
 * a nicety — a queue that jumps under the cursor is worse than a stale one.
 *
 * ## When Phase 6 lands
 *
 * Each caller is deleted as its channel arrives, and this file goes with the last one. A poll
 * left running beside its own channel is two mechanisms doing one job, which nobody notices
 * until the day they disagree.
 *
 * @example
 * // Admin → Leave: the queue and the tab counts, nothing else.
 * usePagePoll(['requests', 'counts']);
 */

const DEFAULT_EVERY_MS = 20_000;

/** Each live caller's props, by the token handed back when it registered. */
const callers = new Map<symbol, readonly string[]>();

let timer: ReturnType<typeof setInterval> | null = null;
let listening = false;
let inFlight = false;
let everyMs = DEFAULT_EVERY_MS;

function pageVisible(): boolean {
    return typeof document === 'undefined' || document.visibilityState === 'visible';
}

/** The union of every caller's props, so one request serves all of them. */
function wanted(): string[] {
    const keys = new Set<string>();

    for (const only of callers.values()) {
        for (const key of only) {
            keys.add(key);
        }
    }

    return [...keys];
}

/**
 * Reliability slice 2a: healthy, every tick reads; after a failed read the next waits two ticks,
 * then four, up to five minutes, and the browser coming back online reads at once.
 */
let gate = backoff(everyMs);

/**
 * Reliability slice 3: a read that an open overlay refused, waiting for it to close.
 *
 * `reload.ts` screens get this from `useLiveProps` (`canRefresh: () => !overlayOpen()`, remembered
 * and delivered after); this is the same rule for the `usePagePoll` screens. The Admin leave queue
 * is the one that needed it: a decision dialog open over a row, and a poll swapping that row out
 * from under the Approve button. The refused read is re-asked the moment the overlay closes rather
 * than twenty seconds later, so the queue is never staler for having waited.
 */
const OVERLAY_RECHECK_MS = 500;
let overlayWait: ReturnType<typeof setTimeout> | null = null;

function waitForOverlay(): void {
    if (overlayWait !== null) {
        return;
    }

    overlayWait = setTimeout(() => {
        overlayWait = null;

        if (callers.size === 0) {
            return;
        }

        if (overlayOpen()) {
            waitForOverlay();

            return;
        }

        read();
    }, OVERLAY_RECHECK_MS);
}

function stopOverlayWait(): void {
    if (overlayWait !== null) {
        clearTimeout(overlayWait);
        overlayWait = null;
    }
}

function read(): void {
    // `hasNewVersion()`: a deploy happened (reliability slice 3) — every read would meet the same
    // 409, and the shell is already offering "Reload now".
    if (inFlight || callers.size === 0 || !pageVisible() || !isSessionLive() || hasNewVersion() || !gate.ready()) {
        return;
    }

    if (overlayOpen()) {
        waitForOverlay();

        return;
    }

    const only = wanted();

    if (only.length === 0) {
        return;
    }

    inFlight = true;

    const sentAt = Date.now();
    let failed = false;

    // A reload always preserves scroll and state: Inertia 3.7 forces both and its ReloadOptions omits them.
    router.reload({
        only,
        onSuccess: () => gate.succeed(),
        onNetworkError: () => {
            failed = true;
        },
        onHttpException: (response) => {
            if (response.status >= 500 || response.status === 404 || response.status === 403 || response.status === 429) {
                failed = true;
            }
        },
        onFinish: () => {
            inFlight = false;

            if (failed) {
                gate.fail(sentAt);
            }
        },
    });
}

// The network is back: one read now, while anybody is still asking.
onReconnect(() => {
    if (timer !== null) {
        read();
    }
});

function startPolling(): void {
    if (timer !== null || callers.size === 0 || !pageVisible() || !isSessionLive()) {
        return;
    }

    timer = setInterval(read, everyMs);
}

// Signed out, or moved to another surface: the shared interval stops. Signed back in: one
// immediate read, then the interval again (only if anybody is still asking).
watch(
    () => sessionState.status,
    (status) => {
        if (status !== 'ok') {
            stopPolling();

            return;
        }

        read();
        startPolling();
    },
);

function stopPolling(): void {
    if (timer !== null) {
        clearInterval(timer);
        timer = null;
    }
}

function onVisibilityChange(): void {
    if (!pageVisible()) {
        stopPolling();
        stopOverlayWait();

        return;
    }

    // Back in front: answer with what is true now, not in twenty seconds.
    read();
    startPolling();
}

/**
 * Keep the named props current for as long as this scope is alive.
 *
 * @param only    The Inertia props this screen needs refreshed. Name the ones another person
 *                changes and nothing else — never the whole page.
 * @param options `everyMs` is for a surface that genuinely reads differently (a roster somebody
 *                watches during a stand-up, say). It applies to the shared interval, so the
 *                shortest live request wins; leave it alone unless there is a reason.
 */
export function usePagePoll(only: readonly string[], options: { everyMs?: number } = {}): void {
    if (typeof window === 'undefined') {
        return;
    }

    const token = Symbol('page-poll');

    callers.set(token, [...only]);

    if (options.everyMs !== undefined && options.everyMs < everyMs) {
        everyMs = options.everyMs;
        gate = backoff(everyMs);
        stopPolling();
    }

    if (!listening && typeof document !== 'undefined') {
        document.addEventListener('visibilitychange', onVisibilityChange);
        listening = true;
    }

    startPolling();

    onScopeDispose(() => {
        callers.delete(token);

        if (callers.size > 0) {
            return;
        }

        stopPolling();
        stopOverlayWait();
        everyMs = DEFAULT_EVERY_MS;
        gate = backoff(everyMs);

        if (listening && typeof document !== 'undefined') {
            document.removeEventListener('visibilitychange', onVisibilityChange);
            listening = false;
        }
    });
}
