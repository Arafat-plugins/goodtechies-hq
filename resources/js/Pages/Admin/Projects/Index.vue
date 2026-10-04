<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Archive, ArchiveRestore, FolderKanban, Pencil, Plus, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import DataTable from '@/Components/DataTable/DataTable.vue';
import type { ColumnDef } from '@/Components/DataTable/types';
import type { FilterDef } from '@/Components/FilterBar.vue';
import FilterBar from '@/Components/FilterBar.vue';
import PageShell from '@/Components/PageShell.vue';
import type { Paginated } from '@/Components/Pagination.vue';
import DeleteProjectDialog, { canForceDelete } from '@/Components/Projects/DeleteProjectDialog.vue';
import { moneyLine } from '@/Components/Projects/FinanceCard.vue';
import type { NamedRef, Option, Project } from '@/Components/Projects/ProjectForm.vue';
import ProjectWorkingNow from '@/Components/Projects/ProjectWorkingNow.vue';
import { useLiveTaskProps } from '@/Components/Realtime/reload';
import StatusPill, { toneForProjectStatus } from '@/Components/StatusPill.vue';
import { workingNowPing, type WorkingNowRow } from '@/Components/Timer/taskTimer';
import { Button } from '@/Components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import { DropdownMenuItem } from '@/Components/ui/dropdown-menu';
import { ToggleGroup, ToggleGroupItem } from '@/Components/ui/toggle-group';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useMenuDialog } from '@/lib/menuFocus';
import { pushQuery, resetQuery } from '@/lib/tableState';
import { useNavigationPending } from '@/lib/useNavigationPending';
import { cn } from '@/lib/utils';

defineOptions({ layout: AdminLayout });

interface Filters {
    search: string | null;
    client_id: number | string | null;
    project_type: string | null;
    status: string | null;
    pm_id: number | string | null;
    archived: boolean;
}

const props = defineProps<{
    projects: Paginated<Project>;
    filters: Filters;
    clients: NamedRef[];
    projectManagers: NamedRef[];
    projectTypes: Option[];
    statuses: Option[];
    priorities: Option[];
    billingTypes: Option[];
    billingFrequencies: Option[];
    /** Open task timers by project id (flow F3) — absent for anybody without `watchLive`. */
    workingNowByProject?: Record<number, WorkingNowRow[]>;
}>();

/**
 * "Working now" follows the timers live: a `task.changed` kind `timer` frame (flow F1) becomes a
 * partial reload of this one prop. A reader without the prop never asks for it.
 */
if (props.workingNowByProject !== undefined) {
    useLiveTaskProps(['workingNowByProject'], { accept: workingNowPing });
}

function workingOn(projectId: number): WorkingNowRow[] {
    return props.workingNowByProject?.[projectId] ?? [];
}

/* ----------------------------------------------------------------- filters */

/**
 * The chip bar reads and writes the query string itself. Active / Archived is not a chip: it
 * is which list you are looking at (`archived=1` is the archive and nothing else), so it sits
 * in front of the bar as a two-option segmented control, one click either way.
 */
const view = computed(() => (props.filters.archived ? 'archived' : 'active'));

function chooseView(value: unknown): void {
    if (value !== 'active' && value !== 'archived') {
        return;
    }

    pushQuery({ archived: value === 'archived' });
}

const filterDefs = computed<FilterDef[]>(() => [
    {
        key: 'client_id',
        label: 'Client',
        kind: 'select',
        options: props.clients.map((client) => ({ value: String(client.id), label: client.name })),
        searchPlaceholder: 'Search clients…',
    },
    { key: 'project_type', label: 'Type', kind: 'select', options: props.projectTypes },
    { key: 'status', label: 'Status', kind: 'select', options: props.statuses },
    {
        key: 'pm_id',
        label: 'PM',
        kind: 'select',
        options: props.projectManagers.map((manager) => ({ value: String(manager.id), label: manager.name })),
        searchPlaceholder: 'Search project managers…',
    },
]);

const hasFilters = computed(
    () =>
        Boolean(props.filters.search) ||
        (props.filters.client_id !== null && props.filters.client_id !== '') ||
        Boolean(props.filters.project_type) ||
        Boolean(props.filters.status) ||
        (props.filters.pm_id !== null && props.filters.pm_id !== '') ||
        Boolean(props.filters.archived),
);

function clearFilters(): void {
    resetQuery();
}

/** The archive with nothing else narrowing it, and nothing in it. */
const onlyArchived = computed(() => props.filters.archived && !hasFiltersBesidesView());

function hasFiltersBesidesView(): boolean {
    return (
        Boolean(props.filters.search) ||
        (props.filters.client_id !== null && props.filters.client_id !== '') ||
        Boolean(props.filters.project_type) ||
        Boolean(props.filters.status) ||
        (props.filters.pm_id !== null && props.filters.pm_id !== '')
    );
}

