<script lang="ts">
import {
    CalendarClock,
    CircleAlert,
    CircleCheck,
    ClipboardCheck,
    Hourglass,
    ListTodo,
    LoaderCircle,
} from '@lucide/vue';
import type { Component } from 'vue';
import type { Task } from '@/Components/Tasks/TaskList.vue';
import type { TaskSurface } from '@/Components/Tasks/taskDetail';

/**
 * The My Tasks screen: seven counts over the tasks in whichever one you asked for.
 *
 * It is deliberately NOT the Tasks List with a filter pre-applied. The List answers "show me
 * the work" and wears a chip bar, a group-by toggle and a column menu for narrowing it down;
 * this answers "what is on my plate", which has exactly seven shapes and needs no controls at
 * all. Admin and Employee render the same component: the backend has already decided whose
 * tasks these are, and "mine" means the same thing on both surfaces.
 *
 * Nothing here computes a count. Every number is a server-side COUNT over
 * `Task::visibleTo()` narrowed to this person (`TaskService::bucketCards()`), so it is the
 * whole bucket and not the page of it this screen happens to be showing.
 */

/** One bucket as the server sends it: what it is called, how many, and where it lives. */
export interface MyTaskBucket {
    key: string;
    label: string;
    count: number;
    /** Every count leads somewhere. This is where. */
    href: string;
}

export interface MyTasksPayload {
    buckets: MyTaskBucket[];
    /** Which bucket's tasks are listed below — a `TaskBucket` value. */
    bucket: string;
    tasks: Task[];
    /** How many rows the list is capped at, so it can say so when it hits the cap. */
    limit: number;
    today: string;
}

/**
 * The icon each bucket wears.
 *
 * Icons are components, so this is the one part of a bucket that cannot come from PHP. It is
 * a lookup rather than a rule: an unknown key falls back to the neutral one instead of
 * rendering nothing, the same way `tagTone()` handles a colour it does not know.
 */
const BUCKET_ICON: Record<string, Component> = {
    open: ListTodo,
    due_today: CalendarClock,
    overdue: CircleAlert,
    in_progress: LoaderCircle,
    waiting: Hourglass,
    in_review: ClipboardCheck,
    completed: CircleCheck,
};

/**
 * What the sub-line under each count says, at zero and above it.
 *
 * Zero is an answer, not an empty state. "0 overdue" is the best news on this page and it
 * reads like it — an `EmptyState` belongs where a LIST is empty, which on this screen is the
 * table below, not a card whose number happens to be nought.
 *
 * The sub-line is also the second encoding the overdue card needs: the number is emphasised,
 * but what makes it overdue is the word beside it, not the emphasis (DESIGN.md §6 rule 6).
 */
const BUCKET_SUB: Record<string, { zero: string; some: string }> = {
    open: { zero: 'Your plate is clear', some: 'Open and assigned to you' },
    due_today: { zero: 'Nothing due today', some: 'Due before the day is out' },
    overdue: { zero: 'Nothing is late', some: 'Past their due date' },
    in_progress: { zero: 'Nothing started yet', some: 'Being worked on' },
    waiting: { zero: 'Nothing is blocked', some: 'Blocked or waiting on somebody' },
    in_review: { zero: 'Nothing with a reviewer', some: 'With a reviewer' },
    completed: { zero: 'None finished yet', some: 'Finished' },
};

/**
 * Both helpers are exported because the employee dashboard shows five of these same buckets:
 * the same bucket must wear the same icon and say the same thing on both screens, or the
 * dashboard becomes a second, drifting description of the page it links to.
 */
export function bucketIcon(key: string): Component {
    return BUCKET_ICON[key] ?? ListTodo;
}

export function bucketSubline(key: string, count: number): string {
    const copy = BUCKET_SUB[key];

    if (!copy) {
        return '';
    }

    return count === 0 ? copy.zero : copy.some;
}
</script>

<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import DataTable from '@/Components/DataTable/DataTable.vue';
import StatCard from '@/Components/StatCard.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { formatTaskDate, tagTone, taskColumns } from '@/Components/Tasks/TaskList.vue';
import { Card } from '@/Components/ui/card';
import { useNavigationPending } from '@/lib/useNavigationPending';
import { cn } from '@/lib/utils';

const props = defineProps<
    MyTasksPayload & {
        /** Which surface's task pages a row opens. Never what decides what this person sees. */
        surface: TaskSurface;
    }
