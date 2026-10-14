<script setup lang="ts">
/**
 * Polish 075/080: Admin → Projects (By client, nothing chosen) as one card per project — the client's
 * image 2: a coloured folder tile, the name, the website (or "Website not added"), "N tasks" (all
 * top-level tasks), "Client: <name>" and a ⋯ menu (Open, Edit, Archive). Filtered by the page's
 * "All projects" (type) / "All clients" pickers and ordered by its sort picker.
 */
import { Link } from '@inertiajs/vue3';
import { CalendarDays, ChevronDown, Clock, ExternalLink, Folder, FolderKanban, Link2Off, ListTodo } from '@lucide/vue';
import { useMediaQuery } from '@vueuse/core';
import { computed, ref } from 'vue';
import { initials } from '@/Components/Clients/clientTasks';
import EmptyState from '@/Components/EmptyState.vue';
import { personTone } from '@/Components/Messages/people';
import type { ClientTreeRow } from '@/Components/Projects/ClientProjectsView.vue';
import { projectTile } from '@/Components/Projects/projectTile';
import { Avatar, AvatarFallback } from '@/Components/ui/avatar';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/Components/ui/tooltip';
import type { StatusKey } from '@/Components/StatusBadge.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { formatDate } from '@/Components/Tasks/taskDetail';
import { formatDuration } from '@/Components/Timer/timer';
import { cn } from '@/lib/utils';

export type ProjectSort = 'newest' | 'oldest' | 'name';

const props = defineProps<{
    tree: ClientTreeRow[];
    /** '' = all types. */
    type: string;
    /** '' = all clients; else the tree row key (c<ID> or internal). */
    client: string;
    sort: ProjectSort;
    /** Polish 078: where a project opens (the employee's own project page). */
    base?: string;
    /** Polish 078: false = employees (no manage actions; the ⋯ menu itself went in 081). */
    canManage?: boolean;
}>();

const openBase = computed(() => props.base ?? '/admin/projects');

/** Polish 081: which cards show their tasks (collapsed by default). */
const openIds = ref<Set<number>>(new Set());

function isOpen(id: number): boolean {
    return openIds.value.has(id);
}

function toggle(id: number): void {
    const next = new Set(openIds.value);

    if (next.has(id)) {
        next.delete(id);
    } else {
        next.add(id);
    }

    openIds.value = next;
}

const emit = defineEmits<{
    archive: [project: { id: number; name: string; is_archived: boolean }];
    clear: [];
}>();

const cards = computed(() => {
    const rows = props.tree
        .filter((row) => props.client === '' || row.key === props.client)
        .flatMap((row) =>
            row.projects.map((project) => ({ ...project })),
        )
        .filter((project) => props.type === '' || project.type === props.type);

    return rows.sort((a, b) => {
        if (props.sort === 'name') {
            return a.name.localeCompare(b.name);
        }

        const diff = (a.created_at ?? '').localeCompare(b.created_at ?? '') || a.id - b.id;

        return props.sort === 'newest' ? -diff : diff;
    });
});

/**
 * The cards in independent columns (masonry), so opening one card's tasks only pushes the cards
 * below it in its own column instead of leaving an empty gap across the whole row. Cards are dealt
 * round-robin, so the reading order across the top stays the sort order. Columns match the old
 * grid: 1 below sm, 2 from sm, 3 from xl.
 */
const isSm = useMediaQuery('(min-width: 40rem)');
const isXl = useMediaQuery('(min-width: 80rem)');
const columnCount = computed(() => (isXl.value ? 3 : isSm.value ? 2 : 1));
const columns = computed(() => {
    const out: (typeof cards.value)[] = Array.from({ length: columnCount.value }, () => []);

    cards.value.forEach((project, index) => out[index % columnCount.value].push(project));

    return out;
});

/** All of a project's task times added up (each task's approved tracked time). */
function totalSeconds(project: { tasks?: { tracked_seconds?: number }[] }): number {
    return (project.tasks ?? []).reduce((sum, task) => sum + (task.tracked_seconds ?? 0), 0);
}

const total = computed(() => props.tree.reduce((sum, row) => sum + row.projects.length, 0));

function href(domain: string): string {
    return /^https?:\/\//i.test(domain) ? domain : `https://${domain}`;
}

