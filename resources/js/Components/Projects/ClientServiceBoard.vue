<script lang="ts">
export interface ServiceBoardTask {
    id: number;
    title: string;
    status_label: string | null;
    tone: string | null;
    due_date: string | null;
    tracked_seconds: number;
    assignees: string[];
}

export interface ServiceBoardProject {
    id: number;
    name: string;
    type: string | null;
    status_label: string | null;
    tone: string | null;
    tracked_seconds: number;
    open_count: number;
    tasks: ServiceBoardTask[];
}

export interface ClientServiceBoardData {
    client: {
        key: string;
        id: number | null;
        label: string;
        name: string | null;
        project_count: number;
        open_count: number;
    };
    overview: {
        tracked_seconds: number;
        untasked_seconds: number;
        boxes: { key: string; name: string; seconds: number }[];
        tasks: {
            id: number;
            title: string;
            project: string;
            box: string;
            status_label: string | null;
            tone: string | null;
            done: boolean;
            tracked_seconds: number;
        }[];
        more_tasks: number;
    };
    boxes: { key: string; name: string; seconds: number; projects: ServiceBoardProject[] }[];
}
</script>

<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowRight, CalendarDays, Clock, FolderKanban, Settings2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import type { ServiceBoxSettings } from '@/Components/Projects/ServiceBoxesDialog.vue';
import ServiceBoxesDialog from '@/Components/Projects/ServiceBoxesDialog.vue';
import type { StatusKey } from '@/Components/StatusBadge.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { formatDate } from '@/Components/Tasks/taskDetail';
import { formatDuration } from '@/Components/Timer/timer';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { cn } from '@/lib/utils';

/**
 * Polish 033: a client's page on Admin → Projects (chosen from the sidebar's Projects list).
 *
 * Top: the client and an overview of where the time went — the total, each service box's share,
 * and every task with time on it, most first. Below: one box per service (the Admin's own list —
 * Development, SEO, Maintenance, Marketing, …); in each box the client's projects of that kind,
 * and under each project its open tasks. A project opens the project; a task opens the task.
 */
const props = defineProps<{
    board: ClientServiceBoardData;
    /** Present for an Admin who may edit the boxes. */
    settings: ServiceBoxSettings | null;
}>();

const editing = ref(false);

const SHOWN = 8;
const allTasks = ref(false);
const overviewTasks = computed(() => (allTasks.value ? props.board.overview.tasks : props.board.overview.tasks.slice(0, SHOWN)));

const today = new Date().toISOString().slice(0, 10);

function time(seconds: number): string {
    return seconds > 0 ? formatDuration(seconds) : '0m';
}
</script>

