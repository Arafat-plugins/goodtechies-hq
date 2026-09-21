<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import PageShell from '@/Components/PageShell.vue';
import type { EmployeeOption, NamedRef, Option, Project } from '@/Components/Projects/ProjectForm.vue';
import ProjectForm from '@/Components/Projects/ProjectForm.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps<{
    project: { data: Project };
    clients: NamedRef[];
    assignableEmployees: EmployeeOption[];
    projectTypes: Option[];
    statuses: Option[];
    priorities: Option[];
    billingTypes: Option[];
    billingFrequencies: Option[];
}>();

const project = computed(() => props.project.data);
</script>

<template>
    <Head :title="`Edit ${project.name}`" />

    <PageShell
        :title="`Edit ${project.name}`"
        description="Money and members have their own controls on the project page."
    >
        <ProjectForm
            :project="project"
            :clients="clients"
            :assignable-employees="assignableEmployees"
            :project-types="projectTypes"
            :statuses="statuses"
            :priorities="priorities"
            :billing-types="billingTypes"
            :billing-frequencies="billingFrequencies"
            submit-label="Save changes"
        />
    </PageShell>
</template>
