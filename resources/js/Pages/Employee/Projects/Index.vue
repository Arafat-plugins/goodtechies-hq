<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { FolderKanban } from '@lucide/vue';
import { computed } from 'vue';
import type { EmployeeProject } from '@/Components/Employee/ProjectListCard.vue';
import ProjectListCard from '@/Components/Employee/ProjectListCard.vue';
import EmptyState from '@/Components/EmptyState.vue';
import FilterBar from '@/Components/FilterBar.vue';
import PageShell from '@/Components/PageShell.vue';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import EmployeeLayout from '@/Layouts/EmployeeLayout.vue';

defineOptions({ layout: EmployeeLayout });

interface Filters {
    search: string | null;
    status: string | null;
}

const props = defineProps<{
    projects: { data: EmployeeProject[] };
    filters: Filters;
}>();

/** reka-ui's Select has no empty value, so this sentinel stands in for "no filter". */
const ALL = 'all';

/** The statuses an employee can filter by; `cancelled` and `archived` are not offered. */
const STATUSES = [
    { value: 'active', label: 'Active' },
    { value: 'on_hold', label: 'On hold' },
    { value: 'completed', label: 'Completed' },
];

const projects = computed(() => props.projects.data);

function applyFilters(patch: Partial<Filters>): void {
    const next = { ...props.filters, ...patch };

    router.get(
        '/employee/projects',
        {
            ...(next.search ? { search: next.search } : {}),
            ...(next.status ? { status: next.status } : {}),
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

const statusFilter = computed({
    get: () => props.filters.status ?? ALL,
    set: (value: string) => applyFilters({ status: value === ALL ? null : value }),
});

const hasFilters = computed(() => Boolean(props.filters.search) || Boolean(props.filters.status));

function clearFilters(): void {
    router.get('/employee/projects', {}, { preserveState: true, preserveScroll: true, replace: true });
}
</script>

<template>
    <Head title="Projects" />

    <PageShell title="Projects" description="The projects you are assigned to.">
        <div class="flex min-w-0 flex-col gap-4">
            <FilterBar
                :search="filters.search"
                :active="hasFilters"
                placeholder="Search projects…"
                input-id="employee-projects-search"
                @update="(value) => applyFilters({ search: value === '' ? null : value })"
                @clear="clearFilters"
            >
                <Select v-model="statusFilter">
                    <SelectTrigger class="w-full sm:w-44" aria-label="Filter by status">
                        <SelectValue placeholder="All statuses" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem :value="ALL">All</SelectItem>
                        <SelectItem v-for="option in STATUSES" :key="option.value" :value="option.value">
                            {{ option.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </FilterBar>

            <EmptyState
                v-if="projects.length === 0"
                :icon="FolderKanban"
                :variant="hasFilters ? 'filtered' : 'empty'"
                :title="hasFilters ? 'Nothing matches these filters' : 'No projects assigned yet'"
                :description="
                    hasFilters
                        ? 'Clear a filter, or widen the search.'
                        : 'A manager adds you to a project and it shows up here.'
                "
                @clear="clearFilters"
            />

            <!-- Cards at every width: an employee reads a project by its domain, not by a row. -->
            <div v-else class="grid min-w-0 gap-4 md:grid-cols-2 xl:grid-cols-3">
                <ProjectListCard v-for="project in projects" :key="project.id" :project="project" />
            </div>
        </div>
    </PageShell>
</template>
