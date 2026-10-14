<script setup lang="ts">
/**
 * Polish 075/080: Admin → Projects (By client, nothing chosen) as one card per project — the client's
 * image 2: a coloured folder tile, the name, the website (or "Website not added"), "N tasks" (all
 * top-level tasks), "Client: <name>" and a ⋯ menu (Open, Edit, Archive). Filtered by the page's
 * "All projects" (type) / "All clients" pickers and ordered by its sort picker.
 */
import { Link } from '@inertiajs/vue3';
import { CalendarDays, ChevronDown, ExternalLink, Folder, FolderKanban, Link2Off, ListTodo } from '@lucide/vue';
import { computed, ref } from 'vue';
import EmptyState from '@/Components/EmptyState.vue';
import type { ClientTreeRow } from '@/Components/Projects/ClientProjectsView.vue';
import { projectTile } from '@/Components/Projects/projectTile';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import type { StatusKey } from '@/Components/StatusBadge.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { formatDate } from '@/Components/Tasks/taskDetail';
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
    <ul v-else class="grid min-w-0 items-start gap-3 sm:grid-cols-2 xl:grid-cols-3" aria-label="Projects">
        <li v-for="project in cards" :key="project.id" class="min-w-0">
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
                                    <StatusBadge v-if="task.tone" :status="task.tone as StatusKey" :label="task.status_label ?? undefined" size="sm" />
                                    <span v-if="task.due_date" class="inline-flex items-center gap-1 tabular-nums">
                                        <CalendarDays class="size-3" aria-hidden="true" />
                                        {{ formatDate(task.due_date) }}
                                    </span>
                                </span>
                            </Link>
                        </li>
                    </ul>
                </div>
            </Card>
        </li>
    </ul>
</template>
