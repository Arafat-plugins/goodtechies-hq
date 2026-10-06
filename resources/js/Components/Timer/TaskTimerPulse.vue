<script lang="ts">
/**
 * Module state, not component state: a layout mounted again on the next page must not start a
 * second loop beside the first, and "asked once this page load" means once per document.
 */
let loop: ReturnType<typeof setInterval> | null = null;
let askedThisLoad = false;

/**
 * Brief 009 (follow-up to 008), reworked in polish 026: is another goodERP tab open?
 *
 * Every tab has an id and keeps a register of the OTHER tabs on the `hq-tabs` BroadcastChannel:
 * `hello` when it opens (everybody already open answers `here`), `alive` every 5 s, and `bye`
 * from `pagehide`. The register used to be a single "last heard 12 s ago" clock, and Chrome
 * throttles a background tab's timers to once a minute — so with a second tab open behind this
 * one, closing this one still raised "Leave site?" and sent the leaving beacon. A tab that said
 * hello and has not said bye now counts as open for up to OTHER_TAB_STALE_MS without a beat,
 * which only matters for a tab that crashed rather than closed.
 *
 * Module state with a mount count, so a layout swap (two instances for a moment) never hears
 * itself as "another tab".
 */
const TAB_ALIVE_MS = 5_000;
const OTHER_TAB_STALE_MS = 10 * 60_000;
const tabId = Math.random().toString(36).slice(2) + Date.now().toString(36);
const otherTabs = new Map<string, number>();
let tabChannel: BroadcastChannel | null = null;
let tabTimer: ReturnType<typeof setInterval> | null = null;
let tabMounts = 0;

type TabFrame = { hello?: string; here?: string; alive?: string | number; bye?: string };

function tabSay(frame: TabFrame): void {
    try {
        tabChannel?.postMessage(frame);
    } catch {
        // A closed channel: nothing to say.
    }
}

function sayBye(): void {
    tabSay({ bye: tabId });
}

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
        const frame = (event.data ?? {}) as TabFrame;
        const now = Date.now();

        if (typeof frame.bye === 'string') {
            otherTabs.delete(frame.bye);

            return;
        }

        if (typeof frame.hello === 'string') {
            otherTabs.set(frame.hello, now);
            tabSay({ here: tabId });

            return;
        }

        const id = frame.here ?? frame.alive;

        if (typeof id === 'string') {
            otherTabs.set(id, now);
        } else if (typeof id === 'number') {
            // A tab still running the old build: no id, so it can only be "somebody".
            otherTabs.set('legacy', now);
        }
    };

    tabSay({ hello: tabId });
    tabTimer = setInterval(() => tabSay({ alive: tabId }), TAB_ALIVE_MS);
    window.addEventListener('pagehide', sayBye);
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

    window.removeEventListener('pagehide', sayBye);
    tabChannel?.close();
    tabChannel = null;
}

function anotherTabAlive(): boolean {
    const now = Date.now();

    for (const [id, seenAt] of otherTabs) {
        if (now - seenAt < OTHER_TAB_STALE_MS) {
            return true;
        }

        otherTabs.delete(id);
    }

    return false;
}
</script>

<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, watch } from 'vue';
import ClockOutOnLeaveDialog from '@/Components/Attendance/ClockOutOnLeaveDialog.vue';
import { clockedIn, leaveAllowed, openClockOutDialog, syncClockFromPage } from '@/Components/Attendance/clockState';
import { beatTaskTimer, taskTimerRoutes, useTaskTimer } from '@/Components/Timer/taskTimer';
import { remoteTimerRunning, timerRoutes } from '@/Components/Timer/timer';
import { installLeaveIntent, isDownloading, isInAppNavigation } from '@/lib/leaveIntent';

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

/**
 * Decision 12-84: somebody clocked in is asked too. The native prompt is all a closing tab may
 * show; if they choose to stay, `ClockOutOnLeaveDialog` asks whether to clock out as well. If they
 * leave, they stay clocked in — nothing about the clock is ever sent on the way out.
 * "Leave Without Clocking Out" stands the clock half down for this page view.
 */
