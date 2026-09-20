<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { CalendarOff, FileText, HandCoins, Receipt, Scale, TrendingUp } from '@lucide/vue';
import PageHeader from '@/Components/PageHeader.vue';
import PlaceholderPanel from '@/Components/PlaceholderPanel.vue';
import StatCard from '@/Components/StatCard.vue';
import AccountantLayout from '@/Layouts/AccountantLayout.vue';

defineOptions({ layout: AccountantLayout });

defineProps<{
    greetingName: string;
    today: string;
}>();

const stats = [
    { label: 'Income this month', phase: 8, icon: TrendingUp },
    { label: 'Expenses this month', phase: 8, icon: Receipt },
    { label: 'Net', phase: 8, icon: Scale },
    { label: 'Payroll status', phase: 9, icon: HandCoins },
];

const panels = [
    { title: 'My leave', phase: 5, icon: CalendarOff, description: 'Your leave balance and requests will show here.' },
    { title: 'My payslip', phase: 9, icon: FileText, description: 'Your latest payslip will show here.' },
];
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex flex-col gap-6">
        <PageHeader :greeting-name="greetingName" :today="today" />

        <section aria-label="This month" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard
                v-for="stat in stats"
                :key="stat.label"
                :label="stat.label"
                :phase="stat.phase"
                :icon="stat.icon"
            />
        </section>

        <section aria-label="Me" class="grid gap-4 md:grid-cols-2">
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
