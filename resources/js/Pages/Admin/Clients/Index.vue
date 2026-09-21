<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Building2, Pencil, Plus, UserX } from '@lucide/vue';
import { computed, ref } from 'vue';
import type { Client } from '@/Components/Clients/ClientForm.vue';
import DataTable from '@/Components/DataTable/DataTable.vue';
import type { ColumnDef } from '@/Components/DataTable/types';
import type { FilterDef } from '@/Components/FilterBar.vue';
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
import { resetQuery } from '@/lib/tableState';
import { useNavigationPending } from '@/lib/useNavigationPending';

defineOptions({ layout: AdminLayout });

const props = defineProps<{
    clients: Paginated<Client>;
    filters: { search: string | null; status: string | null };
}>();

/* ----------------------------------------------------------------- filters */

/**
 * The chip bar reads and writes the query string itself, so this page no longer owns any
 * navigation: removing the Status chip *is* "all statuses", which is why there is no
 * sentinel option any more.
 */
const filterDefs = computed<FilterDef[]>(() => [
    {
        key: 'status',
        label: 'Status',
        kind: 'select',
        options: [
            { value: 'active', label: 'Active' },
            { value: 'inactive', label: 'Inactive' },
        ],
    },
]);

const hasFilters = computed(() => Boolean(props.filters.search) || Boolean(props.filters.status));

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
    { key: 'name', header: 'Name', hideable: false },
    {
        key: 'projects_count',
        header: 'Projects',
        cell: 'number',
        nowrap: true,
        value: (client) => client.projects_count ?? 0,
    },
    { key: 'status', header: 'Status', cell: 'badge', nowrap: true },
]);

const loading = useNavigationPending();

/* ----------------------------------------------------------------- actions */

const pendingClient = ref<Client | null>(null);
const deactivating = ref(false);

/** Let the dropdown finish closing before the dialog takes the focus trap. */
function askToDeactivate(client: Client): void {
    setTimeout(() => {
        pendingClient.value = client;
    }, 0);
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
                pendingClient.value = null;
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
                :search="filters.search"
                :filters="filterDefs"
                placeholder="Search clients…"
                input-id="clients-search"
            />

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
                    <Link :href="`/admin/clients/${row.id}`" class="font-medium break-words hover:underline">
                        {{ row.name }}
                    </Link>
                </template>

                <template #cell-projects_count="{ row }">
                    {{ row.projects_count ?? 0 }}
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

    <Dialog :open="pendingClient !== null" @update:open="(open) => (pendingClient = open ? pendingClient : null)">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Deactivate {{ pendingClient?.name }}?</DialogTitle>
                <DialogDescription>
                    The client stays on record with their projects — they just stop counting as active.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button type="button" variant="outline" :disabled="deactivating" @click="pendingClient = null">
                    Cancel
                </Button>
                <Button type="button" variant="destructive" :disabled="deactivating" @click="confirmDeactivate">
                    {{ deactivating ? 'Deactivating…' : 'Deactivate' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
