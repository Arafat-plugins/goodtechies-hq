<script setup lang="ts">
/**
 * Polish 078: the employee's Projects page uses the Admin's project cards (polish 075) — folder
 * tile, name, website, "N tasks", "Client: …", ⋯ Open — with "All projects" (type), "All clients"
 * and Newest first; no search box. Only the projects the employee can see (ProjectClientBoard::tree).
 */
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PageShell from '@/Components/PageShell.vue';
import type { ClientTreeRow } from '@/Components/Projects/ClientProjectsView.vue';
import ProjectCardsGrid, { type ProjectSort } from '@/Components/Projects/ProjectCardsGrid.vue';
import { NativeSelect } from '@/Components/ui/native-select';
import EmployeeLayout from '@/Layouts/EmployeeLayout.vue';

defineOptions({ layout: EmployeeLayout });

const props = defineProps<{
    clientTree: ClientTreeRow[];
    projectTypes: { value: string; label: string }[];
}>();

const cardType = ref('');
const cardClient = ref('');
const cardSort = ref<ProjectSort>('newest');

const cardClients = computed(() =>
    [...props.clientTree]
        .map((row) => ({ key: row.key, name: row.name ?? 'Internal' }))
        .sort((a, b) => a.name.localeCompare(b.name)),
);

function clearCardFilters(): void {
    cardType.value = '';
    cardClient.value = '';
}
</script>

<template>
    <Head title="Projects" />

    <PageShell title="Projects" description="The projects you are assigned to.">
        <div class="flex min-w-0 flex-col gap-4">
            <div class="flex min-w-0 flex-wrap items-center justify-end gap-2">
                <NativeSelect v-model="cardType" aria-label="Filter projects by type" class="h-9">
                    <option value="">All projects</option>
                    <option v-for="option in projectTypes" :key="option.value" :value="option.value">
                        {{ option.label }}
                    </option>
                </NativeSelect>
                <NativeSelect v-model="cardClient" aria-label="Filter projects by client" class="h-9 max-w-60">
                    <option value="">All clients</option>
                    <option v-for="option in cardClients" :key="option.key" :value="option.key">
                        {{ option.name }}
                    </option>
                </NativeSelect>
                <NativeSelect v-model="cardSort" aria-label="Order projects" class="h-9">
                    <option value="newest">Newest first</option>
                    <option value="oldest">Oldest first</option>
                    <option value="name">Name A–Z</option>
                </NativeSelect>
            </div>

            <ProjectCardsGrid
                :tree="clientTree"
                :type="cardType"
                :client="cardClient"
                :sort="cardSort"
                base="/employee/projects"
                :can-manage="false"
                @clear="clearCardFilters"
            />
        </div>
    </PageShell>
</template>
