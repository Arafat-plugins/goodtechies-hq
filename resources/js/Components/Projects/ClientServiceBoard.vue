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
    };
    boxes: { key: string; name: string; seconds: number; projects: ServiceBoardProject[] }[];
}
</script>

<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowRight, CalendarDays, ChevronDown, Clock, FolderKanban, Settings2 } from '@lucide/vue';
import { ref } from 'vue';
import type { ServiceBoxSettings } from '@/Components/Projects/ServiceBoxesDialog.vue';
import ServiceBoxesDialog from '@/Components/Projects/ServiceBoxesDialog.vue';
import type { StatusKey } from '@/Components/StatusBadge.vue';
import StatusIconLabel from '@/Components/StatusIconLabel.vue';
import { formatDate } from '@/Components/Tasks/taskDetail';
import { formatDuration } from '@/Components/Timer/timer';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { cn } from '@/lib/utils';

/**
 * Polish 033: a client's page on Admin → Projects (chosen from the sidebar's Projects list).
 *
 * Top: the client and an overview of where the time went — the total and each service box's
 * share (polish 034: the per-task list is gone; each task shows its own time in its box).
 * Below: one box per service (the Admin's own list — Development, SEO, Maintenance,
 * Marketing, …); in each box the client's projects of that kind, and under each project its open
 * tasks. A project opens the project; a task opens the task.
 */
defineProps<{
    board: ClientServiceBoardData;
    /** Present for an Admin who may edit the boxes. */
    settings: ServiceBoxSettings | null;
}>();

const editing = ref(false);

/**
 * Polish 035: a project shows only itself until its chevron is pressed; then its open tasks.
 * Which projects are open is remembered in this browser.
 */
const OPEN_KEY = 'hq.projects.client-board.open';

function readOpen(): number[] {
    try {
        const raw = window.localStorage.getItem(OPEN_KEY);
        const list = raw === null ? [] : (JSON.parse(raw) as unknown);

        return Array.isArray(list) ? list.filter((value): value is number => typeof value === 'number') : [];
    } catch {
        return [];
    }
}

const openProjects = ref<number[]>(typeof window === 'undefined' ? [] : readOpen());

function isOpen(id: number): boolean {
    return openProjects.value.includes(id);
}

function toggle(id: number): void {
    openProjects.value = isOpen(id) ? openProjects.value.filter((value) => value !== id) : [...openProjects.value, id];

    try {
        window.localStorage.setItem(OPEN_KEY, JSON.stringify(openProjects.value.slice(-200)));
    } catch {
        // Storage off: the choice lasts for this page only.
    }
}


const today = new Date().toISOString().slice(0, 10);

function time(seconds: number): string {
    return seconds > 0 ? formatDuration(seconds) : '0m';
}
</script>

<template>
    <div class="flex min-w-0 flex-col gap-4">
        <!-- The client and its total time. Polish 036: the total sits beside the name; the per-box
             time chips are gone (each box shows its own time in its header). -->
        <Card class="flex min-w-0 flex-col gap-3 p-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex min-w-0 flex-col gap-1">
                <div class="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-2">
                    <h2 class="min-w-0 truncate text-lg font-semibold">{{ board.client.label }}</h2>
                    <span
                        class="inline-flex shrink-0 items-center gap-2 rounded-md border bg-brand-tint px-3 py-1 text-sm"
                        title="Time spent on this client's projects"
                    >
                        <Clock class="size-3.5 text-muted-foreground" aria-hidden="true" />
                        <span class="text-muted-foreground">Total</span>
                        <span class="font-semibold tabular-nums">{{ time(board.overview.tracked_seconds) }}</span>
                    </span>
                </div>
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
        </Card>

        <!-- One box per service. `items-start`: opening one project grows only its own box. -->
        <div class="grid min-w-0 items-start gap-3 md:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
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
                    <!-- Polish 035: one row — the project (opens it), its time on the right, and a
                         chevron that shows its open tasks. Collapsed by default; no hover fill. -->
                    <div class="flex min-w-0 items-start gap-2">
                        <Link
                            :href="`/admin/projects/${project.id}`"
                            class="flex min-w-0 flex-1 items-start gap-2 rounded-md focus-visible:ring-3 focus-visible:ring-ring focus-visible:outline-none"
                        >
                            <FolderKanban class="mt-0.5 size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                            <span class="flex min-w-0 flex-1 flex-col gap-1">
                                <span class="text-sm leading-snug font-semibold break-words">{{ project.name }}</span>
                                <span class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                                    <span v-if="project.type">{{ project.type }}</span>
                                    <StatusIconLabel
                                        v-if="project.tone"
                                        :status="project.tone as StatusKey"
                                        :label="project.status_label ?? undefined"
                                    />
                                </span>
                            </span>
                        </Link>
                        <span class="inline-flex shrink-0 items-center gap-1 pt-0.5 text-sm font-medium tabular-nums" :title="`Time spent on ${project.name}`">
                            <Clock class="size-3.5 text-muted-foreground" aria-hidden="true" />
                            {{ time(project.tracked_seconds) }}
                        </span>
                        <Button
                            variant="ghost"
                            size="icon-sm"
                            class="-my-1 shrink-0"
                            :aria-expanded="isOpen(project.id)"
                            :aria-controls="`project-tasks-${project.id}`"
                            :aria-label="`${isOpen(project.id) ? 'Hide' : 'Show'} the ${project.open_count} open ${project.open_count === 1 ? 'task' : 'tasks'} of ${project.name}`"
                            @click="toggle(project.id)"
                        >
                            <ChevronDown
                                :class="cn('transition-transform motion-reduce:transition-none', isOpen(project.id) && 'rotate-180')"
                                aria-hidden="true"
                            />
                        </Button>
                    </div>

                    <template v-if="isOpen(project.id)">
                        <p v-if="!project.tasks.length" :id="`project-tasks-${project.id}`" class="text-xs text-muted-foreground">No open tasks</p>
                        <ul v-else :id="`project-tasks-${project.id}`" class="flex min-w-0 flex-col gap-1" :aria-label="`Open tasks in ${project.name}`">
                            <li v-for="task in project.tasks" :key="task.id">
                                <Link
                                    :href="`/admin/tasks/${task.id}`"
                                    class="flex min-w-0 flex-col gap-1 rounded-md border bg-background px-2.5 py-2 text-sm hover:border-ring focus-visible:ring-3 focus-visible:ring-ring focus-visible:outline-none"
                                >
                                    <span class="leading-snug break-words">{{ task.title }}</span>
                                    <span class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                                        <!-- Polish 037/038: the status in its colour, as an icon + word (no capsule). -->
                                        <StatusIconLabel
                                            v-if="task.tone"
                                            :status="task.tone as StatusKey"
                                            :label="task.status_label ?? undefined"
                                        />
                                        <span v-else-if="task.status_label">{{ task.status_label }}</span>
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
                    </template>
                </article>
            </section>
        </div>

        <ServiceBoxesDialog v-if="settings" v-model:open="editing" :settings="settings" />
    </div>
</template>