<template>
    <div class="flex min-w-0 flex-col gap-4">
        <!-- The client and where its time went. -->
        <Card class="flex min-w-0 flex-col gap-4 p-4">
            <div class="flex min-w-0 flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div class="flex min-w-0 flex-col gap-1">
                    <h2 class="min-w-0 truncate text-lg font-semibold">{{ board.client.label }}</h2>
                    <p class="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                        <span v-if="board.client.name && board.client.name !== board.client.label">{{ board.client.name }}</span>
                        <span>{{ board.client.project_count }} {{ board.client.project_count === 1 ? 'project' : 'projects' }}</span>
                        <span>{{ board.client.open_count }} open {{ board.client.open_count === 1 ? 'task' : 'tasks' }}</span>
                    </p>
                </div>
                <div class="flex shrink-0 flex-wrap gap-2">
                    <Button v-if="settings" variant="outline" size="sm" @click="editing = true">
                        <Settings2 aria-hidden="true" />
                        Edit boxes
                    </Button>
                    <Button v-if="board.client.id" as-child variant="outline" size="sm">
                        <Link :href="`/admin/clients/${board.client.id}`">
                            Client page
                            <ArrowRight aria-hidden="true" />
                        </Link>
                    </Button>
                </div>
            </div>

            <section class="flex min-w-0 flex-col gap-3" aria-labelledby="client-overview-heading">
                <h3 id="client-overview-heading" class="text-sm font-medium">Time spent</h3>

                <ul class="flex min-w-0 flex-wrap gap-2">
                    <li class="flex items-center gap-2 rounded-md border bg-brand-tint px-3 py-1.5 text-sm">
                        <Clock class="size-3.5 text-muted-foreground" aria-hidden="true" />
                        <span class="text-muted-foreground">Total</span>
                        <span class="font-semibold tabular-nums">{{ time(board.overview.tracked_seconds) }}</span>
                    </li>
                    <li v-for="box in board.overview.boxes" :key="box.key" class="flex items-center gap-2 rounded-md border px-3 py-1.5 text-sm">
                        <span class="text-muted-foreground">{{ box.name }}</span>
                        <span class="font-medium tabular-nums">{{ time(box.seconds) }}</span>
                    </li>
                    <li
                        v-if="board.overview.untasked_seconds > 0"
                        class="flex items-center gap-2 rounded-md border px-3 py-1.5 text-sm"
                        title="Time logged on these projects without a task"
                    >
                        <span class="text-muted-foreground">Not on a task</span>
                        <span class="font-medium tabular-nums">{{ time(board.overview.untasked_seconds) }}</span>
                    </li>
                </ul>

                <p v-if="!board.overview.tasks.length" class="text-sm text-muted-foreground">No time logged on these tasks yet.</p>
                <template v-else>
                    <ul class="flex min-w-0 flex-col divide-y rounded-md border" aria-label="Time spent per task">
                        <li v-for="task in overviewTasks" :key="task.id">
                            <Link
                                :href="`/admin/tasks/${task.id}`"
                                class="flex min-w-0 items-center gap-3 px-3 py-2 text-sm hover:bg-accent focus-visible:ring-3 focus-visible:ring-ring focus-visible:outline-none"
                            >
                                <span class="flex min-w-0 flex-1 flex-col">
                                    <span :class="cn('truncate', task.done && 'text-muted-foreground line-through')">{{ task.title }}</span>
                                    <span class="truncate text-xs text-muted-foreground">{{ task.project }} · {{ task.box }}</span>
                                </span>
                                <StatusBadge
                                    v-if="task.tone"
                                    class="hidden shrink-0 sm:inline-flex"
                                    :status="task.tone as StatusKey"
                                    :label="task.status_label ?? undefined"
                                    size="sm"
                                />
                                <span class="w-16 shrink-0 text-right font-medium tabular-nums">{{ time(task.tracked_seconds) }}</span>
                            </Link>
                        </li>
                    </ul>
                    <div class="flex flex-wrap items-center gap-3">
                        <Button
                            v-if="board.overview.tasks.length > SHOWN"
                            variant="ghost"
                            size="sm"
                            :aria-expanded="allTasks"
                            @click="allTasks = !allTasks"
                        >
                            {{ allTasks ? 'Show fewer' : `Show all ${board.overview.tasks.length} tasks` }}
                        </Button>
                        <span v-if="allTasks && board.overview.more_tasks > 0" class="text-xs text-muted-foreground">
                            and {{ board.overview.more_tasks }} more with less time
                        </span>
                    </div>
                </template>
            </section>
        </Card>

        <!-- One box per service. -->
        <div class="grid min-w-0 gap-3 md:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
            <section
                v-for="box in board.boxes"
                :key="box.key"
                class="flex min-w-0 flex-col gap-3 rounded-lg border bg-muted p-3"
                :aria-labelledby="`service-box-${box.key}`"
            >
                <header class="flex min-w-0 items-center gap-2">
                    <h3 :id="`service-box-${box.key}`" class="min-w-0 flex-1 truncate text-sm font-semibold tracking-wide uppercase">
                        {{ box.name }}
                    </h3>
                    <span class="shrink-0 text-xs text-muted-foreground tabular-nums">{{ time(box.seconds) }}</span>
                </header>

                <article
                    v-for="project in box.projects"
                    :key="project.id"
                    class="flex min-w-0 flex-col gap-2 rounded-md border bg-card p-3 text-card-foreground shadow-flat"
                >
                    <Link
                        :href="`/admin/projects/${project.id}`"
                        class="-m-1 flex min-w-0 items-start gap-2 rounded-md p-1 hover:bg-accent focus-visible:ring-3 focus-visible:ring-ring focus-visible:outline-none"
                    >
                        <FolderKanban class="mt-0.5 size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                        <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                            <span class="text-sm leading-snug font-semibold break-words">{{ project.name }}</span>
                            <span class="flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground">
                                <span v-if="project.type">{{ project.type }}</span>
                                <span class="inline-flex items-center gap-1 tabular-nums">
                                    <Clock class="size-3" aria-hidden="true" />
                                    {{ time(project.tracked_seconds) }}
                                </span>
                            </span>
                        </span>
                        <StatusBadge
                            v-if="project.tone"
                            class="shrink-0"
                            :status="project.tone as StatusKey"
                            :label="project.status_label ?? undefined"
                            size="sm"
                        />
                    </Link>

                    <p v-if="!project.tasks.length" class="text-xs text-muted-foreground">No open tasks</p>
                    <ul v-else class="flex min-w-0 flex-col gap-1" :aria-label="`Open tasks in ${project.name}`">
                        <li v-for="task in project.tasks" :key="task.id">
                            <Link
                                :href="`/admin/tasks/${task.id}`"
                                class="flex min-w-0 flex-col gap-1 rounded-md border bg-background px-2.5 py-2 text-sm hover:border-ring focus-visible:ring-3 focus-visible:ring-ring focus-visible:outline-none"
                            >
                                <span class="leading-snug break-words">{{ task.title }}</span>
                                <span class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                                    <span v-if="task.status_label">{{ task.status_label }}</span>
                                    <span
                                        v-if="task.due_date"
                                        :class="cn('inline-flex items-center gap-1 tabular-nums', task.due_date < today && 'font-medium text-destructive')"
                                    >
                                        <CalendarDays class="size-3" aria-hidden="true" />
                                        {{ formatDate(task.due_date) }}
                                    </span>
                                    <span class="ml-auto inline-flex items-center gap-1 tabular-nums">
                                        <Clock class="size-3" aria-hidden="true" />
                                        {{ time(task.tracked_seconds) }}
                                    </span>
                                </span>
                            </Link>
                        </li>
                    </ul>
                </article>
            </section>
        </div>

        <ServiceBoxesDialog v-if="settings" v-model:open="editing" :settings="settings" />
    </div>
</template>
