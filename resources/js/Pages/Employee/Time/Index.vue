<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Flag, Pencil, Plus, Timer as TimerIcon } from '@lucide/vue';
import { onMounted, ref, watch } from 'vue';
import EmptyState from '@/Components/EmptyState.vue';
import PageShell from '@/Components/PageShell.vue';
import StatCard from '@/Components/StatCard.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import TimeEntryDialog from '@/Components/Timer/TimeEntryDialog.vue';
import TimerControls from '@/Components/Timer/TimerControls.vue';
import type { TimeableTask, TimeDay, TimeEntry, TimerState } from '@/Components/Timer/timer';
import { formatDuration, formatTimeOfDay, useTimer } from '@/Components/Timer/timer';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import EmployeeLayout from '@/Layouts/EmployeeLayout.vue';

/**
 * The remote employee's Time page: their own entries, by day.
 *
 * It answers one question — *what did I work on, and is any of it wrong?* — so the flagged rows
 * are the thing it is built around. Every flag prints its reason in the words the server wrote,
 * because a row that is marked and not explained is a row nobody investigates.
 *
 * **There is no score on this page.** No rate, no ranking, no "% of target" dressed up as a
 * verdict. Part H forbids productivity scoring, and a test greps this file and the payload for
 * "score" and "productivity". The target appears exactly once, as the second half of "4h 18m /
 * 5h" — a total against a number the schedule set, which is a fact and not a judgement.
 */

const props = defineProps<{
    timer: TimerState;
    tasks: TimeableTask[];
    days: TimeDay[];
    range: { from: string; to: string };
    flagged_count: number;
}>();

const timer = useTimer();

// The page arrives with the state already in its props, so the counter paints on first frame
// rather than after a round trip. Every later change comes from the store.
timer.adopt(props.timer);

watch(() => props.timer, (state) => timer.adopt(state));

onMounted(() => {
    void timer.refresh();
});

const dialogOpen = ref(false);
const editing = ref<TimeEntry | null>(null);

function addByHand(): void {
    editing.value = null;
    dialogOpen.value = true;
}

function correct(entry: TimeEntry): void {
    editing.value = entry;
    dialogOpen.value = true;
}
</script>

