import { reactive, readonly, ref } from 'vue';
import { fetchWithTimeout } from '@/lib/net';

/**
 * Is the person on this page still signed in, and still on this surface?
 *
 * Reliability slice 1. Every background reader — the shell poll, a screen's live props, the bell,
 * the chat thread, the timer's heartbeat, the task drawer — used to meet an ended session on its
 * own: the Inertia ones followed a redirect onto the login page mid-typing, the fetch() ones each
 * called it `failed` or `offline` and kept trying. The server now answers those requests with a
 * 401 (`reason: "session"`), a 419 (`reason: "csrf"`) or, when the role changed under an open
 * page, a 403 (`reason: "surface"`, plus `home`). This module is the one place those answers
 * land, and the one question every poller asks before it fires: `isSessionLive()`.
 *
 * `SessionEndedDialog.vue` draws the state; nothing else here touches the DOM.
 */

export type SessionStatus = 'ok' | 'ended' | 'surface';

interface SessionState {
    status: SessionStatus;
    /** The person's own dashboard, sent with a `surface` 403. */
    home: string | null;
}

const state = reactive<SessionState>({ status: 'ok', home: null });

export const sessionState = readonly(state);

/** Asked by every poller immediately before it schedules or sends. */
export function isSessionLive(): boolean {
    return state.status === 'ok';
}

const resumeListeners = new Set<() => void>();

/**
 * Called once each time the session comes back (a sign-in in another tab, detected on focus).
 * Returns the unsubscribe. The timer replays its buffered heartbeats from here.
 */
export function onSessionResume(listener: () => void): () => void {
    resumeListeners.add(listener);

    return () => resumeListeners.delete(listener);
}

export function markSessionEnded(): void {
    // A surface change is the more specific answer and stays: signing in again would not help.
    if (state.status === 'surface') {
        return;
    }

    state.status = 'ended';
}

export function markSurfaceChanged(home: string | null): void {
    state.status = 'surface';
    state.home = home;
}

export function markSessionLive(): void {
    if (state.status === 'ok') {
        return;
    }

    state.status = 'ok';
    state.home = null;

    for (const listener of [...resumeListeners]) {
        try {
            listener();
        } catch {
            // One listener failing must not keep the others from resuming.
        }
    }
}

function bodyOf(body: unknown): { reason?: unknown; home?: unknown } {
    if (typeof body === 'object' && body !== null) {
        return body as { reason?: unknown; home?: unknown };
    }

    if (typeof body === 'string' && body.trim().startsWith('{')) {
        try {
            return JSON.parse(body) as { reason?: unknown; home?: unknown };
        } catch {
            return {};
        }
    }

    return {};
}

/**
 * Classify a response status (and, for a 403, its body). Returns `true` when the answer was about
 * the session — the caller then stops treating it as its own failure and says nothing.
 *
 * Every other status is left to the caller, unchanged: 401 and 419 always mean the session, and a
 * 403 means it only when the body says `reason: "surface"` — an ordinary refusal stays a refusal.
 */
export function reportStatus(status: number, body?: unknown): boolean {
    if (status === 401 || status === 419) {
        markSessionEnded();

        return true;
    }

    if (status === 403) {
        const parsed = bodyOf(body);

        if (parsed.reason === 'surface') {
            markSurfaceChanged(typeof parsed.home === 'string' ? parsed.home : null);

            return true;
        }
    }

    return false;
}

/**
 * `reportStatus` for a fetch() `Response`. Reads a clone, so the caller can still read the body.
 */
export async function reportResponse(res: Response): Promise<boolean> {
    if (res.status === 403) {
        let body: unknown = undefined;

        try {
            body = await res.clone().json();
        } catch {
            body = undefined;
        }

        return reportStatus(res.status, body);
    }

    return reportStatus(res.status);
}

/**
 * Liveness: the bell's feed. Every role reaches it (NotificationPolicy::viewAny is true for all
 * five seeded roles), and only a 2xx counts — a plain 403 from it means "signed in but refused"
 * (2FA not enrolled, account deactivated), which is not a session this page can carry on in.
 */
const RECHECK_URL = '/notifications/recent';

/**
 * Identity: the feed carries no user id, so the second question goes to `/profile` — every
 * signed-in role has it — as an Inertia partial reload asking for the shared `auth` prop only.
 * Existing route, existing middleware, a few hundred bytes of JSON.
 */
const IDENTITY_URL = '/profile';
const IDENTITY_COMPONENT = 'Shared/Profile';

export type RecheckResult = 'live' | 'ended' | 'other-user';

