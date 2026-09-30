<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { personTone } from '@/Components/Messages/people';
import { formatDuration } from '@/Components/Timer/timer';
import type { RunningTaskTimer } from '@/Components/Timer/taskTimer';
import { Avatar, AvatarFallback } from '@/Components/ui/avatar';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/Components/ui/tooltip';
import { useMinuteTicker } from '@/lib/minuteTicker';
import { cn } from '@/lib/utils';

/**
 * Who else is timing this task, and for how long — flow F3, for watchers only.
 *
 * The server sends `running_timers` to a reader `TimeEntryPolicy::watchLive` allows and to
 * nobody else (the key is absent), so this component is simply not mounted for an employee.
 *
 * **Minute resolution, on the shared minute ticker** (`lib/minuteTicker.ts`): forty cards being
 * timed share one timer, and a tick re-renders these small labels, never the card around them.
 * A face and a duration, and the words in the accessible name — no percentage, no ranking, no
 * comparison between people (Part H).
 */

const props = defineProps<{
    timers: RunningTaskTimer[];
}>();

const now = useMinuteTicker();
const receivedAt = ref(Date.now());

watch(
    () => props.timers,
    () => {
        receivedAt.value = Date.now();
    },
);

const rows = computed(() =>
    props.timers.map((timer) => {
        const seconds =
            timer.state === 'paused'
                ? timer.elapsed_seconds
                : timer.elapsed_seconds + Math.max(0, Math.floor((now.value - receivedAt.value) / 1000));
        const duration = formatDuration(seconds);

        return {
            ...timer,
            duration,
            label:
                timer.state === 'paused'
                    ? `${timer.name}: timer paused at ${duration}`
                    : `${timer.name}: timing this task, ${duration}`,
        };
    }),
);
</script>

<template>
    <TooltipProvider>
        <span class="inline-flex min-w-0 flex-wrap items-center gap-1" data-running-timers>
            <Tooltip v-for="timer in rows" :key="timer.employee_id">
                <TooltipTrigger as-child>
                    <span
                        role="img"
                        :aria-label="timer.label"
                        data-running-timer
                        class="inline-flex shrink-0 items-center gap-1 rounded-full border bg-secondary py-0.5 pr-2 pl-0.5 text-xs text-secondary-foreground tabular-nums"
                    >
                        <Avatar class="size-5">
                            <AvatarFallback :class="cn('text-xs', personTone(timer.employee_id).avatar)" aria-hidden="true">
                                {{ timer.initials }}
                            </AvatarFallback>
                        </Avatar>
                        <span aria-hidden="true">{{ timer.state === 'paused' ? '⏸ ' : '' }}{{ timer.duration }}</span>
                    </span>
                </TooltipTrigger>
                <TooltipContent>{{ timer.label }}</TooltipContent>
            </Tooltip>
        </span>
    </TooltipProvider>
</template>