<template>
    <Head title="Time" />

    <EmployeeLayout>
        <PageShell
            title="Time"
            description="Every session you have tracked, by day. Anything the system had to step in on says so, and why."
            :breadcrumb="[{ label: 'Time' }]"
        >
            <template #actions>
                <Button type="button" variant="outline" @click="addByHand">
                    <Plus aria-hidden="true" />
                    Add time by hand
                </Button>
            </template>

            <div class="flex min-w-0 flex-col gap-6">
                <!-- The timer itself, so the page you go to for your time is also where you start it. -->
                <Card class="min-w-0">
                    <CardHeader>
                        <CardTitle class="text-sm font-medium">Timer</CardTitle>
                        <CardDescription>
                            One session at a time. It checks in once a minute, and if it stops
                            checking in for more than
                            {{ props.timer.heartbeat_timeout_minutes }} minutes the entry is
                            ended at its last check-in so a closed laptop records nothing extra.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <TimerControls :tasks="tasks" variant="panel" />
                    </CardContent>
                </Card>

                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    <StatCard
                        label="Today"
                        :value="formatDuration(timer.todaySeconds.value)"
                        :sub="timer.targetSeconds.value === null
                            ? 'No daily target set on your schedule'
                            : `of ${formatDuration(timer.targetSeconds.value)} on your schedule`"
                        :icon="TimerIcon"
                    />
                    <StatCard
                        label="Waiting for approval"
                        :value="formatDuration(timer.pendingSeconds.value)"
                        sub="Added or corrected by hand, and not counted until it is signed off"
                    />
                    <StatCard
                        label="Needs a look"
                        :value="props.flagged_count"
                        sub="Entries the system stopped or paused on your behalf"
                        :icon="Flag"
                    />
                </div>

                <EmptyState
                    v-if="days.length === 0"
                    :icon="TimerIcon"
                    title="Nothing tracked in this stretch"
                    description="Start the timer on a task, or add a session by hand if the timer missed one."
                >
                    <template #action>
                        <Button type="button" variant="outline" @click="addByHand">
                            <Plus aria-hidden="true" />
                            Add time by hand
                        </Button>
                    </template>
                </EmptyState>

                <!-- Entries by day. One card per day, its own total on the heading. -->
                <Card v-for="day in days" :key="day.date" class="min-w-0 gap-4">
                    <CardHeader class="flex flex-wrap items-baseline justify-between gap-2">
                        <CardTitle class="text-sm font-medium">{{ day.label }}</CardTitle>
                        <p class="text-sm tabular-nums text-muted-foreground">
                            {{ formatDuration(day.counted_seconds) }}
                            <template v-if="day.pending_seconds > 0">
                                · {{ formatDuration(day.pending_seconds) }} waiting for approval
                            </template>
                        </p>
                    </CardHeader>
                    <CardContent class="flex min-w-0 flex-col gap-3">
                        <div
                            v-for="entry in day.entries"
                            :key="entry.id"
                            class="flex min-w-0 flex-col gap-2 rounded-md border p-3 shadow-flat"
                        >
                            <div class="flex min-w-0 flex-wrap items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium">
                                        {{ entry.task?.name ?? 'Task removed' }}
                                    </p>
                                    <p class="truncate text-xs text-muted-foreground">
                                        <span v-if="entry.project">{{ entry.project.name }} · </span>
                                        {{ formatTimeOfDay(entry.started_at) }}–{{ formatTimeOfDay(entry.ended_at) }}
                                    </p>
                                </div>

                                <div class="flex shrink-0 items-center gap-2">
                                    <p class="text-sm font-semibold tabular-nums">
                                        {{ formatDuration(entry.duration_seconds ?? entry.elapsed_seconds) }}
                                    </p>
                                    <Button
                                        v-if="entry.permissions.can_update"
                                        type="button"
                                        size="sm"
                                        variant="ghost"
                                        :aria-label="`Correct the entry on ${entry.task?.name ?? 'this task'}`"
                                        @click="correct(entry)"
                                    >
                                        <Pencil aria-hidden="true" />
                                        Correct
                                    </Button>
                                </div>
                            </div>

                            <!--
                                Every state on this row is a word. `state_label`,
                                `entry_type_label` and `approval_label` are the server's, so
                                nothing here is a tint somebody has to interpret.
                            -->
                            <div class="flex min-w-0 flex-wrap items-center gap-2">
                                <StatusBadge
                                    v-if="entry.state !== 'stopped'"
                                    :status="entry.state === 'running' ? 'progress' : 'changes'"
                                    :label="entry.state_label"
                                    size="sm"
                                />
                                <StatusBadge
                                    v-if="entry.entry_type === 'manual'"
                                    status="todo"
                                    :label="entry.entry_type_label"
                                    size="sm"
                                />
                                <StatusBadge
                                    v-if="entry.approval_label"
                                    status="waiting"
                                    :label="entry.approval_label"
                                    size="sm"
                                />
                                <StatusBadge
                                    v-if="entry.is_flagged"
                                    status="review"
                                    label="Needs a look"
                                    size="sm"
                                />
                                <span v-if="entry.paused_seconds > 0" class="text-xs text-muted-foreground">
                                    {{ formatDuration(entry.paused_seconds) }} paused
                                </span>
                            </div>

                            <!-- The flag's reason, in the server's words. This is the point of the flag. -->
                            <p
                                v-if="entry.is_flagged && entry.flag_reason"
                                class="rounded-md bg-status-review-bg px-3 py-2 text-xs text-status-review-fg"
                            >
                                {{ entry.flag_reason }}
                            </p>

                            <p v-if="entry.reason" class="text-xs text-muted-foreground">
                                <span class="font-medium">{{ entry.edited_at ? 'Corrected' : 'Reason' }}:</span>
                                {{ entry.reason }}
                            </p>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </PageShell>

        <TimeEntryDialog
            v-model:open="dialogOpen"
            :entry="editing"
            :tasks="tasks"
            :requires-approval="props.timer.manual_time_requires_approval"
        />
    </EmployeeLayout>
</template>
