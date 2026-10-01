<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed, onMounted } from 'vue';
import RunningTimers from '@/Components/Timer/RunningTimers.vue';
import TaskTimerButton from '@/Components/Timer/TaskTimerButton.vue';
import TimerControls from '@/Components/Timer/TimerControls.vue';
import { formatDuration, useTimer } from '@/Components/Timer/timer';
import type { MyTaskTimer, RunningTaskTimer } from '@/Components/Timer/taskTimer';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';

/**
 * The timer on a task's detail screen.
 *
 * Mounted from `TaskDetailBody`, which is the SAME component on both surfaces and in both the
 * page and the drawer — so this has to decide for itself whether it exists, and it decides on
 * `auth.user.canTrackTime`: `TimeEntryPolicy::track`, resolved on the server. An Admin opening
 * the same task sees no timer, and so does an office employee; neither sees a disabled one.
 *
 * It offers no task picker, because the task is the one whose page this is. Everything else —
 * the counter, the four buttons, today against the target — is the same `TimerControls` the
 * persistent bar uses, so the two cannot end up disagreeing about what the timer is doing.
 */

const props = withDefaults(
    defineProps<{
        taskId: number;
        /** What the task has tracked so far, from `TaskResource`. */
        trackedSeconds: number;
        /** Flow F3: the reader's own open task timer here (`TaskResource.my_timer`). */
        myTimer?: MyTaskTimer | null;
        /** Flow F3: `permissions.can_track_time` — may this reader ▶ this task. */
        canTrackTask?: boolean;
        /** Flow F3, watchers only: who else is timing it. Undefined for everyone else. */
        runningTimers?: RunningTaskTimer[];
        /**
         * Brief 025: draw the task's own ▶ / ⏸ / ⏹ here. The task detail passes `false`, because
         * its `TaskTimerButton` sits in the drawer header (and the page's actions) instead.
         */
        taskButton?: boolean;
    }>(),
    { myTimer: null, canTrackTask: false, runningTimers: undefined, taskButton: true },
);

const emit = defineEmits<{
    /** A task-timer write finished — the drawer re-reads its task. */
    settled: [];
}>();

const page = usePage();
const timer = useTimer();

const canTrack = computed(() => page.props.auth.user?.canTrackTime === true);

/**
 * Flow F3: an office employee's or an Admin's ▶. A remote employee keeps the full
 * `TimerControls` above instead — that is the timer their day already runs on, and two sets of
 * buttons for one timer would be two ideas of what it is doing.
 */
const showTaskTimer = computed(
    () => props.taskButton && !canTrack.value && (props.canTrackTask || props.myTimer !== null),
);

/**
 * What this task has banked, plus the open session if it is on THIS task. The stored figure is
 * a cache of `time_entries` that `TimerService` refreshes on every stop, so between a start and
 * a stop it is the live session that is missing from it — and this is where it is added back.
 */
const tracked = computed(() => {
    const running = timer.running.value;
    const onThisTask = running !== null && running.task?.id === props.taskId;

    return props.trackedSeconds + (onThisTask ? Math.floor(timer.elapsedSeconds.value) : 0);
});

onMounted(() => {
    if (canTrack.value) {
        void timer.refresh();
    }
});
</script>

<template>
    <Card class="min-w-0 gap-4">
        <CardHeader>
            <CardTitle class="text-sm font-medium">Time</CardTitle>
            <CardDescription>Tracked against this task so far.</CardDescription>
        </CardHeader>
        <CardContent class="flex min-w-0 flex-col gap-4">
            <p class="text-2xl font-semibold tabular-nums">{{ formatDuration(tracked) }}</p>

            <TimerControls v-if="canTrack" :task-id="taskId" variant="panel" />

            <TaskTimerButton
                v-if="showTaskTimer"
                :task-id="taskId"
                :my-timer="myTimer"
                variant="panel"
                @settled="emit('settled')"
            />

            <div v-if="runningTimers && runningTimers.length > 0" class="flex min-w-0 flex-col gap-2">
                <p class="text-xs text-muted-foreground">Timing this task now</p>
                <RunningTimers :timers="runningTimers" />
            </div>
        </CardContent>
    </Card>
</template>
