import { onScopeDispose, readonly, ref } from 'vue';
import type { Ref } from 'vue';

/**
 * One clock for every countdown on the page, ticking on the minute boundary.
 *
 * Module state, not component state: forty cards on a Board share **one** timer, not forty.
 * It runs only while somebody is subscribed and only while the tab is visible — a hidden tab
 * clears the timer, and becoming visible again reads the clock at once and re-aligns to the next
 * boundary, so a laptop that slept an hour does not show an hour-old label.
 *
 * Only `Components/Tasks/DueCountdown.vue` calls `useMinuteTicker()`. A tick writes `now`, and
 * the only render that reads `now` is that small component's — the card around it reads nothing
 * from here, so a tick re-renders the labels and never the card.
 */

const now = ref(Date.now());
let subscribers = 0;
let timer: ReturnType<typeof setTimeout> | null = null;

function visible(): boolean {
    return typeof document === 'undefined' || document.visibilityState === 'visible';
}

function stop(): void {
    if (timer !== null) {
        clearTimeout(timer);
        timer = null;
    }
}

/** Arm one timeout for the next whole minute (plus a hair, so it lands after the boundary). */
function schedule(): void {
    stop();

    if (subscribers === 0 || !visible()) {
        return;
    }

    timer = setTimeout(
        () => {
            now.value = Date.now();
            schedule();
        },
        60_000 - (Date.now() % 60_000) + 50,
    );
}

function onVisibilityChange(): void {
    if (visible()) {
        now.value = Date.now();
        schedule();
    } else {
        stop();
    }
}

function subscribe(): void {
    subscribers += 1;

    if (subscribers === 1) {
        now.value = Date.now();
        document.addEventListener('visibilitychange', onVisibilityChange);
        schedule();
    }
}

function unsubscribe(): void {
    subscribers = Math.max(subscribers - 1, 0);

    if (subscribers === 0) {
        stop();
        document.removeEventListener('visibilitychange', onVisibilityChange);
    }
}

/** The current time in epoch ms, refreshed on every minute boundary while the tab is visible. */
export function useMinuteTicker(): Readonly<Ref<number>> {
    subscribe();
    onScopeDispose(unsubscribe);

    return readonly(now);
}
