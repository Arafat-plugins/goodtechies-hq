<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Building2, Pencil, Plus, UserX } from '@lucide/vue';
import { computed, ref } from 'vue';
import ClientAvatar from '@/Components/Clients/ClientAvatar.vue';
import type { Client } from '@/Components/Clients/ClientForm.vue';
import { NO_TASKS } from '@/Components/Clients/clientTasks';
import TaskCountChips from '@/Components/Clients/TaskCountChips.vue';
import DataTable from '@/Components/DataTable/DataTable.vue';
import type { ColumnDef } from '@/Components/DataTable/types';
import FilterBar from '@/Components/FilterBar.vue';
import PageShell from '@/Components/PageShell.vue';
import type { Paginated } from '@/Components/Pagination.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
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
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useMenuDialog } from '@/lib/menuFocus';
import { pushQuery, resetQuery } from '@/lib/tableState';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { useNavigationPending } from '@/lib/useNavigationPending';

defineOptions({ layout: AdminLayout });

const props = defineProps<{
    clients: Paginated<Client>;
    filters: { search: string | null; status: string | null; tasks: string | null; projects: string | null };
}>();

/* ----------------------------------------------------------------- filters */

/**
 * The chip bar reads and writes the query string itself, so this page no longer owns any
 * navigation: removing the Status chip *is* "all statuses", which is why there is no
 * sentinel option any more.
 */
/*
 * Polish 055 — image 2: three visible dropdowns beside the search, "All status", "All clients"
 * (with / without projects) and the open-tasks filter. Each writes its own query key; "all"
 * clears it.
 */
const ALL = 'all';

function filterModel(key: 'status' | 'projects' | 'tasks') {
    return computed<string>({
        get: () => props.filters[key] ?? ALL,
        set: (value) => pushQuery({ [key]: value === ALL ? null : value }),
    });
}

const statusFilter = filterModel('status');
const projectsFilter = filterModel('projects');
const tasksFilter = filterModel('tasks');

const hasFilters = computed(
    () =>
        Boolean(props.filters.search) ||
        Boolean(props.filters.status) ||
        Boolean(props.filters.tasks) ||
        Boolean(props.filters.projects),
);

/* ----------------------------------------------------------------- cells (polish 049) */

const DATE = new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
const RELATIVE = new Intl.RelativeTimeFormat('en-GB', { numeric: 'auto' });

function activityDate(at: string | null | undefined): string {
    const date = at ? new Date(at) : null;

    return date && !Number.isNaN(date.getTime()) ? DATE.format(date) : '—';
}

function activityAgo(at: string | null | undefined): string {
    const date = at ? new Date(at) : null;

    if (!date || Number.isNaN(date.getTime())) {
        return '';
    }

    const seconds = Math.round((date.getTime() - Date.now()) / 1000);

    for (const [unit, size] of [
        ['year', 31_536_000],
        ['month', 2_592_000],
        ['day', 86_400],
        ['hour', 3_600],
        ['minute', 60],
    ] as const) {
        if (Math.abs(seconds) >= size) {
            return RELATIVE.format(Math.round(seconds / size), unit);
        }
    }

    return 'just now';
}


function clearFilters(): void {
    resetQuery();
}

/* ----------------------------------------------------------------- columns */

/**
 * No column is `sortable` and there is no rows-per-page control: `ClientController::index()`
 * orders by name and reads neither `sort`/`dir` nor `per_page`. They turn on in the same
 * brief that teaches the controller to sort.
 */
const columns = computed<ColumnDef<Client>[]>(() => [
    { key: 'name', header: 'Client', hideable: false },
    { key: 'projects_count', header: 'Projects', value: (client) => client.projects_count ?? 0 },
    { key: 'tasks', header: 'Tasks', nowrap: true, value: (client) => client.task_counts?.total ?? 0 },
    { key: 'status', header: 'Status', cell: 'badge', nowrap: true },
    // Polish 056: "Total tasks" takes the old Primary contact column's place.
    { key: 'total_tasks', header: 'Total tasks', nowrap: true, value: (client) => client.task_counts?.total ?? 0 },
    { key: 'last_activity', header: 'Last activity', nowrap: true, value: (client) => client.last_activity_at ?? '' },
]);

const loading = useNavigationPending();

/* ----------------------------------------------------------------- actions */

const pendingClient = ref<Client | null>(null);
const deactivating = ref(false);

/**
 * Decision 5-20: the confirmation is opened from a `DropdownMenuItem`, which the `⋯` menu has
 * unmounted by the time reka tries to restore focus to it — so the restore landed on `<body>`.
 * `openFromMenu` captures the row's `⋯` trigger and defers the dialog by a tick; `closeDeactivate`
 * is the one way out for focus. See `lib/menuFocus.ts`.
 */
const menu = useMenuDialog();

function askToDeactivate(client: Client): void {
    menu.openFromMenu(() => {
        pendingClient.value = client;
    });
}

function closeDeactivate(): void {
    pendingClient.value = null;
    menu.returnFocus();
}

function confirmDeactivate(): void {
    const client = pendingClient.value;

    if (!client || deactivating.value) {
        return;
    }

    deactivating.value = true;

    router.post(
        `/admin/clients/${client.id}/deactivate`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                deactivating.value = false;
                closeDeactivate();
            },
        },
    );
}
</script>

