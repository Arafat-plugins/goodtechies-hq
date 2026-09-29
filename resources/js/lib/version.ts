import { readonly, ref } from 'vue';
import { isSessionLive, probeSession } from '@/lib/session';

/**
 * A deploy happened while this tab was open (reliability slice 3).
 *
 * A background read that carried the old asset version is answered by the server with a JSON 409
 * `{reason: "version"}` and no location (`HandleInertiaRequests::onVersionChange`). `app.ts` lands
 * it here. From then on every background poller stops asking — `reload.ts`, `pagePoll.ts` and
 * `live.ts` read `hasNewVersion()` beside `isSessionLive()` — because each further read would only
 * get the same 409. The shell's strip offers "Reload now"; nothing reloads until the person says so,
 * so whatever they were typing stays where it is.
 *
 * It is not an error and not a network failure: `net.ts`'s backoff never sees it.
 */

const state = ref(false);

export const newVersion = readonly(state);

export function hasNewVersion(): boolean {
    return state.value;
}

/**
 * With every poller stopped, nothing would notice the session ending — so one probe stays alive:
 * every minute, only while the tab is visible and the session is still believed live.
 */
const PROBE_MS = 60_000;
let probe: ReturnType<typeof setInterval> | null = null;

function probeTick(): void {
    if (typeof document !== 'undefined' && document.visibilityState !== 'visible') {
        return;
    }

    if (!isSessionLive()) {
        return;
    }

    void probeSession();
}

export function markNewVersion(): void {
    state.value = true;

    if (probe === null && typeof window !== 'undefined') {
        probe = setInterval(probeTick, PROBE_MS);
    }
}

/** Is this an Inertia response body saying "the assets changed"? */
export function isVersionAnswer(status: number, reason: string | null): boolean {
    return status === 409 && reason === 'version';
}
