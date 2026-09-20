<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    Bell,
    CalendarCheck,
    CalendarClock,
    CalendarDays,
    CircleAlert,
    CircleCheck,
    History,
    ListTodo,
    LoaderCircle,
    PartyPopper,
    Timer,
    Video,
} from '@lucide/vue';
import { computed } from 'vue';
import PageHeader from '@/Components/PageHeader.vue';
import PlaceholderPanel from '@/Components/PlaceholderPanel.vue';
import StatCard from '@/Components/StatCard.vue';
import EmployeeLayout from '@/Layouts/EmployeeLayout.vue';
import type { TrackingMode } from '@/types';

defineOptions({ layout: EmployeeLayout });

const props = defineProps<{
    greetingName: string;
    today: string;
    trackingMode: TrackingMode;
}>();

const taskStats = [
    { label: 'My Tasks', icon: ListTodo },
    { label: 'Due Today', icon: CalendarClock },
    { label: 'Overdue', icon: CircleAlert },
    { label: 'In Progress', icon: LoaderCircle },
    { label: 'Completed', icon: CircleCheck },
];

const roleCard = computed(() => {
    if (props.trackingMode === 'remote_timer') {
        return { label: 'Time today', sub: 'Target 5h · arrives in Phase 4', icon: Timer };
    }

    if (props.trackingMode === 'office_attendance') {
        return { label: 'Attendance today', sub: 'Arrives in Phase 4', icon: CalendarCheck };
    }

    return null;
});

const panels = [
    { title: 'My schedule', phase: 4, icon: CalendarDays, description: 'Your working hours and shifts will show here.' },
    { title: 'Upcoming meetings', phase: 7, icon: Video, description: 'Meetings you are invited to will show here.' },
    { title: 'Upcoming holidays', phase: 5, icon: PartyPopper, description: 'Company holidays will show here.' },
    { title: 'Notifications', phase: 2, icon: Bell, description: 'Updates on your tasks will show here.' },
    { title: 'Recent activity', phase: 2, icon: History, description: 'Your latest task changes will show here.' },
];
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex flex-col gap-6">
        <PageHeader :greeting-name="greetingName" :today="today" />

        <section aria-label="My tasks" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
            <StatCard v-for="stat in taskStats" :key="stat.label" :label="stat.label" :phase="2" :icon="stat.icon" />
            <StatCard
                v-if="roleCard"
                class="lg:col-span-2"
                :label="roleCard.label"
                :phase="4"
                :sub="roleCard.sub"
                :icon="roleCard.icon"
            />
        </section>

        <section aria-label="Coming up" class="grid gap-4 md:grid-cols-2">
            <PlaceholderPanel
                v-for="panel in panels"
                :key="panel.title"
                :title="panel.title"
                :phase="panel.phase"
                :icon="panel.icon"
                :description="panel.description"
            />
        </section>
    </div>
</template>
