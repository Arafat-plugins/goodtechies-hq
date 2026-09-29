import { router } from '@inertiajs/vue3';
import type { MaybeRefOrGetter } from 'vue';
import type { LiveRefreshHandle, LiveRefreshOptions } from '@/Components/Realtime/live';
import { overlayOpen, SHELL_POLL_MS, useLiveRefresh } from '@/Components/Realtime/live';
import { backoff } from '@/lib/net';
import { isSessionLive } from '@/lib/session';
import { hasNewVersion } from '@/lib/version';

/**
 * One Inertia partial reload for however many live screens asked for one.
 *
 * ## Why this is not just `router.reload({ only: [...] })` at each call site
 *
 * A partial reload runs the current page's controller in full and then throws away every prop
 * that is not in `only:` — Inertia filters the props, it does not know how to build fewer of
 * them. Measured on the seeded database: `/admin/dashboard` is 67 statements whether the answer
 * is thirteen props or one. So the cost of a live refresh is the cost of the SCREEN, and two
 * timers that both fire on the same second cost that screen twice for one answer.
 *
 * On a dashboard there are two: the shell's 30-second poll for the announcement banner and the
 * Messages indicator, and the page's own 60-second poll for its counters. Every other tick they
 * coincide exactly, because both intervals start in the same mount tick and one is a multiple of
 * the other. Coalescing turns that into one request carrying both prop sets.
 *
 * ## What it guarantees
 *
 * - **One request per batch.** Callers in the same tick are merged; `only:` is the union.
 * - **One request in flight.** A tick that arrives while an answer is still coming is folded
 *   into the next batch instead of stacking up behind it, and the names it asked for are not
 *   lost — the same rule `notifications.ts` holds for the bell's fetch.
 * - **Nothing is refreshed that nobody asked for.** An empty name set does nothing at all,
 *   rather than falling through to a full reload, because `router.reload()` with no `only:`
 *   re-sends every prop on the page and would replace props a screen is holding local state
 *   against.
 *
 * ## It cannot interrupt what somebody is doing
 *
 * `router.reload()` forces `async: true` (and `preserveState`, and `preserveScroll`), so it does
 * not join the queue that ordinary visits use and cannot cancel a form submit that is in flight.
 * That matters now that this runs on every screen in the application and not only on Messages:
 * a background refresh that could cancel a `POST` would lose somebody's work about once a week
 * and be unreproducible.
 */

/** The prop names the next request should ask for. */
const wanted = new Set<string>();

let scheduled = false;
let inFlight = false;

/**
 * Reliability slice 2a: the batch's backoff. Every live screen's reads go out through this one
 * request, so this is the one gate they share. Healthy, it never holds anything back and each
 * screen keeps its own interval exactly. After a failed batch (no answer, a 5xx, or the server's
 * `{reason: "error"}`), the next one waits twice the shell's interval — the one poll every page
 * runs — then twice that, up to five minutes. A tick inside the window leaves its names in
 * `wanted` for the first tick after it; the browser coming back online opens the gate at once.
 */
const gate = backoff(SHELL_POLL_MS);

function send(): void {
    scheduled = false;

    if (inFlight || wanted.size === 0) {
        // Still waiting on the last answer: whatever is in `wanted` stays there and goes out
        // with the batch `onFinish` schedules.
        return;
    }

    // Signed out (or moved to another surface): nothing goes out. `wanted` keeps its names, and
    // the pollers' own ticks re-ask once the session is back.
    if (!isSessionLive()) {
        return;
    }

    // A new deploy (reliability slice 3): the names wait, and "Reload now" answers all of them.
    if (hasNewVersion()) {
        return;
    }

    // Backed off, or the browser is offline: the names wait for the next tick past the gate.
    if (!gate.ready()) {
        return;
    }

    const only = [...wanted];
    wanted.clear();
    inFlight = true;

    const sentAt = Date.now();
    let failed = false;

    // `preserveState` and `preserveScroll` are not passed because `router.reload()` forces both
    // to true itself (`doReload`), and Inertia's own types refuse them here to say so. They are
    // the reason this is a `reload` and not a `visit`.
    router.reload({
        only,
        onSuccess: () => gate.succeed(),
        onNetworkError: () => {
            failed = true;
        },
        onHttpException: (response) => {
            // A 401 / 419 / surface 403 is the session module's; anything else here is a
            // failed read (`app.ts` keeps it silent and off the page).
            if (response.status >= 500 || response.status === 404 || response.status === 403 || response.status === 429) {
                failed = true;
            }
        },
        onFinish: () => {
            inFlight = false;

            if (failed) {
                gate.fail(sentAt);
            }

            // Anything that asked while this one was out goes now.
            if (wanted.size > 0) {
                schedule();
            }
        },
    });
}

function schedule(): void {
    if (scheduled) {
        return;
    }

    scheduled = true;

    // A macrotask rather than a microtask: two `setInterval` callbacks that fire on the same
    // second are separate tasks, and a microtask would run between them and miss the second.
    setTimeout(send, 0);
}

/**
 * Ask for these props to be re-read from the server, coalesced with anything else asking.
 *
 * The names are Inertia prop names on whatever page is currently mounted. A caller passing a
 * name the current page does not have gets nothing for it, which is the right answer — a screen
 * unmounted between the tick and the send should not resurrect its props.
 */
export function liveReload(names: readonly string[]): void {
    if (names.length === 0) {
        return;
    }

    for (const name of names) {
        wanted.add(name);
    }

    schedule();
}

/**
 * Is a coalesced reload out or queued right now? Only for a screen that wants to say so — the
 * board's little "checking" mark. Nothing branches on it.
 */
export function liveReloadBusy(): boolean {
    return inFlight || scheduled;
}

/* ------------------------------------------------- the three lines every screen costs */

/**
 * Keep a screen's own Inertia props current — the whole of what §A.4 promised each screen would
 * cost.
 *
 * It is `useLiveRefresh` wired to `liveReload`, and it exists because **seven screens wanted the
 * identical three lines** and the third of them was the one that would be forgotten:
 * `canRefresh: () => !overlayOpen()`. A dashboard whose counters re-read while its reader has a
 * menu open is rule 5 broken by omission rather than by design, so the guard is the default here
 * and a screen that knows more about its own gestures ANDs its own condition into it.
 *
 * `channel` is `null` by default, which means poll-only: five of the seven screens have no
 * channel to subscribe to and saying so once is better than five screens each passing `null`.
 * Task detail passes `task.{id}` and is therefore instant on a socket build.
 *
 * Returns the handle, so a screen with a gesture can `resume()` when the gesture ends and render
 * `pending` while it is held.
 */
export function useLiveProps(
    names: readonly string[],
    options: LiveRefreshOptions & { channel?: MaybeRefOrGetter<string | null> } = {},
): LiveRefreshHandle {
    const { channel = null, canRefresh, ...rest } = options;

    return useLiveRefresh(channel, () => liveReload(names), {
        ...rest,
        canRefresh: () => !overlayOpen() && (canRefresh?.() ?? true),
    });
}
