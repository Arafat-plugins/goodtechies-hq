<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import PageShell from '@/Components/PageShell.vue';
import type { EmployeeOption, NamedRef, Option } from '@/Components/Projects/ProjectForm.vue';
import ProjectForm from '@/Components/Projects/ProjectForm.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

/** Polish 049: `?client=ID` (the client page's "Add website") picks that client. */
const defaultClientId = Number(new URLSearchParams(typeof window === 'undefined' ? '' : window.location.search).get('client')) || null;

defineProps<{
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
    currencies?: Option[];
    defaultCurrency?: string;
}>();
</script>

<template>
    <Head title="New project" />

    <PageShell title="New project" description="Book the work, then assign the people.">
        <ProjectForm
            :clients="clients"
            :default-client-id="defaultClientId"
            :assignable-employees="assignableEmployees"
            :project-types="projectTypes"
            :project-type-defaults="projectTypeDefaults"
            :can-rename-project-types="canRenameProjectTypes"
            :statuses="statuses"
            :priorities="priorities"
            :billing-types="billingTypes"
            :recurrence-frequencies="recurrenceFrequencies"
            :billing-frequencies="billingFrequencies"
            :currencies="currencies"
            :default-currency="defaultCurrency"
            submit-label="Create project"
        />
    </PageShell>
</template>