const askAboutClock = computed(() => clockedIn.value && !leaveAllowed.value);
const guardActive = computed(() => anyRunning.value || askAboutClock.value);

syncClockFromPage(page);

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
    // Polish 018: downloading a file, or moving to another goodERP page by a full load (a link,
    // or a click that a deploy turned into one), is not leaving goodERP — no "Leave site?".
    // Polish 026: with another goodERP tab still open, closing this one is not leaving goodERP.
    if (!guardActive.value || isDownloading() || isInAppNavigation() || anotherTabAlive()) {
        return;
    }

    event.preventDefault();
    event.returnValue = '';

    if (askAboutClock.value) {
        offerClockOutIfStaying();
    }
}

/**
 * Polish 019: the clock-out dialog is for somebody who pressed *Stay* on the browser's box.
 *
 * It used to open on a 0 ms timer — which also fires when the person pressed *Reload* or
 * *Leave*, because the old page stays on screen until the new one arrives, so the dialog
 * flashed up on every reload. Nothing tells a page which button was pressed, so it waits for
 * proof that the page is still in use: the person's next click or key on it (or 8 s passing
 * with the page still here). A `pagehide` — the page really going — cancels it.
 */
let pendingOffer: (() => void) | null = null;

function offerClockOutIfStaying(): void {
    pendingOffer?.();

    const armedAt = Date.now();
    const onUse = (): void => {
        if (Date.now() - armedAt >= 300) {
            done();
            openClockOutDialog();
        }
    };
    const fallback = setTimeout(() => {
        done();
        openClockOutDialog();
    }, 8000);
    const done = (): void => {
        clearTimeout(fallback);
        window.removeEventListener('pointerdown', onUse, true);
        window.removeEventListener('keydown', onUse, true);
        window.removeEventListener('pagehide', done);
        pendingOffer = null;
    };

    window.addEventListener('pointerdown', onUse, true);
    window.addEventListener('keydown', onUse, true);
    window.addEventListener('pagehide', done);
    pendingOffer = done;
}

/**
 * Client doc 2026-10-05 item 1: closing the last goodERP tab while clocked in clocks out. The
 * beacon only marks the moment; the server clocks out AT it a minute later unless a page of
 * this person's comes back (a reload does, at once). "Leave Without Clocking Out" opts out.
 */
const ATTENDANCE_LEAVING_URL = '/attendance/leaving';

function onPageHide(): void {
    const clockOutOnClose = clockedIn.value && !leaveAllowed.value;

    if ((!anyRunning.value && !clockOutOnClose) || typeof navigator.sendBeacon !== 'function' || isInAppNavigation()) {
        return;
    }

    // Another tab is still open and beating: this one leaving is not the person leaving.
    if (anotherTabAlive()) {
        return;
    }

    const urls = [
        ...(taskTimerOpen.value ? [taskTimerRoutes.leaving] : []),
        ...(remoteTimerRunning.value ? [timerRoutes.leaving] : []),
        ...(clockOutOnClose ? [ATTENDANCE_LEAVING_URL] : []),
    ];

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
        installLeaveIntent();
        window.addEventListener('beforeunload', onBeforeUnload);
        window.addEventListener('pagehide', onPageHide);
        guarding = true;
    } else if (!running && guarding) {
        window.removeEventListener('beforeunload', onBeforeUnload);
        window.removeEventListener('pagehide', onPageHide);
        guarding = false;
    }
}

watch(guardActive, guard, { immediate: true });

let lastPath = typeof window === 'undefined' ? '' : window.location.pathname;

const stopSyncing = router.on('success', (event) => {
    syncClockFromPage(event.detail.page);

    // A new page view: "Leave Without Clocking Out" applied only to the one before.
    if (window.location.pathname !== lastPath) {
        lastPath = window.location.pathname;
        leaveAllowed.value = false;
    }
});

onUnmounted(() => {
    stopSyncing();
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
    <ClockOutOnLeaveDialog />
</template>
