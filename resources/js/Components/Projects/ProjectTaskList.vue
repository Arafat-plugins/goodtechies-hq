<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowRight, CalendarDays, Clock, ListTodo } from '@lucide/vue';
import { computed } from 'vue';
import EmptyState from '@/Components/EmptyState.vue';
import type { StatusKey } from '@/Components/StatusBadge.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { formatDate } from '@/Components/Tasks/taskDetail';
import { formatDuration } from '@/Components/Timer/timer';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { cn } from '@/lib/utils';

/**
 * The tasks on a project page (client request 2026-10-06). An employee's list is the tasks on
 * this project assigned to them; an Admin's is every task on the project — the server decides
 * which through `Task::visibleTo()` (`App\Support\ProjectTaskList`). Open work first, soonest due
 * first; each row opens the task. The full Tasks list, filtered to the project, is one press away.
 */
export interface ProjectTaskRow {
    id: number;
    title: string;
    status: string | null;
    status_label: string | null;
    tone: StatusKey | null;
    is_open: boolean;
    due_date: string | null;
    /** Polish 030: approved time spent on this task. */
    tracked_seconds: number;
    assignees: string[];
    href: string;
}

export interface ProjectTasks {
    total: number;
    open: number;
    /** Polish 030: approved time spent on the whole project. */
    tracked_seconds: number;
    rows: ProjectTaskRow[];
}

const props = defineProps<{
    tasks: ProjectTasks;
    /** "Assigned to you" for an employee, "All tasks" for an Admin. */
    scopeLabel: string;
    /** The Tasks screen, filtered to this project. */
    allHref: string;
    /** Show who each task is assigned to (the Admin's list). */
    showAssignees?: boolean;
}>();

const today = new Date().toISOString().slice(0, 10);

const hidden = computed(() => Math.max(0, props.tasks.total - props.tasks.rows.length));

function overdue(row: ProjectTaskRow): boolean {
    return row.is_open && row.due_date !== null && row.due_date < today;
}
</script>

<template>
    <Card class="min-w-0 gap-3 p-6">
        <div class="flex min-w-0 flex-wrap items-center gap-2">
            <h2 class="text-sm font-medium">Tasks</h2>
            <span class="rounded-sm bg-muted px-1.5 text-xs text-muted-foreground tabular-nums">{{ tasks.total }}</span>
            <span class="text-xs text-muted-foreground">
                {{ scopeLabel }}<template v-if="tasks.total > 0"> · {{ tasks.open }} open</template>
            </span>
            <span class="inline-flex items-center gap-1 text-xs font-medium tabular-nums" title="Total time spent on this project">
                <Clock class="size-3" aria-hidden="true" />
                {{ formatDuration(tasks.tracked_seconds) }} spent
            </span>
            <Button as-child size="sm" variant="ghost" class="ml-auto">
                <Link :href="allHref">
                    Open in Tasks
                    <ArrowRight aria-hidden="true" />
                </Link>
            </Button>
        </div>

        <EmptyState v-if="tasks.total === 0" :icon="ListTodo" title="No tasks yet" />

        <ul v-else class="flex min-w-0 flex-col divide-y">
            <li v-for="row in tasks.rows" :key="row.id" class="min-w-0">
                <Link
                    :href="row.href"
                    class="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1 rounded-md px-2 py-2.5 hover:bg-accent focus-visible:ring-3 focus-visible:ring-ring focus-visible:outline-none"
                >
                    <StatusBadge v-if="row.tone" :status="row.tone" :label="row.status_label ?? undefined" size="sm" class="shrink-0" />
                    <span
                        :class="cn('min-w-0 flex-1 truncate text-sm', !row.is_open && 'text-muted-foreground line-through')"
                    >
                        {{ row.title }}
                    </span>
                    <span
                        v-if="showAssignees && row.assignees.length"
                        class="max-w-48 shrink-0 truncate text-xs text-muted-foreground"
                    >
                        {{ row.assignees.join(', ') }}
                    </span>
                    <span
                        v-if="row.tracked_seconds > 0"
                        class="inline-flex shrink-0 items-center gap-1 text-xs text-muted-foreground tabular-nums"
                        :title="`Time spent on ${row.title}`"
                    >
                        <Clock class="size-3" aria-hidden="true" />
                        {{ formatDuration(row.tracked_seconds) }}
                    </span>
                    <span
                        v-if="row.due_date"
                        :class="
                            cn(
                                'inline-flex shrink-0 items-center gap-1 text-xs tabular-nums',
                                overdue(row) ? 'font-medium text-destructive' : 'text-muted-foreground',
                            )
                        "
                    >
                        <CalendarDays class="size-3" aria-hidden="true" />
                        {{ formatDate(row.due_date) }}<span v-if="overdue(row)" class="sr-only">, overdue</span>
                    </span>
                </Link>
            </li>
        </ul>

        <p v-if="hidden > 0" class="text-xs text-muted-foreground">
            And {{ hidden }} more —
            <Link :href="allHref" class="underline underline-offset-2 hover:text-foreground">see them all in Tasks</Link>.
        </p>
    </Card>
</template>
