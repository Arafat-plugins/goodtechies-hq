<script setup lang="ts">
import { usePage } from "@inertiajs/vue3";
import { Pause, Play } from "@lucide/vue";
import { computed, onBeforeUnmount, ref, useId, watch } from "vue";
import {
    formatClock,
    formatDuration,
    spokenDuration,
} from "@/Components/Timer/timer";
import type { MyTaskTimer } from "@/Components/Timer/taskTimer";
import {
    CLOCK_IN_CONFIRM_TEXT,
    ownElapsed,
    useTaskTimer,
} from "@/Components/Timer/taskTimer";
import { Button } from "@/Components/ui/button";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/Components/ui/dialog";
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from "@/Components/ui/tooltip";

/**
 * ONE button for ONE task's timer — flow F3, brief 029. Mounted on a board card
 * (`variant="card"`), in the drawer header and the Show pages' actions (`variant="panel"`).
 *
 * Drawn only where the server said `permissions.can_track_time` — the parent decides; this
 * component never asks a role. Its states are the reader's own `my_timer` from `TaskResource`,
 * and the label carries the task's tracked time (`trackedSeconds`), so a card needs no second
 * clock counter beside it:
 *
 *   none, tracked ≥ 1 min   ▶ 18h 11m    "Start timer"   (every state: one light pill, no border)
 *   none, nothing tracked   ▶ Start      "Start timer"
 *   running                 ⏸ 18:11:05   "Pause timer"   (ticks every second)
 *   paused                  ▶ 18:11:05   "Start timer"   (resumes)
 *
 * One click: none → start, running → pause, paused → resume. **There is no ⏹** (brief 029): an
 * open timer still closes on clock-out, on starting another task, and by the watchdog.
 *
 * **Running and paused show the task's total plus the reader's own open session.**
 * `tasks.tracked_seconds` is the sum of STOPPED entries only (`TimerService::refreshTaskTotal`,
 * `->stopped()->tracked()`), so the open entry is never in it and adding `my_timer`'s elapsed
 * time cannot count it twice. Without `trackedSeconds` (or at 0) it is the reader's own clock.
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
        /**
         * The task's `tracked_seconds` — stopped, approved entries only, so never the open one.
         * Omitted or 0: the label is `Start` idle, and the reader's own session clock otherwise.
         */
        trackedSeconds?: number | null;
        variant?: "card" | "panel";
    }>(),
    { variant: "card", trackedSeconds: null },
);

const emit = defineEmits<{
    /** A write finished — the drawer re-reads its task on this. */
    settled: [];
}>();

const page = usePage();
const canTrackTime = computed(
    () => page.props.auth.user?.canTrackTime === true,
);
const actions = useTaskTimer(canTrackTime.value);
const owner = Symbol("task-timer");

const receivedAt = ref(Date.now());
const now = ref(Date.now());
let tick: ReturnType<typeof setInterval> | null = null;