/* ----------------------------------------------------------------- columns */

/**
 * No column is `sortable`: `ProjectController::index()` orders by name and reads no
 * `sort`/`dir` parameter, and a header that pushed one would silently do nothing. They
 * turn on in the same brief that teaches the controller to sort.
 */
const columns = computed<ColumnDef<Project>[]>(() => [
    { key: 'name', header: 'Project', hideable: false },
    { key: 'client', header: 'Client', value: (project) => project.client?.name ?? null },
    { key: 'project_type_label', header: 'Type' },
    { key: 'status', header: 'Status', cell: 'badge', nowrap: true },
    { key: 'priority', header: 'Priority', nowrap: true },
    { key: 'deadline', header: 'Deadline', cell: 'date', nowrap: true },
    { key: 'money', header: 'Money', cell: 'currency', align: 'right', nowrap: true },
]);

const DATE = new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium' });

function formatDeadline(deadline: string | null | undefined): string {
    if (!deadline) {
        return '—';
    }

    const date = new Date(deadline);

    return Number.isNaN(date.getTime()) ? deadline : DATE.format(date);
}

/** Only an open project can be late; a completed or cancelled one just has a past date. */
function isOverdue(project: Project): boolean {
    if (!project.deadline || (project.status !== 'active' && project.status !== 'on_hold')) {
        return false;
    }

    const date = new Date(project.deadline);

    return !Number.isNaN(date.getTime()) && date.getTime() < Date.now();
}

const URGENT = 'urgent';

const loading = useNavigationPending();

/* ----------------------------------------------------------------- actions */

const pendingProject = ref<Project | null>(null);
const archiving = ref(false);

/**
 * Decision 5-20, and the screen the decision names: this confirmation is opened from a
 * `DropdownMenuItem`, so reka restored focus to a menu item the `⋯` menu had already unmounted and
 * a keyboard user was dropped on `<body>`, mid-table, whichever button they pressed.
 *
 * `openFromMenu` captures the row's `⋯` trigger while the menu is still open and defers the dialog
 * by a tick; `returnFocus` on every way out puts the keyboard back on that button — and the row
 * survives an archive, so here it is always the `⋯` and never the fallback.
 */
const menu = useMenuDialog();

function askToToggleArchive(project: Project): void {
    menu.openFromMenu(() => {
        pendingProject.value = project;
    });
}

/** Cancel, Esc, and the write that closed it: one way out for focus. */
function closeArchive(): void {
    pendingProject.value = null;
    menu.returnFocus();
}

function confirmArchiveToggle(): void {
    const project = pendingProject.value;

    if (!project || archiving.value) {
        return;
    }

    archiving.value = true;

    router.post(
        `/admin/projects/${project.id}/${project.is_archived ? 'unarchive' : 'archive'}`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                archiving.value = false;
                closeArchive();
            },
        },
    );
}

/* -------------------------------------------------------- delete for good */

const deletingProject = ref<Project | null>(null);

function askToDelete(project: Project): void {
    menu.openFromMenu(() => {
        deletingProject.value = project;
    });
}

function closeDelete(): void {
    deletingProject.value = null;
    menu.returnFocus();
}
</script>

