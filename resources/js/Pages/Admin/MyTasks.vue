<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import MyTasks from '@/Components/Tasks/MyTasks.vue';
import type { MyTasksPayload } from '@/Components/Tasks/MyTasks.vue';
import PageShell from '@/Components/PageShell.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

/**
 * An Admin's own plate — not the agency's, which is `/admin/tasks`.
 *
 * The page is thin on purpose: every count and every row arrived resolved, and the strip and
 * the list are the shared `MyTasks` component both surfaces render.
 */
const props = defineProps<MyTasksPayload>();
</script>

<template>
    <Head title="My tasks" />

    <PageShell
        title="My tasks"
        description="Everything assigned to you: what is late, what is due today, and what is waiting on somebody else."
    >
        <MyTasks
            surface="admin"
            :buckets="props.buckets"
            :bucket="props.bucket"
            :tasks="props.tasks"
            :limit="props.limit"
            :today="props.today"
        />
    </PageShell>
</template>
