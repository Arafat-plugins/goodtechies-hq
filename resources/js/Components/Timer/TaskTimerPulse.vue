<script lang="ts">
/**
 * Module state, not component state: a layout mounted again on the next page must not start a
 * second loop beside the first, and "asked once this page load" means once per document.
 */
let loop: ReturnType<typeof setInterval> | null = null;
let askedThisLoad = false;
</script>

<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed, onMounted, watch } from 'vue';
import { beatTaskTimer, useTaskTimer } from '@/Components/Timer/taskTimer';

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

onMounted(async () => {
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
