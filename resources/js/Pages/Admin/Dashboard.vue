<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    CalendarCheck,
    CalendarClock,
    CalendarOff,
    CircleAlert,
    CircleCheck,
    ClipboardCheck,
    FolderKanban,
    HandCoins,
    ListChecks,
    MessagesSquare,
    PartyPopper,
    Receipt,
    Scale,
    Timer,
    TrendingUp,
    UserCheck,
    Users,
    UserX,
    Video,
} from '@lucide/vue';
import PageHeader from '@/Components/PageHeader.vue';
import PlaceholderPanel from '@/Components/PlaceholderPanel.vue';
import StatCard from '@/Components/StatCard.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

defineProps<{
    greetingName: string;
    today: string;
    stats: {
        activeEmployees: number;
    };
}>();

const placeholderStats = [
    { label: 'Present today', phase: 4, icon: UserCheck },
    { label: 'On leave', phase: 5, icon: CalendarOff },
    { label: 'Absent', phase: 4, icon: UserX },
    { label: 'Tasks due today', phase: 2, icon: CalendarClock },
    { label: 'Overdue tasks', phase: 2, icon: CircleAlert },
    { label: 'Awaiting review', phase: 2, icon: ClipboardCheck },
    { label: 'Completed today', phase: 2, icon: CircleCheck },
    { label: 'Active projects', phase: 1, icon: FolderKanban },
    { label: 'Unread messages', phase: 6, icon: MessagesSquare },
    { label: 'Upcoming meetings', phase: 7, icon: Video },
    { label: 'Remote time today', phase: 4, icon: Timer },
    { label: 'Pending approvals', phase: 4, icon: CalendarCheck },
    { label: 'Upcoming holidays', phase: 5, icon: PartyPopper },
];

const panels = [
    { title: 'Tasks by status', phase: 2, icon: ListChecks, description: 'Open work grouped by status will show here.' },
    { title: 'Tasks by employee', phase: 2, icon: Users, description: 'Open tasks per person will show here.' },
    { title: 'Projects by type', phase: 1, icon: FolderKanban, description: 'Active projects grouped by type will show here.' },
    { title: 'Upcoming deadlines', phase: 2, icon: CalendarClock, description: 'Tasks due in the coming days will show here.' },
];

const monthStats = [
    { label: 'Income', phase: 8, icon: TrendingUp },
    { label: 'Expenses', phase: 8, icon: Receipt },
    { label: 'Payroll', phase: 9, icon: HandCoins },
    { label: 'Operating result', phase: 8, icon: Scale },
];
</script>

<template>
    <Head title="Company dashboard" />

    <div class="flex flex-col gap-6">
        <PageHeader :greeting-name="greetingName" :today="today" />

        <section aria-label="Today at a glance" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard
                label="Active employees"
                :value="stats.activeEmployees"
                sub="Current headcount"
                :icon="Users"
            />
            <StatCard
                v-for="stat in placeholderStats"
                :key="stat.label"
                :label="stat.label"
                :phase="stat.phase"
                :icon="stat.icon"
            />
        </section>

        <section aria-label="Work overview" class="grid gap-4 lg:grid-cols-2">
            <PlaceholderPanel
                v-for="panel in panels"
                :key="panel.title"
                :title="panel.title"
                :phase="panel.phase"
                :description="panel.description"
                :icon="panel.icon"
            />
        </section>

        <section aria-labelledby="this-month" class="flex flex-col gap-4">
            <h2 id="this-month" class="text-base font-semibold tracking-tight">This month</h2>
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    v-for="stat in monthStats"
                    :key="stat.label"
                    :label="stat.label"
                    :phase="stat.phase"
                    :icon="stat.icon"
                />
            </div>
        </section>
    </div>
</template>