function syncTick(): void {
    const running = props.myTimer?.state === "running";

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

const elapsed = computed(() =>
    props.myTimer === null
        ? 0
        : ownElapsed(props.myTimer, receivedAt.value, now.value),
);
const tracked = computed(() => {
    const seconds = props.trackedSeconds;

    return typeof seconds === "number" && Number.isFinite(seconds) && seconds > 0
        ? Math.floor(seconds)
        : 0;
});
/** The task's total so far: the stopped entries plus the reader's own open session. */
const total = computed(() => tracked.value + elapsed.value);
/**
 * What the button reads. Idle with a whole minute tracked: `18h 11m` (the card's own
 * `formatDuration`, since nothing is moving); idle under that: `Start`; open: `18:11:05`.
 */
const label = computed(() => {
    if (props.myTimer !== null) {
        return formatClock(total.value);
    }

    return tracked.value >= 60 ? formatDuration(tracked.value) : "Start";
});
/**
 * The total for a screen reader, which never hears the ticking label: whole minutes only, so it
 * changes at most once a minute, and it is a description, never announced on change.
 */
const descriptionId = useId();
const description = computed(() =>
    props.myTimer === null && tracked.value < 60
        ? ""
        : `${spokenDuration(total.value)} tracked`,
);

const asking = computed(() => actions.prompt.value?.owner === owner);

function settled(): void {
    emit("settled");
}

function onPrimary(): void {
    if (props.myTimer === null) {
        actions.start(props.taskId, owner, { onSettled: settled });
    } else if (props.myTimer.state === "running") {
        actions.pause(settled);
    } else {
        actions.resume(settled);
    }
}

function confirmClockIn(): void {
    actions.start(props.taskId, owner, { clockIn: true, onSettled: settled });
}

function onDialogOpen(open: boolean): void {
    if (!open) {
        actions.dismissPrompt();
    }
}

const primaryLabel = computed(() =>
    props.myTimer?.state === "running" ? "Pause timer" : "Start timer",
);
const size = computed(() => (props.variant === "card" ? "xs" : "sm"));

/**
 * **One light pill for every state** (brief 026): idle `▶ Start`, running `⏸ 0:12:34` and paused
 * `▶ 0:12:34` all wear the same soft `--brand-tint` fill with `--primary` text (4.63:1 light /
 * 5.93:1 dark) and **no border** — `border-transparent`, so the computed border colour is never a
 * red or orange ring. The time is inside this one button; there is no separate time chip and,
 * since brief 029, no ⏹ beside it.
 * Hover stays light: `--brand-tint-strong` with `--foreground` text (11.06:1 / 11.96:1), because
 * `--primary` text drops to 4.16:1 on that fill.
 */
const PILL =
    "rounded-full border-transparent bg-brand-tint font-medium text-primary tabular-nums hover:bg-brand-tint-strong hover:text-foreground dark:hover:bg-brand-tint-strong";
</script>

<template>
    <TooltipProvider>
        <span class="inline-flex shrink-0 items-center gap-1" data-card-timer>
            <Tooltip>
                <TooltipTrigger as-child>
                    <Button
                        type="button"
                        variant="ghost"
                        :size="size"
                        :aria-label="primaryLabel"
                        :aria-describedby="description ? descriptionId : undefined"
                        :aria-pressed="myTimer?.state === 'running'"
                        :disabled="actions.busy.value"
                        draggable="false"
                        data-task-timer-primary
                        :class="PILL"
                        @click.stop="onPrimary"
                    >
                        <Pause
                            v-if="myTimer?.state === 'running'"
                            aria-hidden="true"
                        />
                        <Play v-else aria-hidden="true" class="fill-current" />
                        <span
                            aria-hidden="true"
                            :data-task-timer-start="myTimer === null ? '' : undefined"
                            :data-task-timer-clock="myTimer !== null ? '' : undefined"
                            >{{ label }}</span
                        >
                    </Button>
                </TooltipTrigger>
                <TooltipContent>{{ primaryLabel }}</TooltipContent>
            </Tooltip>

            <span
                v-if="description"
                :id="descriptionId"
                class="sr-only"
                data-task-timer-total
                >{{ description }}</span
            >

            <!-- Said to a screen reader once, not every second: the state, not the count. -->
            <span class="sr-only" aria-live="polite">
                {{
                    myTimer === null
                        ? ""
                        : myTimer.state === "running"
                          ? "Timer running"
                          : "Timer paused"
                }}
            </span>
        </span>

        <Dialog v-if="asking" :open="asking" @update:open="onDialogOpen">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{{ CLOCK_IN_CONFIRM_TEXT }}</DialogTitle>
                    <DialogDescription>
                        You are not clocked in. This clocks you in now and
                        starts the timer on this task.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="actions.dismissPrompt()"
                        >Cancel</Button
                    >
                    <Button
                        type="button"
                        :disabled="actions.busy.value"
                        data-task-timer-confirm
                        @click="confirmClockIn"
                    >
                        Clock in and start
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </TooltipProvider>
</template>