/**
 * Reliability slice 3: one plain read of the recheck feed, for when every poller has stopped
 * (a new deploy — `lib/version.ts`). A 401/419 or a surface 403 lands in `reportResponse`, so the
 * session lock still closes over a page left open for hours after the deploy. Anything else,
 * including no answer, is ignored: this is a probe, not a poller with a failure to report.
 */
export async function probeSession(): Promise<void> {
    try {
        const res = await fetchWithTimeout(RECHECK_URL, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            cache: 'no-store',
        });

        if (!res.ok) {
            await reportResponse(res);
        }
    } catch {
        // Offline or unreachable: the next probe asks again.
    }
}

let rechecking: Promise<RecheckResult> | null = null;

async function signedInUserId(version: string | null): Promise<number | 'unknown' | 'version'> {
    const res = await fetchWithTimeout(IDENTITY_URL, {
        headers: {
            'X-Inertia': 'true',
            'X-Inertia-Version': version ?? '',
            'X-Inertia-Partial-Component': IDENTITY_COMPONENT,
            'X-Inertia-Partial-Data': 'auth',
            'X-Requested-With': 'XMLHttpRequest',
            Accept: 'text/html, application/xhtml+xml',
        },
        credentials: 'same-origin',
        cache: 'no-store',
    });

    // The build moved while the page was open: identity cannot be read off this response.
    if (res.status === 409) {
        return 'version';
    }

    // A redirect (to the 2FA enrolment page, say) or anything that is not this page's Inertia
    // answer says nothing about who is signed in.
    if (!res.ok || res.redirected || !res.headers.has('X-Inertia')) {
        return 'unknown';
    }

    try {
        const body = (await res.json()) as { component?: unknown; props?: { auth?: { user?: { id?: unknown } | null } } };
        const id = body.props?.auth?.user?.id;

        return body.component === IDENTITY_COMPONENT && typeof id === 'number' ? id : 'unknown';
    } catch {
        return 'unknown';
    }
}

/**
 * Ask the server whether this tab is signed in again, AS THE SAME PERSON.
 *
 * - `live`: the feed answered 2xx and `/profile` names `expectedUserId`. Every poller resumes
 *   and the timer replays.
 * - `other-user`: somebody else signed in in the other tab (or the build changed, so the answer
 *   cannot be trusted). Nothing resumes; the caller reloads the page, whose content then belongs
 *   to whoever the server says is signed in.
 * - `ended`: anything else — a 401/419, a plain 403, a network failure. The state stays.
 */
export function recheckSession(expectedUserId: number | null, version: string | null): Promise<RecheckResult> {
    if (state.status !== 'ended') {
        return Promise.resolve(state.status === 'ok' ? 'live' : 'ended');
    }

    if (rechecking !== null) {
        return rechecking;
    }

    rechecking = (async (): Promise<RecheckResult> => {
        try {
            const res = await fetchWithTimeout(RECHECK_URL, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                cache: 'no-store',
            });

            if (!res.ok) {
                // A surface 403 moves the state to `surface`; every other refusal leaves it.
                await reportResponse(res);

                return 'ended';
            }

            const who = await signedInUserId(version);

            if (who === 'unknown') {
                return 'ended';
            }

            if (who === 'version' || expectedUserId === null || who !== expectedUserId) {
                return 'other-user';
            }

            markSessionLive();

            return 'live';
        } catch {
            return 'ended';
        } finally {
            rechecking = null;
        }
    })();

    return rechecking;
}

/* ------------------------------------------------ a click that met an ended session */

/**
 * Bumped when a visit the PERSON started (a Save, a link — not a background reload) is refused
 * because the session has already ended. The dialog watches it and pulls focus back, so the
 * click is answered rather than silently swallowed.
 */
export const sessionAttention = ref(0);

/** Inertia visits the person started that are still in flight (not `async`, not `prefetch`). */
let userVisitsInFlight = 0;

export function trackVisitStart(visit: { async?: boolean; prefetch?: boolean }): void {
    if (!visit.async && !visit.prefetch) {
        userVisitsInFlight += 1;
    }
}

export function trackVisitFinish(visit: { async?: boolean; prefetch?: boolean }): void {
    if (!visit.async && !visit.prefetch) {
        userVisitsInFlight = Math.max(0, userVisitsInFlight - 1);
    }
}

/** Called for a 401/419 on an Inertia visit, before the state is updated. */
export function noteRefusedVisit(): void {
    if (state.status !== 'ok' && userVisitsInFlight > 0) {
        sessionAttention.value += 1;
    }
}
