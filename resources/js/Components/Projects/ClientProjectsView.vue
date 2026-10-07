<script lang="ts">
export interface ClientTreeProject {
    id: number;
    name: string;
    domain: string | null;
    status: string | null;
    status_label: string | null;
    tone: string | null;
    open: number;
    total: number;
}

export interface ClientTreeRow {
    key: string;
    id: number | null;
    /** The nickname when there is one, else the client's name; "Internal" for no client. */
    label: string;
    name: string | null;
    open: number;
    total: number;
    projects: ClientTreeProject[];
}

export interface BoardCard {
    id: number;
    title: string;
    status_label: string | null;
    tone: string | null;
    priority: string | null;
    priority_label: string | null;
    due_date: string | null;
    tracked_seconds: number;
    assignees: string[];
}

export interface ProjectBoard {
    project: {
        id: number;
        name: string;
        domain: string | null;
        client: string | null;
        type: string | null;
        status_label: string | null;
        tone: string | null;
        deadline: string | null;
        tracked_seconds: number;
    };
    columns: { key: string; label: string; count: number; cards: BoardCard[] }[];
}
</script>

<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ArrowRight, CalendarDays, Clock, FolderKanban, KanbanSquare } from '@lucide/vue';
import { ref } from 'vue';
import EmptyState from '@/Components/EmptyState.vue';
import type { StatusKey } from '@/Components/StatusBadge.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { formatDate } from '@/Components/Tasks/taskDetail';
import { formatDuration } from '@/Components/Timer/timer';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { cn } from '@/lib/utils';

/**
 * Polish 030: Admin → Projects by client (the client's ClickUp picture).
 *
 * Polish 031: the clients → projects list moved to the sidebar, under Projects. This page shows
 * the chosen project's tasks as a board (To do, In progress, Review, Done), or — with none
 * chosen — every client's projects with their task counts.
 * Choosing a project is a partial reload of `projectBoard` only, with the id in the URL so a
 * board is a link somebody can send.
 */
const props = defineProps<{
    tree: ClientTreeRow[];
    board: ProjectBoard | null;
    selectedId: number | null;
}>();

const loading = ref(false);

function choose(projectId: number): void {
    router.get(
        '/admin/projects',
        { layout: 'clients', project: projectId },
        {
            only: ['projectBoard'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => {
                loading.value = true;
            },
            onFinish: () => {
                loading.value = false;
            },
        },
    );
}

const today = new Date().toISOString().slice(0, 10);

function initials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join('');
}

const PRIORITY_CLASS: Record<string, string> = {
    urgent: 'text-destructive',
    high: 'text-status-waiting',
};
</script>

