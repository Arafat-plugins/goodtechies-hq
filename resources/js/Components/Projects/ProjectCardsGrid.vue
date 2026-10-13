<script setup lang="ts">
/**
 * Polish 075: Admin → Projects (By client, nothing chosen) as one card per project — the client's
 * image 2: a coloured folder tile, the name, the website (or "Website not added"), "N tasks" (all
 * top-level tasks), "Client: <name>" and a ⋯ menu (Open, Edit, Archive). Filtered by the page's
 * "All projects" (type) / "All clients" pickers and ordered by its sort picker.
 */
import { Link } from '@inertiajs/vue3';
import { Archive, ExternalLink, Folder, FolderKanban, Link2Off, ListTodo, MoreHorizontal, Pencil, UserRound } from '@lucide/vue';
import { computed } from 'vue';
import EmptyState from '@/Components/EmptyState.vue';
import type { ClientTreeRow } from '@/Components/Projects/ClientProjectsView.vue';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';
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
    /** Polish 078: false = the ⋯ menu offers Open only (employees). */
    canManage?: boolean;
}>();

const openBase = computed(() => props.base ?? '/admin/projects');

const emit = defineEmits<{
    archive: [project: { id: number; name: string; is_archived: boolean }];
    clear: [];
}>();

/** The folder tile's colour, varied by project like the reference (status tokens only). */
const TILES = [
    'border-status-progress-border bg-status-progress-bg text-status-progress-fg',
    'border-status-done-border bg-status-done-bg text-status-done-fg',
    'border-status-changes-border bg-status-changes-bg text-status-changes-fg',
    'border-status-review-border bg-status-review-bg text-status-review-fg',
    'border-status-backlog-border bg-status-backlog-bg text-status-backlog-fg',
    'border-status-waiting-border bg-status-waiting-bg text-status-waiting-fg',
];

const cards = computed(() => {
    const rows = props.tree
        .filter((row) => props.client === '' || row.key === props.client)
        .flatMap((row) =>
            row.projects.map((project) => ({ ...project, clientName: row.name ?? 'Internal' })),
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
            <Card class="flex min-w-0 flex-col gap-4 p-4 hover:border-ring" data-testid="project-card">
                <div class="flex min-w-0 items-start gap-3">
                    <span
                        :class="cn('flex size-11 shrink-0 items-center justify-center rounded-lg border', TILES[project.id % TILES.length])"
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
                    <span class="inline-flex shrink-0 items-center gap-1.5 text-sm text-muted-foreground tabular-nums">
                        <ListTodo class="size-4" aria-hidden="true" />
                        {{ project.total }} {{ project.total === 1 ? 'task' : 'tasks' }}
                    </span>
                </div>
                <div class="flex min-w-0 items-center gap-2 text-sm">
                    <UserRound class="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                    <span class="text-muted-foreground">Client:</span>
                    <span class="min-w-0 flex-1 truncate font-medium">{{ project.clientName }}</span>
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon-sm"
                                class="shrink-0 text-muted-foreground"
                                :aria-label="`More for ${project.name}`"
                            >
                                <MoreHorizontal aria-hidden="true" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem as-child>
                                <Link :href="`${openBase}/${project.id}`" class="w-full">
                                    <FolderKanban aria-hidden="true" />
                                    Open
                                </Link>
                            </DropdownMenuItem>
                            <DropdownMenuItem v-if="canManage !== false" as-child>
                                <Link :href="`/admin/projects/${project.id}/edit`" class="w-full">
                                    <Pencil aria-hidden="true" />
                                    Edit
                                </Link>
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                v-if="canManage !== false && project.can_archive"
                                variant="destructive"
                                @select="emit('archive', { id: project.id, name: project.name, is_archived: false })"
                            >
                                <Archive aria-hidden="true" />
                                Archive
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </Card>
        </li>
    </ul>
</template>
