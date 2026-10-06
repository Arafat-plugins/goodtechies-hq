<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import FilePanel from '@/Components/Files/FilePanel.vue';
import { internalFileRoutes } from '@/Components/Files/files';
import PageShell from '@/Components/PageShell.vue';
import FinanceCard from '@/Components/Projects/FinanceCard.vue';
import type { EmployeeOption, NamedRef, Option, Project } from '@/Components/Projects/ProjectForm.vue';
import ProjectForm from '@/Components/Projects/ProjectForm.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps<{
    project: { data: Project };
    clients: NamedRef[];
    assignableEmployees: EmployeeOption[];
    projectTypes: Option[];
    projectTypeDefaults?: Record<string, string>;
    canRenameProjectTypes?: boolean;
    statuses: Option[];
    priorities: Option[];
    billingTypes: Option[];
    recurrenceFrequencies: Option[];
    billingFrequencies: Option[];
}>();

const project = computed(() => props.project.data);
const permissions = computed(() => project.value.permissions ?? {});
const internalFiles = computed(() => internalFileRoutes(project.value.id));
</script>

<template>
    <Head :title="`Edit ${project.name}`" />

    <PageShell
        :title="`Edit ${project.name}`"
        :description="
            permissions.can_view_finance
                ? 'Finance saves on its own below. Members have their own controls on the project page.'
                : 'Members have their own controls on the project page.'
        "
    >
        <ProjectForm
            :project="project"
            :clients="clients"
            :assignable-employees="assignableEmployees"
            :project-types="projectTypes"
            :project-type-defaults="projectTypeDefaults"
            :can-rename-project-types="canRenameProjectTypes"
            :statuses="statuses"
            :priorities="priorities"
            :billing-types="billingTypes"
            :recurrence-frequencies="recurrenceFrequencies"
            :billing-frequencies="billingFrequencies"
            submit-label="Save changes"
        >
            <!-- Uploads go straight away (FilePanel's own behaviour), separate from Save changes. -->
            <template #internal-notes-extra>
                <FilePanel
                    compact
                    title="Attachments"
                    :routes="internalFiles"
                    :can-upload="permissions.can_update === true"
                />
            </template>
        </ProjectForm>

        <FinanceCard
            v-if="permissions.can_view_finance"
            :project="project"
            :billing-frequencies="billingFrequencies"
            :can-edit="permissions.can_update === true"
        />
    </PageShell>
</template>
