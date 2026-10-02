import { ref } from 'vue';

/**
 * Is the signer-in clocked in right now? Module state, so the tab-close guard
 * (`Components/Timer/TaskTimerPulse.vue`), the clock widget and the clock-out-on-leave dialog
 * all read one value (decision 12-84).
 *
 * It starts from the shared `clock` prop (`HandleInertiaRequests::sharedClock()`, `null` for
 * anyone not on the office clock), is re-read after every Inertia navigation, and is set the
 * moment a clock-in or clock-out lands.
 */
export const clockedIn = ref(false);

/** The "You're currently clocked in" dialog, opened only after somebody chose to stay. */
export const clockOutDialogOpen = ref(false);

/**
 * "Leave Without Clocking Out" was chosen: the guard stands down for this page view, so the
 * close that follows is not asked about again.
 */
export const leaveAllowed = ref(false);

interface PageWithClock {
    props: { clock?: { clocked_in: boolean } | null };
}

/** A response that does not carry `clock` at all (a partial reload) leaves the value alone. */
export function syncClockFromPage(page: PageWithClock): void {
    if (!('clock' in page.props)) {
        return;
    }

    clockedIn.value = page.props.clock?.clocked_in === true;
}

export function setClockedIn(value: boolean): void {
    clockedIn.value = value;
}

export function openClockOutDialog(): void {
    if (clockedIn.value && !leaveAllowed.value) {
        clockOutDialogOpen.value = true;
    }
}
