<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import PageShell from '@/Components/PageShell.vue';
import type { EmployeeOption, NamedRef, Option } from '@/Components/Projects/ProjectForm.vue';
import ProjectForm from '@/Components/Projects/ProjectForm.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

defineProps<{
    clients: NamedRef[];
    assignableEmployees: EmployeeOption[];
    projectTypes: Option[];
    statuses: Option[];
    priorities: Option[];
    billingTypes: Option[];
    billingFrequencies: Option[];
}>();
</script>

<template>
    <Head title="New project" />

    <PageShell title="New project" description="Book the work, then assign the people.">
        <ProjectForm
            :clients="clients"
            :assignable-employees="assignableEmployees"
            :project-types="projectTypes"
            :statuses="statuses"
            :priorities="priorities"
            :billing-types="billingTypes"
            :billing-frequencies="billingFrequencies"
            submit-label="Create project"
        />
    </PageShell>
</template>