function bare(domain: string): string {
    return domain.replace(/^https?:\/\//i, '').replace(/\/$/, '');
}
</script>

<template>
    <Card v-if="!total" class="p-6">
        <EmptyState :icon="FolderKanban" title="No projects yet" />
    </Card>
    <Card v-else-if="!cards.length" class="flex flex-col items-center gap-3 p-6">
        <EmptyState :icon="FolderKanban" title="No projects match these filters" />
        <Button type="button" variant="outline" size="sm" @click="emit('clear')">Clear filters</Button>
    </Card>
    <div v-else class="grid min-w-0 items-start gap-3 sm:grid-cols-2 xl:grid-cols-3">
        <ul v-for="(column, columnIndex) in columns" :key="columnIndex" class="flex min-w-0 flex-col gap-3" :aria-label="columnIndex === 0 ? 'Projects' : undefined">
            <li v-for="project in column" :key="project.id" class="min-w-0">
                <Card class="flex min-w-0 flex-col gap-0 p-4 hover:border-ring" data-testid="project-card">
                    <div class="flex min-w-0 items-start gap-3">
                        <span
                            :class="cn('flex size-11 shrink-0 items-center justify-center rounded-lg border', projectTile(project.id))"
                            aria-hidden="true"
                        >
                            <Folder class="size-5" />
                        </span>
                        <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                            <Link
                                :href="`${openBase}/${project.id}`"
                                class="truncate text-base font-semibold hover:underline focus-visible:ring-3 focus-visible:ring-ring focus-visible:outline-none"
                                :title="project.name"
                            >
                                {{ project.name }}
                            </Link>
                            <a
                                v-if="project.domain"
                                :href="href(project.domain)"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex min-w-0 items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground hover:underline"
                            >
                                <span class="truncate">{{ bare(project.domain) }}</span>
                                <ExternalLink class="size-3.5 shrink-0 text-status-progress-fg" aria-hidden="true" />
                                <span class="sr-only">(opens in a new tab)</span>
                            </a>
                            <span v-else class="inline-flex items-center gap-1.5 text-sm text-muted-foreground">
                                Website not added
                                <Link2Off class="size-3.5" aria-hidden="true" />
                            </span>
                        </div>
                        <!-- Polish 081: "N tasks" opens the tasks inside the card (like the old project
                             dropdown), not as a popup; the ⋯ menu is gone. -->
                        <!-- The time spent on all of the project's tasks (the rows' times added up), under "N tasks". -->
                        <div class="flex shrink-0 flex-col items-end gap-0.5">
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                class="h-7 shrink-0 gap-1.5 px-2 text-sm font-normal text-muted-foreground tabular-nums"
                                :aria-expanded="isOpen(project.id)"
                                :aria-controls="`project-tasks-${project.id}`"
                                @click="toggle(project.id)"
                            >
                                <ListTodo class="size-4" aria-hidden="true" />
                                {{ project.total }} {{ project.total === 1 ? 'task' : 'tasks' }}
                                <ChevronDown :class="cn('size-3.5 transition-transform', isOpen(project.id) && 'rotate-180')" aria-hidden="true" />
                            </Button>
                            <span
                                class="inline-flex items-center gap-1 px-2 text-xs text-muted-foreground tabular-nums"
                                :title="`Total time spent on this project's tasks: ${formatDuration(totalSeconds(project))}`"
                            >
                                <Clock class="size-3" aria-hidden="true" />
                                {{ formatDuration(totalSeconds(project)) }}
                            </span>
                        </div>
                    </div>
                    <div v-if="isOpen(project.id)" :id="`project-tasks-${project.id}`" class="mt-3 border-t pt-3">
                        <p v-if="!project.tasks?.length" class="text-xs text-muted-foreground">No tasks yet</p>
                        <ul v-else class="flex min-w-0 flex-col gap-2" :aria-label="`Tasks in ${project.name}`">
                            <li v-for="task in project.tasks" :key="task.id">
                                <Link
                                    :href="task.href"
                                    class="flex min-w-0 flex-col gap-1.5 rounded-md border bg-background px-2.5 py-2.5 text-sm hover:border-ring focus-visible:ring-3 focus-visible:ring-ring focus-visible:outline-none"
                                >
                                    <span class="leading-snug break-words">{{ task.title }}</span>
                                    <span class="flex min-w-0 flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                        <span
                                            v-if="task.assignees?.length"
                                            class="flex shrink-0 items-center -space-x-0.5"
                                            role="img"
                                            :aria-label="`Assigned: ${task.assignees.map((person) => person.name).join(', ')}`"
                                        >
                                            <TooltipProvider>
                                                <Tooltip v-for="person in task.assignees.slice(0, 4)" :key="person.id">
                                                    <TooltipTrigger as-child>
                                                        <Avatar class="size-6 ring-2 ring-background">
                                                            <AvatarFallback :class="cn('text-xs tracking-tighter', personTone(person.id).avatar)" aria-hidden="true">
                                                                {{ initials(person.name) }}
                                                            </AvatarFallback>
                                                        </Avatar>
                                                    </TooltipTrigger>
                                                    <TooltipContent>{{ person.name }}</TooltipContent>
                                                </Tooltip>
                                                <Tooltip v-if="task.assignees.length > 4">
                                                    <TooltipTrigger as-child>
                                                        <span class="inline-flex size-6 items-center justify-center rounded-full bg-muted text-xs ring-2 ring-background" aria-hidden="true">
                                                            +{{ task.assignees.length - 4 }}
                                                        </span>
                                                    </TooltipTrigger>
                                                    <TooltipContent>{{ task.assignees.slice(4).map((person) => person.name).join(', ') }}</TooltipContent>
                                                </Tooltip>
                                            </TooltipProvider>
                                        </span>
                                        <StatusBadge v-if="task.tone" :status="task.tone as StatusKey" :label="task.status_label ?? undefined" size="sm" />
                                        <span v-if="task.due_date" class="inline-flex items-center gap-1 tabular-nums">
                                            <CalendarDays class="size-3" aria-hidden="true" />
                                            {{ formatDate(task.due_date) }}
                                        </span>
                                        <span class="ml-auto inline-flex items-center gap-1 tabular-nums" :title="`Time spent: ${formatDuration(task.tracked_seconds ?? 0)}`">
                                            <Clock class="size-3" aria-hidden="true" />
                                            {{ formatDuration(task.tracked_seconds ?? 0) }}
                                        </span>
                                    </span>
                                </Link>
                            </li>
                        </ul>
                    </div>
                </Card>
            </li>
        </ul>
    </div>
</template>