<template>
    <Head title="Clients" />

    <PageShell title="Clients" description="Every client and the projects that belong to them.">
        <template #actions>
            <Button as-child>
                <Link href="/admin/clients/create">
                    <Plus aria-hidden="true" />
                    New client
                </Link>
            </Button>
        </template>

        <div class="flex min-w-0 flex-col gap-4">
            <FilterBar
                page-actions
                :search="filters.search"
                :active="hasFilters"
                placeholder="Search by client, website, email or company…"
                search-width="sm:w-88"
                input-id="clients-search"
            >
                <template #extra>
                    <Select v-model="statusFilter">
                        <SelectTrigger class="w-28" aria-label="Status" data-filter="status">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All status</SelectItem>
                            <SelectItem value="active">Active</SelectItem>
                            <SelectItem value="inactive">Inactive</SelectItem>
                        </SelectContent>
                    </Select>
                    <Select v-model="projectsFilter">
                        <SelectTrigger class="w-36" aria-label="Clients" data-filter="projects">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All clients</SelectItem>
                            <SelectItem value="with">Clients with projects</SelectItem>
                            <SelectItem value="without">Clients without projects</SelectItem>
                        </SelectContent>
                    </Select>
                    <Select v-model="tasksFilter">
                        <SelectTrigger class="w-44" aria-label="Tasks" data-filter="tasks">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All tasks</SelectItem>
                            <SelectItem value="open">Clients with open tasks</SelectItem>
                            <SelectItem value="none">No open tasks</SelectItem>
                        </SelectContent>
                    </Select>
                </template>
            </FilterBar>

            <DataTable
                id="admin-clients"
                :columns="columns"
                :rows="clients.data"
                :meta="clients.meta"
                :links="clients.links"
                :loading="loading"
                :filters-active="hasFilters"
                :row-label="(client) => client.name"
                noun="client"
                :empty-icon="Building2"
                empty-title="No clients yet"
                empty-description="Add the first client and their projects can follow."
                filtered-title="No clients match these filters"
                filtered-description="Clear a filter, or widen the search."
                @clear="clearFilters"
            >
                <template #cell-name="{ row }">
                    <div class="flex min-w-0 items-center gap-3">
                        <ClientAvatar :id="row.id" :name="row.name" />
                        <div class="flex min-w-0 flex-col">
                            <Link :href="`/admin/clients/${row.id}`" class="font-medium break-words hover:underline">
                                {{ row.name }}
                            </Link>
                            <span v-if="row.nickname" class="truncate text-xs text-muted-foreground">{{ row.nickname }}</span>
                        </div>
                    </div>
                </template>

                <!-- Polish 066: the project count only — the type icons are gone. -->
                <template #cell-projects_count="{ row }">
                    <span class="text-sm whitespace-nowrap tabular-nums">
                        {{ row.projects_count ?? 0 }} {{ (row.projects_count ?? 0) === 1 ? 'project' : 'projects' }}
                    </span>
                </template>

                <template #cell-tasks="{ row }">
                    <TaskCountChips :counts="row.task_counts ?? NO_TASKS" stacked />
                </template>

                <template #cell-total_tasks="{ row }">
                    <span class="text-base font-semibold tabular-nums">{{ (row.task_counts ?? NO_TASKS).total }}</span>
                </template>

                <template #cell-last_activity="{ row }">
                    <div class="flex flex-col gap-0.5">
                        <span class="text-sm tabular-nums">{{ activityDate(row.last_activity_at) }}</span>
                        <span class="text-xs text-muted-foreground">{{ activityAgo(row.last_activity_at) }}</span>
                    </div>
                </template>

                <template #cell-status="{ row }">
                    <StatusBadge :status="row.status === 'active' ? 'done' : 'todo'" :label="row.status_label" />
                </template>

                <template #row-actions="{ row }">
                    <DropdownMenuItem as-child>
                        <Link :href="`/admin/clients/${row.id}`" class="w-full">
                            <Building2 aria-hidden="true" />
                            View
                        </Link>
                    </DropdownMenuItem>
                    <DropdownMenuItem as-child>
                        <Link :href="`/admin/clients/${row.id}/edit`" class="w-full">
                            <Pencil aria-hidden="true" />
                            Edit
                        </Link>
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        v-if="row.status === 'active'"
                        variant="destructive"
                        @select="askToDeactivate(row)"
                    >
                        <UserX aria-hidden="true" />
                        Deactivate
                    </DropdownMenuItem>
                </template>

                <template #empty-action>
                    <Button as-child>
                        <Link href="/admin/clients/create">
                            <Plus aria-hidden="true" />
                            New client
                        </Link>
                    </Button>
                </template>
            </DataTable>
        </div>
    </PageShell>

    <Dialog :open="pendingClient !== null" @update:open="(open) => { if (! open) { closeDeactivate(); } }">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Deactivate {{ pendingClient?.name }}?</DialogTitle>
                <DialogDescription>
                    The client stays on record with their projects — they just stop counting as active.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button type="button" variant="outline" :disabled="deactivating" @click="closeDeactivate">
                    Cancel
                </Button>
                <Button type="button" variant="destructive" :disabled="deactivating" @click="confirmDeactivate">
                    {{ deactivating ? 'Deactivating…' : 'Deactivate' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
