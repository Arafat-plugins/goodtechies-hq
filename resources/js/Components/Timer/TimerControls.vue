<script setup lang="ts">
import { CloudOff, Flag, Pause, Play, Square } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import type { StatusKey } from '@/Components/StatusBadge.vue';
import type { TimeableTask } from '@/Components/Timer/timer';
import { formatClock, formatDuration, spokenDuration, useTimer } from '@/Components/Timer/timer';
import { Button } from '@/Components/ui/button';
import { Label } from '@/Components/ui/label';
import { NativeSelect, NativeSelectOption } from '@/Components/ui/native-select';
import { cn } from '@/lib/utils';

/**
 * Start / Pause / Resume / Stop, the live counter, and today's total against the target.
 *
 * One control cluster, mounted in three places — the persistent bar, the task-detail widget and
 * the Time page — because three copies of "which button is showing right now" is two copies
 * that will one day disagree with the server.
 *
 * ## Nothing here is carried by colour
 *
 * Running, paused, offline and flagged each print a word. The dot beside the state is
 * decoration: with it removed the line still says "Paused". DESIGN.md §5.6 is the rule, and
 * §2.2's measurement is the reason — four of the eight status dots fail 3:1 in light mode with
 * their label taken away.
 *
 * ## The counter does not shout
 *
 * The ticking figure is `aria-hidden`. A live region that announced a new second every second
 * would make the page unusable with a screen reader on, and nobody needs to hear the seconds.
 * What IS announced, politely and only when it changes, is the state: "Timer running", "Timer
 * paused", "Timer stopped, 4 hours 13 minutes recorded".
 */

const props = withDefaults(
    defineProps<{
        /** The picker's options. Without them the control can resume and stop but not start. */
        tasks?: TimeableTask[];
        /** Start on this task and offer no picker — the task-detail mount. */
        taskId?: number | null;
        /** `bar` is the compact one-line form; `panel` stacks. */
        variant?: 'bar' | 'panel';
    }>(),
    { tasks: () => [], taskId: null, variant: 'panel' },
);

const timer = useTimer();

/** The picker's selection. Defaults to the first task so Start is one click, not two. */
const chosen = ref<number | null>(props.taskId ?? props.tasks[0]?.id ?? null);

watch(
    () => props.tasks,
    (tasks) => {
        if (props.taskId === null && (chosen.value === null || !tasks.some((task) => task.id === chosen.value))) {
            chosen.value = tasks[0]?.id ?? null;
        }
    },
);

const running = computed(() => timer.running.value);
const isThisTask = computed(() => props.taskId === null || running.value?.task?.id === props.taskId);

const clock = computed(() => formatClock(timer.elapsedSeconds.value));

const target = computed(() => timer.targetSeconds.value);

const todayLine = computed(() => {
    const today = formatDuration(timer.todaySeconds.value);

    return target.value === null ? today : `${today} / ${formatDuration(target.value)}`;
});

/** 0–100 for the bar. Never above 100: the day is not a competition to overshoot. */
const progress = computed<number | null>(() => {
    if (target.value === null || target.value <= 0) {
        return null;
    }

    return Math.min(100, Math.round((timer.todaySeconds.value / target.value) * 100));
});

/**
 * The state in words, which is what both the screen and the announcement use. `offline` wins
 * over `running` because it is the more urgent fact: the session is still going here and the
 * server may already have ended it.
 */
const stateLabel = computed(() => {
    switch (timer.status.value) {
        case 'offline':
            return 'Offline';
        case 'running':
            return 'Running';
        case 'paused':
            return 'Paused';
        default:
            return 'No timer going';
    }
});

/**
 * The badge's tone. `StatusBadge` is the app's one status pill (DESIGN.md §5.8), so the timer
 * borrows a tone rather than growing a ninth colour — and it always passes its own `label`,
 * because the words are the timer's and not the task machine's.
 */
const stateTone = computed<StatusKey>(() => {
    switch (timer.status.value) {
        case 'offline':
            return 'waiting';
        case 'running':
            return 'progress';
        case 'paused':
            return 'changes';
        default:
            return 'backlog';
    }
});

/* ------------------------------------------------------------- the announcement */

const announcement = ref('');

// Only on a CHANGE of state, and never on a tick. The elapsed figure rides along on the stop
// because that is the one moment somebody wants to hear a number.
watch(
    () => timer.status.value,
    (now, before) => {
        if (now === before) {
            return;
        }

        if (now === 'idle' && (before === 'running' || before === 'paused')) {
            announcement.value = `Timer stopped. ${spokenDuration(timer.state.value?.today.counted_seconds ?? 0)} recorded today.`;

            return;
        }

        announcement.value = `Timer ${stateLabel.value.toLowerCase()}.`;
    },
);

function start(): void {
    const id = props.taskId ?? chosen.value;

    if (id !== null) {
        timer.start(id);
    }
}
</script>

