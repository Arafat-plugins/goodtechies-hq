<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import MyTasks from '@/Components/Tasks/MyTasks.vue';
import type { MyTasksPayload } from '@/Components/Tasks/MyTasks.vue';
import PageShell from '@/Components/PageShell.vue';
import EmployeeLayout from '@/Layouts/EmployeeLayout.vue';

defineOptions({ layout: EmployeeLayout });

/**
 * The screen an employee opens first thing. Thin on purpose — the strip and the list are the
 * shared `MyTasks` component, and every count arrived as a server-side query.
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
            surface="employee"
            :buckets="props.buckets"
            :bucket="props.bucket"
            :tasks="props.tasks"
            :limit="props.limit"
            :today="props.today"
        />
    </PageShell>
</template>