<template>
    <Head title="Projects" />

    <PageShell title="Projects" description="Every client project and where it stands.">
        <template #actions>
            <Button as-child>
                <Link href="/admin/projects/create">
                    <Plus aria-hidden="true" />
                    New project
                </Link>
            </Button>
        </template>

        <div class="flex min-w-0 flex-col gap-4">
            <div class="flex min-w-0 flex-col gap-2 sm:flex-row sm:items-start">
                <ToggleGroup
                    type="single"
                    variant="outline"
                    class="shrink-0 self-start"
                    :model-value="view"
                    aria-label="Which projects"
                    @update:model-value="chooseView"
                >
                    <ToggleGroupItem value="active" class="px-3">Active</ToggleGroupItem>
                    <ToggleGroupItem value="archived" class="px-3">
                        <Archive aria-hidden="true" />
                        Archived
                    </ToggleGroupItem>
                </ToggleGroup>

                <FilterBar
                    page-actions
                    class="min-w-0 flex-1"
                    :search="filters.search"
                    :filters="filterDefs"
                    placeholder="Search projects…"
                    input-id="projects-search"
                />
            </div>

            <DataTable
                id="admin-projects"
                :columns="columns"
                :rows="projects.data"
                :meta="projects.meta"
                :links="projects.links"
                :loading="loading"
                :filters-active="hasFilters"
                :row-label="(project) => project.name"
                noun="project"
                :empty-icon="FolderKanban"
                empty-title="No projects yet"
                empty-description="Create the first project and the work can follow."
                :filtered-title="onlyArchived ? 'No archived projects' : 'No projects match these filters'"
                :filtered-description="
                    onlyArchived
                        ? 'A project you archive shows up here.'
                        : 'Clear a filter, or widen the search.'
                "
                @clear="clearFilters"
            >
                <template #cell-name="{ row }">
                    <div class="flex min-w-0 flex-col gap-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <Link :href="`/admin/projects/${row.id}`" class="font-medium break-words hover:underline">
                                {{ row.name }}
                            </Link>
                            <span
                                v-if="row.is_archived"
                                class="rounded-full border px-2 py-0.5 text-xs font-medium text-muted-foreground"
                            >
                                Archived
                            </span>
                        </div>
                        <span v-if="row.domain" class="text-xs font-normal text-muted-foreground break-all">
                            {{ row.domain }}
                        </span>
                        <ProjectWorkingNow v-if="workingOn(row.id).length" :rows="workingOn(row.id)" />
                    </div>
                </template>

                <template #cell-client="{ row }">
                    <span v-if="row.client">{{ row.client.name }}</span>
                    <span v-else class="text-muted-foreground">Internal</span>
                </template>

                <template #cell-status="{ row }">
                    <StatusPill :label="row.status_label" :tone="toneForProjectStatus(row.status)" />
                </template>

                <template #cell-priority="{ row }">
                    <span
                        :class="
                            cn('text-xs', row.priority === URGENT ? 'text-destructive' : 'text-muted-foreground')
                        "
                    >
                        {{ row.priority_label }}
                    </span>
                </template>

                <!--
                    Late is a status, so it prints its word. Red alone said nothing to a screen
                    reader and nothing in greyscale: two rows a day apart read identically. Same
                    treatment as `Priority: Urgent` above — the destructive colour carries the
                    emphasis, the word carries the meaning. Stacked rather than inline because the
                    column is `nowrap`.
                -->
                <template #cell-deadline="{ row }">
                    <span :class="cn('flex flex-col', isOverdue(row) && 'text-destructive')">
                        <span>{{ formatDeadline(row.deadline) }}</span>
                        <span v-if="isOverdue(row)" class="text-xs font-medium">Overdue</span>
                    </span>
                </template>

                <template #cell-money="{ row }">
                    {{ moneyLine(row.finance) ?? '—' }}
                </template>

                <template #row-actions="{ row }">
                    <DropdownMenuItem as-child>
                        <Link :href="`/admin/projects/${row.id}`" class="w-full">
                            <FolderKanban aria-hidden="true" />
                            View
                        </Link>
                    </DropdownMenuItem>
                    <DropdownMenuItem as-child>
                        <Link :href="`/admin/projects/${row.id}/edit`" class="w-full">
                            <Pencil aria-hidden="true" />
                            Edit
                        </Link>
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        v-if="row.permissions?.can_archive"
                        :variant="row.is_archived ? 'default' : 'destructive'"
                        @select="askToToggleArchive(row)"
                    >
                        <component :is="row.is_archived ? ArchiveRestore : Archive" aria-hidden="true" />
                        {{ row.is_archived ? 'Unarchive' : 'Archive' }}
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        v-if="row.is_archived && canForceDelete(row)"
                        variant="destructive"
                        @select="askToDelete(row)"
                    >
                        <Trash2 aria-hidden="true" />
                        Delete permanently…
                    </DropdownMenuItem>
                </template>

                <template #empty-action>
                    <Button as-child>
                        <Link href="/admin/projects/create">
                            <Plus aria-hidden="true" />
                            New project
                        </Link>
                    </Button>
                </template>
            </DataTable>
        </div>
    </PageShell>

    <Dialog
        :open="pendingProject !== null"
        @update:open="(open) => { if (! open) { closeArchive(); } }"
    >
        <DialogContent>
            <DialogHeader>
                <DialogTitle>
                    {{ pendingProject?.is_archived ? 'Unarchive' : 'Archive' }} {{ pendingProject?.name }}?
                </DialogTitle>
                <DialogDescription>
                    {{
                        pendingProject?.is_archived
                            ? 'The project goes back on the active list and can be worked on again.'
                            : 'The project stays on record but drops off the active list and stops accepting changes.'
                    }}
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button type="button" variant="outline" :disabled="archiving" @click="closeArchive">
                    Cancel
                </Button>
                <Button
                    type="button"
                    :variant="pendingProject?.is_archived ? 'default' : 'destructive'"
                    :disabled="archiving"
                    @click="confirmArchiveToggle"
                >
                    {{ pendingProject?.is_archived ? 'Unarchive' : 'Archive' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <DeleteProjectDialog :project="deletingProject" @close="closeDelete" />
</template>
