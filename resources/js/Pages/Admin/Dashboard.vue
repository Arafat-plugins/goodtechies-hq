<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    CalendarClock,
    CircleAlert,
    HandCoins,
    Inbox,
    Plus,
    Receipt,
    Scale,
    TrendingUp,
    UserCheck,
    Users,
} from '@lucide/vue';
import AttentionList from '@/Components/Dashboard/AttentionList.vue';
import AreaTrend from '@/Components/Charts/AreaTrend.vue';
import DonutBreakdown from '@/Components/Charts/DonutBreakdown.vue';
import PageShell from '@/Components/PageShell.vue';
import StatCard from '@/Components/StatCard.vue';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import AdminLayout from '@/Layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

defineProps<{
    greetingName: string;
    today: string;
    stats: {
        activeEmployees: number;
    };
}>();

/**
 * Tier 1 is the four numbers the day is run on. Three of them have no data source until
 * their phase lands, so they carry the "—" and the phase line rather than a stand-in
 * number; none of the four carries a delta, because nothing on this page can be compared
 * against last week yet — attendance and task history arrive in Phases 2 and 4.
 */
const heroStats = [
    { label: 'Present today', phase: 4, icon: UserCheck },
    { label: 'Overdue tasks', phase: 2, icon: CircleAlert },
    { label: 'Tasks due today', phase: 2, icon: CalendarClock },
];

/**
 * Tier 4. Money is a footnote on the company dashboard, so these are the compact card:
 * same anatomy, smaller number, clearly below the hero row. The sparkline slot is left
 * unfilled until Phase 8 has a series to draw.
 */
const monthStats = [
    { label: 'Income', phase: 8, icon: TrendingUp },
    { label: 'Expenses', phase: 8, icon: Receipt },
    { label: 'Payroll', phase: 9, icon: HandCoins },
    { label: 'Operating result', phase: 8, icon: Scale },
];
</script>

<template>
    <Head title="Company dashboard" />

    <PageShell title="Company dashboard" :greeting="{ name: greetingName, today }">
        <template #actions>
            <Button as-child>
                <Link href="/admin/projects/create">
                    <Plus aria-hidden="true" />
                    New project
                </Link>
            </Button>
        </template>

        <!-- Tier 1 — four KPIs, nothing else at this weight. -->
        <section aria-label="Today" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard
                label="Active employees"
                :value="stats.activeEmployees"
                sub="Current headcount · no history to compare yet"
                :icon="Users"
            />
            <StatCard
                v-for="stat in heroStats"
                :key="stat.label"
                :label="stat.label"
                :phase="stat.phase"
                :icon="stat.icon"
            />
        </section>

        <!-- Tier 2 — the block that tells you what to do, before any chart. -->
        <AttentionList
            title="Needs your attention"
            :empty-icon="Inbox"
            empty-title="Nothing needs you right now"
            empty-description="Approvals, overdue work and leave decisions arrive in Phases 2–5"
        />

        <!-- Tier 3 — two charts, the screen's whole chart budget. -->
        <section aria-label="Work and attendance" class="grid gap-4 lg:grid-cols-2">
            <Card class="min-w-0 gap-4 p-6 shadow-xs">
                <h2 class="text-sm font-medium">Tasks by status</h2>
                <div class="flex min-h-56 min-w-0 flex-col justify-center">
                    <DonutBreakdown :data="[]" center-label="Open tasks" />
                </div>
            </Card>
            <Card class="min-w-0 gap-4 p-6 shadow-xs">
                <h2 class="text-sm font-medium">Attendance, last 14 days</h2>
                <div class="flex min-h-56 min-w-0 flex-col justify-center">
                    <AreaTrend :data="[]" label="Present" />
                </div>
            </Card>
        </section>

        <!-- Tier 4 — this month, at the bottom and at a lower weight. -->
        <section aria-labelledby="this-month" class="flex flex-col gap-4">
            <h2 id="this-month" class="text-base font-semibold tracking-tight">This month</h2>
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    v-for="stat in monthStats"
                    :key="stat.label"
                    size="compact"
                    :label="stat.label"
                    :phase="stat.phase"
                    :icon="stat.icon"
                />
            </div>
        </section>
    </PageShell>
</template>
