<script lang="ts">
/**
 * Module state, not component state: a layout mounted again on the next page must not start a
 * second loop beside the first, and "asked once this page load" means once per document.
 */
let loop: ReturnType<typeof setInterval> | null = null;
let askedThisLoad = false;

/**
 * Brief 009 (follow-up to 008): is another goodERP tab open? Every tab says "alive" on the
 * `hq-tabs` BroadcastChannel every 5 s and remembers when ANOTHER tab last did. Closing one of
 * two tabs then skips the leaving beacon — the timer is still being kept alive next door.
 * Module state with a mount count, so a layout swap (two instances for a moment) never hears
 * itself as "another tab".
 */
const TAB_ALIVE_MS = 5_000;
const OTHER_TAB_FRESH_MS = 12_000;
let tabChannel: BroadcastChannel | null = null;
let tabTimer: ReturnType<typeof setInterval> | null = null;
let tabMounts = 0;
let otherTabAliveAt = 0;

function openTabChannel(): void {
    tabMounts += 1;

    if (tabChannel !== null || typeof BroadcastChannel === 'undefined') {
        return;
    }

    try {
        tabChannel = new BroadcastChannel('hq-tabs');
    } catch {
        tabChannel = null;

        return;
    }

    tabChannel.onmessage = (event: MessageEvent) => {
        if (typeof (event.data as { alive?: unknown } | null)?.alive === 'number') {
            otherTabAliveAt = Date.now();
        }
    };

    const say = (): void => {
        try {
            tabChannel?.postMessage({ alive: Date.now() });
        } catch {
            // A closed channel: nothing to say.
        }
    };

    say();
    tabTimer = setInterval(say, TAB_ALIVE_MS);
}

function closeTabChannel(): void {
    tabMounts = Math.max(0, tabMounts - 1);

    if (tabMounts > 0) {
        return;
    }

    if (tabTimer !== null) {
        clearInterval(tabTimer);
        tabTimer = null;
    }

    tabChannel?.close();
    tabChannel = null;
}

function anotherTabAlive(): boolean {
    return Date.now() - otherTabAliveAt < OTHER_TAB_FRESH_MS;
}
</script>

<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, watch } from 'vue';
import { beatTaskTimer, taskTimerRoutes, useTaskTimer } from '@/Components/Timer/taskTimer';
import { remoteTimerRunning, timerRoutes } from '@/Components/Timer/timer';

/**
 * The heartbeat for an office/Admin task timer — flow F3. Renders nothing.
 *
 * Mounted once in each shell (Admin and Employee layouts) because a timer started on the Board
 * keeps running while the person goes to Messages, and the watchdog stops any timer that has not
 * pinged for `heartbeat_timeout_minutes` (Part D §7's rule, which applies to everyone). A remote
 * employee's timer already beats through `timer.ts`, so this stays silent for them.
 *
 * One beat on the first mount of a full page load answers "is a timer open?" (the page may have
 * been reloaded mid-session); after that it beats every minute only while one is. The server's
 * answer ends it: a reply saying nothing is open — stopped here, by a clock-out, by the watchdog —
 * turns the loop off.
 */

const page = usePage();
const actions = useTaskTimer(false);

/**
 * Only somebody on the office clock has a timer this loop keeps alive. An optimisation, not a
 * permission: the endpoint answers for itself, and this only spares the Accountant and the remote
 * shell a request that would be refused or redundant.
 */
const eligible = computed(() => {
    const user = page.props.auth.user;

    return user !== null && user !== undefined && user.canTrackTime !== true && user.trackingMode === 'office_attendance';
});

async function beat(): Promise<void> {
    const open = await beatTaskTimer();

    if (open === false) {
        actions.setPulseWanted(false);
    }
}

function sync(wanted: boolean): void {
    if (wanted && eligible.value && loop === null) {
        loop = setInterval(() => void beat(), 60_000);
    } else if ((!wanted || !eligible.value) && loop !== null) {
        clearInterval(loop);
        loop = null;
    }
}

watch(() => actions.pulseWanted.value, sync);

/* ------------------------------------------------------------- closing the last tab */

/**
 * While a timer runs, closing the tab asks first (the browser's own "Leave site?"), and the
 * `pagehide` beacon tells the server the moment the tab went. The server stops the timer AT that
 * moment once 30 s pass with no heartbeat — a reload beats straight away and cancels it, and so
 * does another goodERP tab still open. The office clock-in is untouched: task/remote timer only.
 */
const taskTimerOpen = computed(() => eligible.value && actions.pulseWanted.value);
const anyRunning = computed(() => taskTimerOpen.value || remoteTimerRunning.value);

/** The session's CSRF token as a form field: a csrf meta if the page has one, else the XSRF cookie. */
function formToken(): string {
    const meta = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;

    if (meta) {
        return meta;
    }

    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

function onBeforeUnload(event: BeforeUnloadEvent): void {
    if (!anyRunning.value) {
        return;
    }

    event.preventDefault();
    event.returnValue = '';
}

function onPageHide(): void {
    if (!anyRunning.value || typeof navigator.sendBeacon !== 'function') {
        return;
    }

    // Another tab is still open and beating: this one leaving is not the person leaving.
    if (anotherTabAlive()) {
        return;
    }

    const urls = [...(taskTimerOpen.value ? [taskTimerRoutes.leaving] : []), ...(remoteTimerRunning.value ? [timerRoutes.leaving] : [])];

    for (const url of urls) {
        const data = new FormData();
        data.append('_token', formToken());
        navigator.sendBeacon(url, data);
    }
}

/**
 * Per instance, not module state: when a layout is swapped, the old instance's unmount runs
 * after the new one's setup, and must only remove its own listeners.
 */
let guarding = false;

function guard(running: boolean): void {
    if (typeof window === 'undefined') {
        return;
    }

    if (running && !guarding) {
        window.addEventListener('beforeunload', onBeforeUnload);
        window.addEventListener('pagehide', onPageHide);
        guarding = true;
    } else if (!running && guarding) {
        window.removeEventListener('beforeunload', onBeforeUnload);
        window.removeEventListener('pagehide', onPageHide);
        guarding = false;
    }
}

watch(anyRunning, guard, { immediate: true });

onUnmounted(() => {
    guard(false);
    closeTabChannel();
});

onMounted(async () => {
    openTabChannel();

    if (!eligible.value || askedThisLoad) {
        return;
    }

    askedThisLoad = true;

    const open = await beatTaskTimer();

    if (open === true) {
        actions.setPulseWanted(true);
    }

    sync(actions.pulseWanted.value);
});
</script>

<template>
    <span hidden aria-hidden="true" />
</template>