<template>
    <!-- Polish 031: the client list lives in the sidebar, under Projects; this page is the board. -->
    <div class="flex min-w-0 flex-col gap-4">
        <!-- Right: the chosen project's board. -->
        <div :class="cn('flex min-w-0 flex-col gap-4 transition-opacity', loading && 'opacity-60')" :aria-busy="loading || undefined">
            <template v-if="board">
                <Card class="flex min-w-0 flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex min-w-0 flex-col gap-1">
                        <div class="flex min-w-0 flex-wrap items-center gap-2">
                            <h2 class="min-w-0 truncate text-base font-semibold">{{ board.project.name }}</h2>
                            <StatusBadge
                                v-if="board.project.tone"
                                :status="board.project.tone as StatusKey"
                                :label="board.project.status_label ?? undefined"
                                size="sm"
                            />
                        </div>
                        <p class="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                            <span>{{ board.project.client ?? 'Internal' }}</span>
                            <span v-if="board.project.type">{{ board.project.type }}</span>
                            <span v-if="board.project.deadline" class="inline-flex items-center gap-1">
                                <CalendarDays class="size-3" aria-hidden="true" />
                                {{ formatDate(board.project.deadline) }}
                            </span>
                            <span class="inline-flex items-center gap-1 font-medium text-foreground tabular-nums">
                                <Clock class="size-3" aria-hidden="true" />
                                {{ formatDuration(board.project.tracked_seconds) }} spent
                            </span>
                        </p>
                    </div>
                    <div class="flex shrink-0 flex-wrap gap-2">
                        <Button as-child variant="outline" size="sm">
                            <Link :href="`/admin/tasks/board?project_id=${board.project.id}`">
                                <KanbanSquare aria-hidden="true" />
                                Full board
                            </Link>
                        </Button>
                        <Button as-child size="sm">
                            <Link :href="`/admin/projects/${board.project.id}`">
                                Open project
                                <ArrowRight aria-hidden="true" />
                            </Link>
                        </Button>
                    </div>
                </Card>

                <div class="-mx-4 overflow-x-auto px-4 pb-2 sm:mx-0 sm:px-0">
                    <div class="grid min-w-[44rem] grid-cols-4 gap-3">
                        <section
                            v-for="column in board.columns"
                            :key="column.key"
                            class="flex min-w-0 flex-col gap-2 rounded-lg border bg-muted p-2"
                            :aria-label="`${column.label}, ${column.count} tasks`"
                        >
                            <header class="flex items-center gap-2 px-1 pt-1">
                                <span class="text-xs font-semibold tracking-wide uppercase">{{ column.label }}</span>
                                <span class="text-xs text-muted-foreground tabular-nums">{{ column.count }}</span>
                            </header>

                            <p v-if="!column.cards.length" class="px-1 py-4 text-center text-xs text-muted-foreground">Nothing here</p>

                            <Link
                                v-for="card in column.cards"
                                :key="card.id"
                                :href="`/admin/tasks/${card.id}`"
                                class="flex min-w-0 flex-col gap-2 rounded-md border bg-card p-2.5 text-card-foreground shadow-flat hover:border-ring focus-visible:ring-3 focus-visible:ring-ring focus-visible:outline-none"
                            >
                                <span class="text-sm leading-snug break-words">{{ card.title }}</span>
                                <span class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                                    <span
                                        v-if="card.priority && card.priority !== 'medium' && card.priority !== 'low'"
                                        :class="cn('font-medium', PRIORITY_CLASS[card.priority])"
                                    >
                                        {{ card.priority_label }}
                                    </span>
                                    <span
                                        v-if="card.due_date"
                                        :class="cn('inline-flex items-center gap-1 tabular-nums', column.key !== 'done' && card.due_date < today && 'font-medium text-destructive')"
                                    >
                                        <CalendarDays class="size-3" aria-hidden="true" />
                                        {{ formatDate(card.due_date) }}
                                    </span>
                                    <span v-if="card.tracked_seconds > 0" class="inline-flex items-center gap-1 tabular-nums">
                                        <Clock class="size-3" aria-hidden="true" />
                                        {{ formatDuration(card.tracked_seconds) }}
                                    </span>
                                    <span v-if="card.assignees.length" class="ml-auto flex -space-x-1.5" :title="card.assignees.join(', ')">
                                        <span
                                            v-for="person in card.assignees.slice(0, 3)"
                                            :key="person"
                                            class="flex size-5 items-center justify-center rounded-full border border-card bg-secondary text-xs font-medium text-secondary-foreground"
                                            aria-hidden="true"
                                        >
                                            {{ initials(person).charAt(0) }}
                                        </span>
                                        <span class="sr-only">{{ card.assignees.join(', ') }}</span>
                                    </span>
                                </span>
                            </Link>
                        </section>
                    </div>
                </div>
            </template>

            <!-- No project chosen: every client and its projects, with task counts. -->
            <template v-else>
                <Card v-if="!tree.length" class="p-6">
                    <EmptyState :icon="FolderKanban" title="No projects yet" />
                </Card>
                <div v-else class="grid min-w-0 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    <Card v-for="row in tree" :key="row.key" class="flex min-w-0 flex-col gap-2 p-4">
                        <div class="flex min-w-0 items-center gap-2">
                            <h2 class="min-w-0 flex-1 truncate text-sm font-semibold" :title="row.name ?? undefined">{{ row.label }}</h2>
                            <span class="shrink-0 text-xs text-muted-foreground tabular-nums">{{ row.total }} tasks</span>
                        </div>
                        <ul class="flex min-w-0 flex-col">
                            <li v-for="project in row.projects" :key="project.id">
                                <button
                                    type="button"
                                    class="flex w-full min-w-0 items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm hover:bg-accent focus-visible:ring-3 focus-visible:ring-ring focus-visible:outline-none"
                                    @click="choose(project.id)"
                                >
                                    <FolderKanban class="size-3.5 shrink-0 text-muted-foreground" aria-hidden="true" />
                                    <span class="min-w-0 flex-1 truncate">{{ project.name }}</span>
                                    <span class="shrink-0 text-xs text-muted-foreground tabular-nums" :title="`${project.total} tasks, ${project.open} open`">
                                        {{ project.total }}
                                    </span>
                                </button>
                            </li>
                        </ul>
                    </Card>
                </div>
            </template>
        </div>
    </div>
</template>
