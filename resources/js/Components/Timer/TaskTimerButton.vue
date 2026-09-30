<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Pause, Play, Square } from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { formatClock } from '@/Components/Timer/timer';
import type { MyTaskTimer } from '@/Components/Timer/taskTimer';
import { CLOCK_IN_CONFIRM_TEXT, ownElapsed, useTaskTimer } from '@/Components/Timer/taskTimer';
import { Button } from '@/Components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/Components/ui/tooltip';

/**
 * ▶ / ⏸ / ⏹ for ONE task — flow F3. Mounted on a board card (`variant="card"`) and in the
 * drawer's Time section (`variant="panel"`).
 *
 * Drawn only where the server said `permissions.can_track_time` — the parent decides; this
 * component never asks a role. Its states are the reader's own `my_timer` from `TaskResource`:
 *
 *   none     ▶ Start (pill)           "Start timer"
 *   running  ⏸ 0:12:34  ⏹           "Pause timer", "Stop timer"
 *   paused   ▶ 0:12:34  ⏹           "Start timer" (resumes), "Stop timer"
 *
 * **The one-second clock is local to this small component** and runs only while the reader's
 * own timer is running here. Nothing else on the card reads it, so a tick re-renders this
 * button's text and never the card (the brief's rule: never re-render whole cards per second).
 *
 * The "Clock in and start?" confirm is drawn by the instance that asked, and only while it is
 * asking (`prompt.owner`), so a board of two hundred cards mounts no dialog until one is needed.
 */

const props = withDefaults(
    defineProps<{
        taskId: number;
        myTimer: MyTaskTimer | null;
        variant?: 'card' | 'panel';
    }>(),
    { variant: 'card' },
);

const emit = defineEmits<{
    /** A write finished — the drawer re-reads its task on this. */
    settled: [];
}>();

const page = usePage();
const canTrackTime = computed(() => page.props.auth.user?.canTrackTime === true);
const actions = useTaskTimer(canTrackTime.value);
const owner = Symbol('task-timer');

const receivedAt = ref(Date.now());
const now = ref(Date.now());
let tick: ReturnType<typeof setInterval> | null = null;

function syncTick(): void {
    const running = props.myTimer?.state === 'running';

    if (running && tick === null) {
        tick = setInterval(() => {
            now.value = Date.now();
        }, 1000);
    } else if (!running && tick !== null) {
        clearInterval(tick);
        tick = null;
    }
}

watch(
    () => props.myTimer,
    (timer) => {
        receivedAt.value = Date.now();
        now.value = receivedAt.value;
        syncTick();

        // An office/Admin timer this tab can see is one the pulse must keep alive.
        if (timer !== null && !canTrackTime.value) {
            actions.setPulseWanted(true);
        }
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    if (tick !== null) {
        clearInterval(tick);
    }
});

const elapsed = computed(() => (props.myTimer === null ? 0 : ownElapsed(props.myTimer, receivedAt.value, now.value)));
const clock = computed(() => formatClock(elapsed.value));

const asking = computed(() => actions.prompt.value?.owner === owner);

function settled(): void {
    emit('settled');
}

function onPrimary(): void {
    if (props.myTimer === null) {
        actions.start(props.taskId, owner, { onSettled: settled });
    } else if (props.myTimer.state === 'running') {
        actions.pause(settled);
    } else {
        actions.resume(settled);
    }
}

function onStop(): void {
    actions.stop(settled);
}

function confirmClockIn(): void {
    actions.start(props.taskId, owner, { clockIn: true, onSettled: settled });
}

function onDialogOpen(open: boolean): void {
    if (!open) {
        actions.dismissPrompt();
    }
}

const primaryLabel = computed(() => (props.myTimer?.state === 'running' ? 'Pause timer' : 'Start timer'));
const size = computed(() => (props.variant === 'card' ? 'xs' : 'sm'));
const iconSize = computed(() => (props.variant === 'card' ? 'icon-xs' : 'icon-sm'));

/**
 * The idle ▶ is a soft-primary pill with a word on it (brief 021) — a faint grey icon was not
 * found. `--primary` on `--brand-tint` is 4.63:1 light / 5.93:1 dark; hover goes to the solid
 * `--primary` fill (5.01:1 / 7.31:1) rather than `--brand-tint-strong`, where `--primary` text
 * drops to 4.16:1. The border is `--brand`, a graphic, never a fill behind text (DESIGN.md).
 */
const IDLE_PILL =
    'rounded-full border border-brand bg-brand-tint font-medium text-primary hover:bg-primary hover:text-primary-foreground';
</script>

<template>
    <TooltipProvider>
        <span class="inline-flex shrink-0 items-center gap-1" data-card-timer>
            <Tooltip>
                <TooltipTrigger as-child>
                    <Button
                        type="button"
                        :variant="myTimer === null ? 'ghost' : 'secondary'"
                        :size="size"
                        :aria-label="primaryLabel"
                        :aria-pressed="myTimer?.state === 'running'"
                        :disabled="actions.busy.value"
                        draggable="false"
                        data-task-timer-primary
                        :class="myTimer === null ? IDLE_PILL : 'tabular-nums'"
                        @click.stop="onPrimary"
                    >
                        <Pause v-if="myTimer?.state === 'running'" aria-hidden="true" />
                        <Play v-else aria-hidden="true" class="fill-current" />
                        <span v-if="myTimer === null" aria-hidden="true" data-task-timer-start>Start</span>
                        <span v-else aria-hidden="true" data-task-timer-clock>{{ clock }}</span>
                    </Button>
                </TooltipTrigger>
                <TooltipContent>{{ primaryLabel }}</TooltipContent>
            </Tooltip>

            <Tooltip v-if="myTimer !== null">
                <TooltipTrigger as-child>
                    <Button
                        type="button"
                        variant="ghost"
                        :size="iconSize"
                        aria-label="Stop timer"
                        :disabled="actions.busy.value"
                        draggable="false"
                        data-task-timer-stop
                        @click.stop="onStop"
                    >
                        <Square aria-hidden="true" />
                    </Button>
                </TooltipTrigger>
                <TooltipContent>Stop timer</TooltipContent>
            </Tooltip>

            <!-- Said to a screen reader once, not every second: the state, not the count. -->
            <span class="sr-only" aria-live="polite">
                {{ myTimer === null ? '' : myTimer.state === 'running' ? 'Timer running' : 'Timer paused' }}
            </span>
        </span>

        <Dialog v-if="asking" :open="asking" @update:open="onDialogOpen">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{{ CLOCK_IN_CONFIRM_TEXT }}</DialogTitle>
                    <DialogDescription>
                        You are not clocked in. This clocks you in now and starts the timer on this task.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button type="button" variant="outline" @click="actions.dismissPrompt()">Cancel</Button>
                    <Button type="button" :disabled="actions.busy.value" data-task-timer-confirm @click="confirmClockIn">
                        Clock in and start
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </TooltipProvider>
</template>
