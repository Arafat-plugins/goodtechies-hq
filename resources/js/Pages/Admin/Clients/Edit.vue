<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import type { Client, StatusOption } from '@/Components/Clients/ClientForm.vue';
import ClientForm from '@/Components/Clients/ClientForm.vue';
import PageShell from '@/Components/PageShell.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps<{
    client: { data: Client };
    statuses: StatusOption[];
}>();

const client = props.client.data;
</script>

<template>
    <Head :title="`Edit ${client.name}`" />

    <PageShell :title="`Edit ${client.name}`" description="Changes take effect as soon as you save.">
        <ClientForm :client="client" :statuses="statuses" submit-label="Save changes" />
    </PageShell>
</template>
