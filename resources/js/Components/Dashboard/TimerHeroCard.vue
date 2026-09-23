<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowRight, Timer } from '@lucide/vue';
import { computed } from 'vue';
import { formatDuration, timerRoutes, useTimer } from '@/Components/Timer/timer';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';

/**
 * The remote employee's day, at the top of their dashboard.
 *
 * Decision 0.5-5 made the timer this page's hero, and until Phase 4 this card was a disabled
 * button reading "Arrives in Phase 4". It is not a placeholder any more.
 *
 * **It carries no controls.** `TimerBar` is on every page of this shell and owns start, pause
 * and stop; a second set of buttons here would be a second thing to keep in step with the
 * running session, and the two would disagree the first time one of them missed a replay.
 * This card answers "how am I doing today" and links to the page that answers "on what".
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
</script>

<template>
    <Card class="gap-4 p-6 shadow-xs sm:flex-row sm:items-center sm:justify-between">
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

            <!--
                Said in words rather than shown as a tint: hours waiting on an approval are not
                a lesser kind of hour, they are hours nobody has signed off yet, and that
                distinction has to survive a screen reader.
            -->
            <p v-if="pendingSeconds > 0" class="text-xs text-muted-foreground">
                {{ formatDuration(pendingSeconds) }} more is recorded and waiting for approval.
            </p>
        </div>

        <div class="shrink-0">
            <Button as-child variant="outline">
                <Link :href="timerRoutes.index">
                    My time
                    <ArrowRight aria-hidden="true" />
                </Link>
            </Button>
        </div>
    </Card>
</template>
