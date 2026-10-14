<script setup lang="ts">
/**
 * Polish 080: a project's tasks as a grid of status columns (the client's reference, columns by
 * status instead of by service): To do (backlog + todo), In progress (in_progress +
 * changes_requested + waiting), Review (in_review), Complete (completed). Cancelled tasks are in
 * no column. Each card opens the task; "Add task" sits under the two columns a task may start in.
 */
import { Link } from '@inertiajs/vue3';
import { CalendarDays, Circle, CircleCheck, CirclePlay, Clock, Eye, Plus } from '@lucide/vue';
import type { Component } from 'vue';
import { computed } from 'vue';
import type { ProjectTaskRow, ProjectTasks } from '@/Components/Projects/ProjectTaskList.vue';
import type { StatusKey } from '@/Components/StatusBadge.vue';
import StatusBadge, { statusToneClass } from '@/Components/StatusBadge.vue';
import { formatDate } from '@/Components/Tasks/taskDetail';
import { formatDuration } from '@/Components/Timer/timer';
import { cn } from '@/lib/utils';

const props = defineProps<{
    tasks: ProjectTasks;
    /** Show "Add task" (the viewer may create tasks here). */
    canAdd?: boolean;
}>();

const emit = defineEmits<{ add: [status: string] }>();

interface Column {
    key: 'todo' | 'progress' | 'review' | 'complete';
    label: string;
    tone: StatusKey;
    icon: Component;
    statuses: string[];
    /** The status "Add task" starts a task in; null = no Add here. */
    birth: string | null;
}

const COLUMNS: Column[] = [
    { key: 'todo', label: 'To do', tone: 'todo', icon: Circle, statuses: ['backlog', 'todo'], birth: 'todo' },
    { key: 'progress', label: 'In progress', tone: 'progress', icon: CirclePlay, statuses: ['in_progress', 'changes_requested', 'waiting'], birth: null },
    { key: 'review', label: 'Review', tone: 'review', icon: Eye, statuses: ['in_review'], birth: null },
    { key: 'complete', label: 'Complete', tone: 'done', icon: CircleCheck, statuses: ['completed'], birth: null },
];

const columns = computed(() =>
    COLUMNS.map((column) => ({
        ...column,
        count: props.tasks.counts?.[column.key] ?? 0,
        rows: props.tasks.rows.filter((row) => row.status !== null && column.statuses.includes(row.status)),
    })),
);

const today = new Date().toISOString().slice(0, 10);

const PRIORITY: Record<string, string> = {
    urgent: 'border-destructive/40 text-destructive',
    high: 'border-status-changes-border bg-status-changes-bg text-status-changes-fg',
    medium: 'border-status-review-border bg-status-review-bg text-status-review-fg',
    low: 'border-status-done-border bg-status-done-bg text-status-done-fg',
};

function initials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join('');
}

function overdue(row: ProjectTaskRow): boolean {
    return row.is_open && row.due_date !== null && row.due_date < today;
}
</script>

<template>
    <div class="grid min-w-0 items-start gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <section
            v-for="column in columns"
            :key="column.key"
            class="flex min-w-0 flex-col gap-2 rounded-lg border bg-card p-3"
            :aria-label="`${column.label}, ${column.count} ${column.count === 1 ? 'task' : 'tasks'}`"
            :data-testid="`board-${column.key}`"
        >
            <header class="flex items-center gap-2 pb-1">
                <span :class="cn('flex size-8 shrink-0 items-center justify-center rounded-md border', statusToneClass(column.tone))" aria-hidden="true">
                    <component :is="column.icon" class="size-4" />
                </span>
                <h3 class="min-w-0 flex-1 truncate text-sm font-semibold">{{ column.label }}</h3>
                <span class="shrink-0 text-xs text-muted-foreground tabular-nums">
                    {{ column.count }} {{ column.count === 1 ? 'task' : 'tasks' }}
                </span>
            </header>

            <p v-if="!column.rows.length" class="py-4 text-center text-xs text-muted-foreground">Nothing here</p>

            <Link
                v-for="row in column.rows"
                :key="row.id"
                :href="row.href"
                class="flex min-w-0 flex-col gap-2 rounded-md border bg-background p-3 text-sm hover:border-ring focus-visible:ring-3 focus-visible:ring-ring focus-visible:outline-none"
            >
                <span class="leading-snug font-medium break-words">{{ row.title }}</span>
                <span class="flex min-w-0 flex-wrap items-center gap-1.5">
                    <StatusBadge v-if="row.tone && column.statuses.length > 1" :status="row.tone" :label="row.status_label ?? undefined" size="sm" />
                    <span
                        v-if="row.priority"
                        :class="cn('inline-flex h-5 items-center rounded-full border px-2 text-xs font-medium', PRIORITY[row.priority] ?? 'text-muted-foreground')"
                    >
                        {{ row.priority_label }}
                    </span>
                    <!-- The time spent on the task (approved tracked time), at the right of the line
                         (client request 2026-10-14). -->
                    <span
                        class="ml-auto inline-flex items-center gap-1 text-xs text-muted-foreground tabular-nums"
                        :title="`Time spent: ${formatDuration(row.tracked_seconds)}`"
                    >
                        <Clock class="size-3.5" aria-hidden="true" />
                        {{ formatDuration(row.tracked_seconds) }}
                    </span>
                </span>
                <span class="flex min-w-0 items-center gap-2 text-xs text-muted-foreground">
                    <span
                        v-if="row.due_date"
                        :class="cn('inline-flex items-center gap-1 tabular-nums', overdue(row) && 'font-medium text-destructive')"
                    >
                        <CalendarDays class="size-3.5" aria-hidden="true" />
                        Due {{ formatDate(row.due_date) }}
                    </span>
                    <span v-if="row.assignees.length" class="ml-auto flex shrink-0 -space-x-0.5" :title="row.assignees.join(', ')">
                        <span
                            v-for="(person, index) in row.assignees.slice(0, 3)"
                            :key="person + index"
                            class="flex size-6 items-center justify-center rounded-full bg-secondary text-xs tracking-tighter text-secondary-foreground ring-2 ring-background"
                            aria-hidden="true"
                        >
                            {{ initials(person) }}
                        </span>
                        <span class="sr-only">Assigned: {{ row.assignees.join(', ') }}</span>
                    </span>
                </span>
            </Link>

            <button
                v-if="canAdd && column.birth"
                type="button"
                class="mt-1 inline-flex h-9 items-center justify-center gap-1.5 rounded-md border border-dashed text-sm text-muted-foreground hover:border-ring hover:text-foreground focus-visible:ring-3 focus-visible:ring-ring focus-visible:outline-none"
                @click="emit('add', column.birth)"
            >
                <Plus class="size-4" aria-hidden="true" />
                Add task
            </button>
        </section>
    </div>
</template>