>();

/**
 * The five columns that answer "what is this and when is it due". No Assignee column: every
 * row here is already this person's, which is the whole premise of the screen.
 */
const columns = computed(() => taskColumns(['title', 'project', 'status', 'due_date', 'tags']));

const current = computed(() => props.buckets.find((bucket) => bucket.key === props.bucket) ?? null);

/** The heading over the list, so the bucket is named in words and not only by a ring. */
const heading = computed(() => current.value?.label ?? 'My tasks');

/** True when the list is a capped view of a bigger bucket — the one case the two disagree. */
const capped = computed(() => (current.value?.count ?? 0) > props.tasks.length);

/** A row opens the task on the surface the reader is already on. */
function openTask(task: Task): void {
    router.visit(`/${props.surface}/tasks/${task.id}`);
}

const loading = useNavigationPending();
</script>

<template>
    <div class="flex min-w-0 flex-col gap-6">
        <!--
            A list of links to this same page, so it is navigation and says so. `aria-current`
            marks the one being shown; the ring is the sighted half of the same statement, and
            the heading under the strip is the third — the bucket is never identified by
            colour, or by a ring, alone.
        -->
        <nav aria-label="What is on my plate">
            <ul class="grid min-w-0 grid-cols-2 gap-4 md:grid-cols-4 xl:grid-cols-7">
                <li v-for="bucket in buckets" :key="bucket.key" class="min-w-0">
                    <StatCard
                        :label="bucket.label"
                        :value="bucket.count"
                        :sub="bucketSubline(bucket.key, bucket.count)"
                        :icon="bucketIcon(bucket.key)"
                        :href="bucket.href"
                        size="compact"
                        :aria-current="bucket.key === props.bucket ? 'page' : undefined"
                        :class="cn(bucket.key === props.bucket && 'rounded-xl ring-2 ring-primary')"
                    />
                </li>
            </ul>
        </nav>

        <Card class="min-w-0 gap-4 p-4 shadow-xs sm:p-6">
            <div class="flex min-w-0 flex-col gap-1">
                <h2 class="text-base font-semibold tracking-tight">{{ heading }}</h2>
                <p v-if="capped" class="text-xs text-muted-foreground">
                    Showing the first <span class="tabular-nums">{{ limit }}</span> of
                    <span class="tabular-nums">{{ current?.count }}</span
                    >. The
                    <Link :href="`/${surface}/tasks`" class="underline underline-offset-2">Tasks list</Link>
                    holds the rest.
                </p>
            </div>

            <DataTable
                :id="`${surface}-my-tasks`"
                :columns="columns"
                :rows="tasks"
                :loading="loading"
                :row-label="(task) => task.title"
                noun="task"
                row-clickable
                :empty-icon="bucketIcon(bucket)"
                :empty-title="`Nothing in ${heading.toLowerCase()}`"
                empty-description="Pick another bucket above to see the rest of your work."
                @row-click="openTask"
            >
                <template #cell-title="{ row }">
                    <span class="font-medium break-words">{{ row.title }}</span>
                </template>

                <!-- The tone is the server's answer; this screen only paints it. -->
                <template #cell-status="{ row }">
                    <StatusBadge :status="row.status_tone" :label="row.status_label" />
                </template>

                <!--
                    The same treatment the Tasks List gives a late deadline: the word carries
                    the meaning and the colour carries the emphasis, because red alone says
                    nothing to a screen reader and nothing in greyscale. `is_overdue` is the
                    server's, never recomputed here.
                -->
                <template #cell-due_date="{ row }">
                    <span :class="cn('flex flex-col', row.is_overdue && 'text-destructive')">
                        <span>{{ formatTaskDate(row.due_date) }}</span>
                        <span v-if="row.is_overdue" class="text-xs font-medium">Overdue</span>
                    </span>
                </template>

                <template #cell-tags="{ row }">
                    <span v-if="row.tags.length > 0" class="flex flex-wrap items-center gap-1">
                        <StatusBadge
                            v-for="tag in row.tags"
                            :key="tag.id"
                            :status="tagTone(tag.colour)"
                            :label="tag.name"
                        />
                    </span>
                    <span v-else class="text-muted-foreground">—</span>
                </template>
            </DataTable>
        </Card>
    </div>
</template>