<template>
    <!--
        The bar form wraps rather than scrolls, and keeps its rows tight: at 360 px it is the
        only thing between the page and the bottom of the screen, and a bar that eats a third of
        a phone is a bar people hide. The panel form stacks and can afford the room.
    -->
    <div
        :class="
            cn(
                'flex min-w-0',
                variant === 'bar' ? 'flex-wrap items-center gap-x-4 gap-y-2' : 'flex-col gap-4',
            )
        "
    >
        <!--
            The state, always as a word — the badge prints its own label, so with the tint
            removed the line still says "Paused".
        -->
        <p class="flex min-w-0 shrink-0 items-center gap-2 text-sm">
            <StatusBadge :status="stateTone" :label="stateLabel" size="sm" />
            <CloudOff v-if="timer.status.value === 'offline'" class="size-4 shrink-0" aria-hidden="true" />
        </p>

        <!--
            The live counter. `aria-hidden`, because a figure that changes every second must
            never reach a live region — the accessible name of the region below carries the
            state instead, and the total is read from the line after it.
        -->
        <p
            v-if="running"
            :class="
                cn(
                    'shrink-0 font-mono font-semibold tabular-nums',
                    variant === 'bar' ? 'text-lg' : 'text-2xl',
                )
            "
            aria-hidden="true"
        >
            {{ clock }}
        </p>

        <div v-if="running?.task" :class="cn('min-w-0', variant === 'bar' && 'hidden sm:block')">
            <p class="truncate text-sm font-medium">{{ running.task.name }}</p>
            <p v-if="running.project" class="truncate text-xs text-muted-foreground">
                {{ running.project.name }}
            </p>
        </div>

        <!-- The picker, when there is a choice to make. -->
        <div v-if="!running && taskId === null" :class="cn('min-w-0', variant === 'bar' ? 'w-56' : 'w-full')">
            <Label class="sr-only" for="timer-task">Task to track</Label>
            <NativeSelect v-if="tasks.length > 0" id="timer-task" v-model="chosen">
                <NativeSelectOption v-for="task in tasks" :key="task.id" :value="task.id">
                    {{ task.title }}{{ task.project ? ` — ${task.project}` : '' }}
                </NativeSelectOption>
            </NativeSelect>
            <p v-else class="text-sm text-muted-foreground">
                Nothing assigned to you is open, so there is nothing to time yet.
            </p>
        </div>

        <div class="flex shrink-0 flex-wrap items-center gap-2">
            <Button
                v-if="!running"
                type="button"
                size="sm"
                :disabled="timer.busy.value || (taskId === null && chosen === null)"
                @click="start"
            >
                <Play aria-hidden="true" />
                Start
            </Button>

            <Button
                v-if="running && running.state === 'running'"
                type="button"
                size="sm"
                variant="outline"
                :disabled="timer.busy.value"
                @click="timer.pause"
            >
                <Pause aria-hidden="true" />
                Pause
            </Button>

            <Button
                v-if="running && running.state === 'paused'"
                type="button"
                size="sm"
                :disabled="timer.busy.value"
                @click="timer.resume"
            >
                <Play aria-hidden="true" />
                Resume
            </Button>

            <Button
                v-if="running"
                type="button"
                size="sm"
                variant="outline"
                :disabled="timer.busy.value"
                @click="timer.stop"
            >
                <Square aria-hidden="true" />
                Stop
            </Button>

            <slot name="actions" />
        </div>

        <!-- Today, against the schedule's hours. A total and a target — never a rating. -->
        <div :class="cn('min-w-0', variant === 'bar' ? 'shrink-0' : 'w-full')">
            <p class="truncate text-sm tabular-nums">
                <span class="text-muted-foreground">Today </span>{{ todayLine }}
            </p>
            <div
                v-if="progress !== null && variant === 'panel'"
                class="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-muted"
                role="progressbar"
                :aria-valuenow="progress"
                aria-valuemin="0"
                aria-valuemax="100"
                :aria-label="`Today: ${todayLine}`"
            >
                <div class="h-full rounded-full bg-primary transition-[width]" :style="{ width: `${progress}%` }" />
            </div>
            <p
                v-if="timer.pendingSeconds.value > 0 && variant === 'panel'"
                class="mt-1 flex items-center gap-1 text-xs text-muted-foreground"
            >
                <Flag class="size-3 shrink-0" aria-hidden="true" />
                {{ formatDuration(timer.pendingSeconds.value) }} waiting for approval
            </p>
        </div>

        <p v-if="timer.status.value === 'offline'" class="w-full text-xs text-muted-foreground">
            The connection has gone. This session is being kept here and sent when it comes back
            — and if it stays away for more than
            {{ timer.state.value?.heartbeat_timeout_minutes ?? 5 }} minutes the server ends this
            entry at the last check-in it heard, so nothing is counted for a closed laptop.
        </p>

        <p v-if="running && !isThisTask" class="w-full text-xs text-muted-foreground">
            Your timer is on another task. Stop it before starting one here — only one session
            can be open at a time.
        </p>

        <!--
            The one live region. It is announced only when the state changes, and it carries a
            sentence rather than a number, so a screen reader is told what happened and is not
            read a clock.
        -->
        <p class="sr-only" role="status" aria-live="polite">{{ announcement }}</p>
    </div>
</template>
