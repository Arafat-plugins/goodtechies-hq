import { router } from '@inertiajs/vue3';
import { onScopeDispose } from 'vue';

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

function read(): void {
    if (inFlight || callers.size === 0 || !pageVisible()) {
        return;
    }

    const only = wanted();

    if (only.length === 0) {
        return;
    }

    inFlight = true;

    router.reload({
        only,
        preserveScroll: true,
        preserveState: true,
        onFinish: () => {
            inFlight = false;
        },
    });
}

function startPolling(): void {
    if (timer !== null || callers.size === 0 || !pageVisible()) {
        return;
    }

    timer = setInterval(read, everyMs);
}

function stopPolling(): void {
    if (timer !== null) {
        clearInterval(timer);
        timer = null;
    }
}

function onVisibilityChange(): void {
    if (!pageVisible()) {
        stopPolling();

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
        everyMs = DEFAULT_EVERY_MS;

        if (listening && typeof document !== 'undefined') {
            document.removeEventListener('visibilitychange', onVisibilityChange);
            listening = false;
        }
    });
}
