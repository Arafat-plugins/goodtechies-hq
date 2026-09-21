<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import type { StatusOption } from '@/Components/Clients/ClientForm.vue';
import ClientForm from '@/Components/Clients/ClientForm.vue';
import PageShell from '@/Components/PageShell.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

defineProps<{
    statuses: StatusOption[];
}>();
</script>

<template>
    <Head title="New client" />

    <PageShell title="New client" description="Add a client before you give them projects.">
        <ClientForm :statuses="statuses" submit-label="Create client" />
    </PageShell>
</template>
