<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import type { ActivityDayPayload } from '@/Components/Activity/activity';
import ActivityDay from '@/Components/Activity/ActivityDay.vue';
import PageShell from '@/Components/PageShell.vue';
import type { TimerState } from '@/Components/Timer/timer';
import EmployeeLayout from '@/Layouts/EmployeeLayout.vue';

/**
 * Employee → Time → My activity: one day of the person's own timer sessions, minute by minute,
 * and the website domains the time went to. No score, no rank, no URL or page title.
 */
defineProps<{
    activity: ActivityDayPayload;
    timer: TimerState;
}>();
</script>

<template>
    <Head title="My activity" />

    <EmployeeLayout>
        <PageShell title="My activity" :breadcrumb="[{ label: 'Time', href: '/employee/time' }, { label: 'My activity' }]">
            <ActivityDay :activity="activity" />
        </PageShell>
    </EmployeeLayout>
</template>
