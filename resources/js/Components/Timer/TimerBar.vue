<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { ChevronUp, Timer as TimerIcon, X } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import TimerControls from '@/Components/Timer/TimerControls.vue';
import { timerRoutes, useTimer } from '@/Components/Timer/timer';
import { Button } from '@/Components/ui/button';
import { readBarVisible, writeBarVisible } from '@/lib/timerState';

/**
 * The timer, on every page of the Employee shell.
 *
 * ## Who sees it
 *
 * `auth.user.canTrackTime` and nothing else. That is `TimeEntryPolicy::track` resolved on the
 * server — the `timer.use` key AND `tracking_mode = remote_timer` — so an office employee gets
 * **no timer UI at all**, not a disabled one. Deriving it here from `role` or `trackingMode`
 * would be the second copy of a policy that decisions 2-28 and 2-31 were both recorded about.
 *
 * ## Why it is sticky and not fixed
 *
 * A `fixed` bar sits on top of the page and covers whatever is under it — at 360 px that is
 * usually the last row of a list or a form's submit button. This is the last child of the
 * layout's column with `sticky bottom-0`, so it OCCUPIES layout space: it follows the viewport
 * while there is page left to scroll and settles at the end, and there is no width at which it
 * hides content. That is also why it needs no z-index fight with the sidebar.
 *
 * Being last in the DOM puts it last in the tab order, after the page's own controls, and it
 * traps nothing — it is a region, not a dialog. Esc is not bound, because there is nothing
 * modal to escape from.
 *
 * ## Dismissing it
 *
 * The *Hide* button collapses it to a single labelled button, reachable by keyboard from the
 * same place. The preference is this viewer's alone, so it is `localStorage` (`hq.timer.bar`,
 * in `lib/timerState.ts`) rather than the query string — DESIGN.md §5.10.
 *
 * A hidden bar does not stop the timer and does not stop the heartbeat: `useTimer()` owns
 * both, and they run for as long as the tab is open.
 */

const page = usePage();
const timer = useTimer();

/** The server's answer, never a role read here. */
const canTrack = computed(() => page.props.auth.user?.canTrackTime === true);

const visible = ref(true);

onMounted(() => {
    visible.value = readBarVisible();

    // The page may have been open since before a watchdog sweep, or reopened from a closed
    // laptop. Ask the server what it thinks before painting a counter.
    void timer.refresh();
});

function hide(): void {
    visible.value = false;
    writeBarVisible(false);
}

function show(): void {
    visible.value = true;
    writeBarVisible(true);
}
</script>

<template>
    <div v-if="canTrack" class="sticky bottom-0 z-20">
        <section
            v-if="visible"
            aria-label="Timer"
            class="border-t bg-card px-4 py-3 shadow-raised md:px-6"
        >
            <div class="mx-auto flex w-full max-w-screen-2xl min-w-0 items-start gap-4">
                <TimerControls variant="bar" :tasks="timer.tasks.value" class="flex-1" />

                <div class="flex shrink-0 items-center gap-1">
                    <Button as-child size="sm" variant="ghost">
                        <a :href="timerRoutes.index">Time</a>
                    </Button>
                    <Button
                        type="button"
                        size="icon"
                        variant="ghost"
                        aria-label="Hide the timer bar"
                        @click="hide"
                    >
                        <X aria-hidden="true" />
                    </Button>
                </div>
            </div>
        </section>

        <!--
            Collapsed. Still a real button in the tab order, still labelled, and it takes so
            little room that a phone keeps its whole page.
        -->
        <div v-else class="flex justify-end px-4 pb-2 md:px-6">
            <Button type="button" size="sm" variant="outline" @click="show">
                <TimerIcon aria-hidden="true" />
                Show timer
                <ChevronUp aria-hidden="true" />
            </Button>
        </div>
    </div>
</template>
