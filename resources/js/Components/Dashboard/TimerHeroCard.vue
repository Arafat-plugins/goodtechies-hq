<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowRight, Timer } from '@lucide/vue';
import { computed } from 'vue';
import { formatDuration, timerRoutes, useTimer } from '@/Components/Timer/timer';
import TimerControls from '@/Components/Timer/TimerControls.vue';
import { Card } from '@/Components/ui/card';

/**
 * The remote employee's day, at the top of their dashboard.
 *
 * Decision 0.5-5 made the timer this page's hero, and until Phase 4 this card was a disabled
 * button reading "Arrives in Phase 4". It is not a placeholder any more.
 *
 * **It carries the same controls as `TimerBar`, not a second timer** (decision 11-03). The
 * client asked for the buttons on the dashboard card itself; both the card and the bar mount
 * the one `TimerControls` over the one `useTimer()` store, so they cannot disagree — a start
 * here is the start the bar shows, and the "left today" line below the figure ticks from the
 * same elapsed value. This card answers "how am I doing today" and links to the page that
 * answers "on what".
 *
 * The figures are the server's. `counted` is what `approved_at is not null` says; `pending` is
 * the rest — recorded, not yet counted, because `manual_time_requires_approval` is on. Showing
 * only the counted half would quietly lose hours somebody actually worked, and the person whose
 * hours they are is the one reading this. The live session is added on top from the shared
 * timer store, because a figure that sat still while the clock ran would read as broken.
 */
const props = defineProps<{
    countedSeconds: number;
    pendingSeconds: number;
    /** `schedules.working_hours_per_day`, or null for an employee with no schedule. */
    targetSeconds: number | null;
}>();

const timer = useTimer();

const total = computed(
    () => props.countedSeconds + (timer.running.value !== null ? Math.floor(timer.elapsedSeconds.value) : 0),
);

const targetLabel = computed(() =>
    props.targetSeconds === null ? null : formatDuration(props.targetSeconds),
);

/** What is left of the day's target, from the same `total` — so it ticks with the figure above. */
const leftSeconds = computed(() =>
    props.targetSeconds === null ? null : Math.max(0, props.targetSeconds - total.value),
);
</script>

<template>
    <Card class="gap-4 p-6 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex min-w-0 flex-col gap-2">
            <p class="flex items-center gap-2 text-sm text-muted-foreground">
                <Timer class="size-4 shrink-0" aria-hidden="true" />
                Time today
            </p>

            <p class="text-3xl font-semibold tabular-nums">
                {{ formatDuration(total) }}
                <span v-if="targetLabel" class="text-xl font-normal text-muted-foreground">
                    / {{ targetLabel }}
                </span>
            </p>

            <p v-if="leftSeconds !== null" class="text-sm tabular-nums text-muted-foreground">
                <template v-if="leftSeconds > 0">{{ formatDuration(leftSeconds) }} left today</template>
                <template v-else>Target reached</template>
            </p>

            <!--
                Said in words rather than shown as a tint: hours waiting on an approval are not
                a lesser kind of hour, they are hours nobody has signed off yet, and that
                distinction has to survive a screen reader.
            -->
            <p v-if="pendingSeconds > 0" class="text-xs text-muted-foreground">
                {{ formatDuration(pendingSeconds) }} more is recorded and waiting for approval.
            </p>
        </div>

        <div class="flex min-w-0 flex-col gap-2 sm:items-end">
            <TimerControls variant="bar" :tasks="timer.tasks.value" />
            <Link
                :href="timerRoutes.index"
                class="inline-flex items-center gap-1 rounded-sm text-sm font-medium text-muted-foreground underline-offset-4 outline-none hover:text-foreground hover:underline focus-visible:ring-3 focus-visible:ring-ring"
            >
                My time
                <ArrowRight class="size-4" aria-hidden="true" />
            </Link>
        </div>
    </Card>
</template>
