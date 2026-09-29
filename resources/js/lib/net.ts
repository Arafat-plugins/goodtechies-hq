import { readonly, ref } from 'vue';

/**
 * Can this page reach goodERP right now?
 *
 * Reliability slice 2a. Every background reader used to meet a dropped connection or a failing
 * server on its own: the bell called it `failed`, the thread retried every ten seconds for ever,
 * the shell poll kept its thirty-second beat against a dead server, and the person was told
 * nothing. This module is the one place those failures land, and it answers three questions:
 *
 * - **`connectivity`** — `online`, `offline` (the browser says there is no network) or
 *   `unreachable` (the network is up but goodERP is not answering: two failures in a row, a
 *   network error, a timeout or a 5xx, from any reader). `ShellLive.vue` draws it as a strip.
 * - **`backoff(baseMs)`** — each poller's own gate. Healthy, it never gets in the way, so every
 *   interval stays exactly what it was. After a failure the next attempt waits twice as long,
 *   then twice that, up to five minutes; one success resets it.
 * - **`onReconnect(listener)`** — the browser saying it is back, or the first answer after goodERP
 *   was unreachable. Every poller answers with one immediate read instead of waiting out its
 *   backed-off delay (its gate lets exactly that one through). The browser's `online` also
 *   clears every gate's failures, because the network itself changed.
 *
 * `fetchWithTimeout` is the pollers' `fetch()`: twenty seconds, then it counts as a failure.
 *
 * Nothing here touches the DOM or the session: an ended session is `lib/session.ts`'s answer,
 * and a 401 / 419 is a reachable server.
 */

export type Connectivity = 'online' | 'offline' | 'unreachable';

/** Consecutive failures, from any reader, before the strip says goodERP cannot be reached. */
export const UNREACHABLE_AFTER = 2;

/** The longest a backed-off poller waits between attempts. */
export const BACKOFF_MAX_MS = 5 * 60_000;

/** How long a poller's request may take before it is given up on and counted as a failure. */
export const FETCH_TIMEOUT_MS = 20_000;

/**
 * A tick is allowed through when it is at most this early for its gate. Every poller keeps its
 * `setInterval`, and a tick lands a few milliseconds either side of its nominal time — without
 * this, a gate set for exactly two intervals out would miss the tick it was meant for and wait
 * a third.
 */
const TICK_SLACK_MS = 500;

function browserOnline(): boolean {
    return typeof navigator === 'undefined' || navigator.onLine !== false;
}

const state = ref<Connectivity>(browserOnline() ? 'online' : 'offline');

export const connectivity = readonly(state);

let streak = 0;

/**
 * Bumped on every reconnect — the browser's `online` event, or the first answer after goodERP
 * was unreachable. Each gate gets ONE free attempt per bump: the immediate retry.
 */
let epoch = 0;

/**
 * Bumped only by the browser's `online` event. The network itself changed, so every gate also
 * forgets its failures. The first answer after an outage does not reset anybody: a poller whose
 * own endpoint keeps failing keeps backing off even while the rest of the page is fine.
 */
let networkEpoch = 0;

const reconnectListeners = new Set<() => void>();

/**
 * Called once on each reconnect. Returns the unsubscribe. The poller does one immediate read —
 * its gate lets exactly that one through.
 */
export function onReconnect(listener: () => void): () => void {
    reconnectListeners.add(listener);

    return () => reconnectListeners.delete(listener);
}

function reconnected(): void {
    epoch += 1;

    for (const listener of [...reconnectListeners]) {
        try {
            listener();
        } catch {
            // One poller failing must not keep the others from retrying.
        }
    }
}

/** A request from this page failed: no answer, a timeout, or a 5xx. */
export function reportNetworkFailure(): void {
    streak += 1;

    // The browser saying "no network" is definite (only its "online" is a guess).
    if (!browserOnline()) {
        state.value = 'offline';

        return;
    }

    if (state.value === 'online' && streak >= UNREACHABLE_AFTER) {
        state.value = 'unreachable';
    }
}

/** A request from this page got an answer from the server (any status below 500). */
export function reportNetworkSuccess(): void {
    streak = 0;

    if (state.value === 'online' || !browserOnline()) {
        return;
    }

    state.value = 'online';
    reconnected();
}

if (typeof window !== 'undefined') {
    window.addEventListener('offline', () => {
        state.value = 'offline';
    });

    window.addEventListener('online', () => {
        // Optimistic: the network is back, so every poller asks once, now. If goodERP itself is
        // still down, two of those failing put the strip back to "unreachable".
        streak = 0;
        state.value = 'online';
        networkEpoch += 1;
        reconnected();
    });
}

/* ------------------------------------------------------------------ the gate */

export interface Backoff {
    /** Failures since the last success (0 when healthy). */
    readonly failures: number;
    /** The wait after the most recent attempt: `baseMs` when healthy, doubling per failure. */
    delay(): number;
    /**
     * May this poller send now? Always true when healthy; false while backed off or while the
     * browser is offline; true once, straight away, after a reconnect.
     */
    ready(now?: number): boolean;
    /** The attempt sent at `sentAt` failed. */
    fail(sentAt?: number): void;
    /** The attempt got an answer. */
    succeed(): void;
}

/**
 * One poller's backoff. `baseMs` is its healthy interval. The poller keeps its own
 * `setInterval` and asks `ready()` on every tick; a tick inside the backed-off window is skipped,
 * so the gaps between attempts go base × 2, × 4, × 8 … up to five minutes.
 */
export function backoff(baseMs: number): Backoff {
    let failures = 0;
    let openAt = 0;
    /** The reconnect whose free attempt this gate has already used. */
    let usedEpoch = epoch;
    let seenNetworkEpoch = networkEpoch;

    function current(): void {
        if (seenNetworkEpoch !== networkEpoch) {
            seenNetworkEpoch = networkEpoch;
            failures = 0;
            openAt = 0;
        }
    }

    function delay(): number {
        current();

        if (failures === 0) {
            return baseMs;
        }

        return Math.max(baseMs, Math.min(baseMs * 2 ** failures, BACKOFF_MAX_MS));
    }

    return {
        get failures() {
            current();

            return failures;
        },
        delay,
        ready(now = Date.now()) {
            current();

            if (!browserOnline()) {
                return false;
            }

            return usedEpoch !== epoch || now >= openAt - TICK_SLACK_MS;
        },
        fail(sentAt = Date.now()) {
            current();
            usedEpoch = epoch;
            failures += 1;
            openAt = sentAt + delay();
        },
        succeed() {
            current();
            usedEpoch = epoch;
            failures = 0;
            openAt = 0;
        },
    };
}

/* ------------------------------------------------------------------ fetch */

/**
 * `fetch()` with a twenty-second limit, reporting its outcome to the connectivity state: a
 * network error, a timeout or a 5xx is a failure, anything else is a success. It rejects exactly
 * as `fetch()` does (a timeout is a `TimeoutError` DOMException), so a caller's `catch` still
 * catches both. An abort the caller asked for through its own `signal` is not counted.
 */
export async function fetchWithTimeout(
    input: RequestInfo | URL,
    init: RequestInit = {},
    timeoutMs: number = FETCH_TIMEOUT_MS,
): Promise<Response> {
    const timeout = AbortSignal.timeout(timeoutMs);
    const signal = init.signal ? AbortSignal.any([init.signal, timeout]) : timeout;

    let response: Response;

    try {
        response = await fetch(input, { ...init, signal });
    } catch (error) {
        if (!init.signal?.aborted) {
            reportNetworkFailure();
        }

        throw error;
    }

    if (response.status >= 500) {
        reportNetworkFailure();
    } else {
        reportNetworkSuccess();
    }

    return response;
}

/* ------------------------------------------------ an upload that speaks for itself */

/** The one sentence for a 413, here and in `app.ts`'s global handler. */
export const TOO_LARGE_TEXT = 'That upload is too large for the server.';

/** Why an upload did not land, as far as the uploader needs to know. */
export type UploadFailure = 'network' | 'too_large';

/**
 * Reliability slice 2b: per-visit handlers for an upload that shows its own failure inline.
 *
 * `FilePanel` and the message composer keep the file (or the voice clip) on screen after a
 * failure and say so under it, with a *Try again* beside it. `app.ts`'s global `networkError` /
 * `httpException` listeners would also toast the same failure, so these return `false` for the
 * cases they handle — Inertia then skips its global event entirely (the per-visit callback runs
 * first) and the failure is told once. That is the opt-out: narrow, per visit, and only for no
 * answer, a 5xx and a 413. A 401 / 419 / 403 / 429 falls through to the global handler, so the
 * session dialog still speaks. Connectivity is still reported here, since the global listener
 * that would have reported it never runs.
 */
export function inlineUploadFailure(onFailure: (kind: UploadFailure) => void): {
    onNetworkError: () => false;
    onHttpException: (response: { status: number }) => false | void;
} {
    return {
        onNetworkError: () => {
            reportNetworkFailure();
            onFailure('network');

            return false;
        },
        onHttpException: (response) => {
            if (response.status === 413) {
                reportNetworkSuccess();
                onFailure('too_large');

                return false;
            }

            if (response.status >= 500) {
                reportNetworkFailure();
                onFailure('network');

                return false;
            }

            return undefined;
        },
    };
}

/* -------------------------------------------- which Inertia visit the person started */

export interface UserVisit {
    method: string;
    url: string;
    startedAt: number;
}

type VisitLike = { method: string; url: URL | string; async?: boolean; prefetch?: boolean };

/**
 * The Inertia visit the PERSON started (a click, a Save — not `async`, not `prefetch`) that is
 * still in flight. Inertia runs one such visit at a time: starting another cancels the first.
 * Inertia's global `networkError` / `httpException` events do not say which visit they are
 * about, so `app.ts` asks this.
 */
let userVisit: UserVisit | null = null;
let userVisitKey: object | null = null;

export function currentUserVisit(): UserVisit | null {
    return userVisit;
}

export function noteUserVisitStart(visit: VisitLike): UserVisit | null {
    if (visit.async || visit.prefetch) {
        return null;
    }

    userVisit = { method: visit.method.toLowerCase(), url: String(visit.url), startedAt: Date.now() };
    userVisitKey = visit;

    return userVisit;
}

/** A cancelled visit finishing after its replacement started must not clear the replacement. */
export function noteUserVisitFinish(visit: VisitLike): void {
    if (visit.async || visit.prefetch) {
        return;
    }

    if (userVisitKey === visit || (userVisit !== null && userVisit.url === String(visit.url) && userVisit.method === visit.method.toLowerCase())) {
        userVisit = null;
        userVisitKey = null;
    }
}

/** Is a failed request to `url` the person's own visit? Compared by path: the query may differ. */
export function isUserVisitUrl(url: string | undefined): boolean {
    if (userVisit === null) {
        return false;
    }

    if (url === undefined) {
        return true;
    }

    try {
        const base = typeof window === 'undefined' ? 'http://localhost' : window.location.href;

        return new URL(url, base).pathname === new URL(userVisit.url, base).pathname;
    } catch {
        return true;
    }
}
