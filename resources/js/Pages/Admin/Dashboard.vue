<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    CalendarClock,
    CircleAlert,
    ClipboardCheck,
    CircleCheck,
    FolderKanban,
    HandCoins,
    Inbox,
    Plus,
    Receipt,
    Scale,
    TrendingUp,
    UserCheck,
    Users,
} from '@lucide/vue';
import type { Component } from 'vue';
import AttentionList from '@/Components/Dashboard/AttentionList.vue';
import AreaTrend from '@/Components/Charts/AreaTrend.vue';
import DonutBreakdown from '@/Components/Charts/DonutBreakdown.vue';
import PageShell from '@/Components/PageShell.vue';
import StatCard from '@/Components/StatCard.vue';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import AdminLayout from '@/Layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

/** A tier-1 card: the label, the count, and the list it opens. All three from the server. */
interface WorkStat {
    key: string;
    label: string;
    count: number;
    href: string;
}

defineProps<{
    greetingName: string;
    today: string;
    stats: {
        activeEmployees: number;
    };
    /**
     * The plan's five task cards for this dashboard — Tasks due today, Overdue, Awaiting
     * review, Completed today, Active projects. Every one is a server-side COUNT scoped by
     * `Task::visibleTo()` / `Project::visibleTo()` over the whole agency (this is the Company
     * dashboard, not the Admin's own plate — that is `/admin/my-tasks`), and every one links
     * to the same query as a list.
     */
    workStats: WorkStat[];
}>();

/**
 * The icon each of the five wears. Icons are components, so they cannot come from PHP; the
 * label, the number and the destination all do.
 */
const WORK_ICON: Record<string, Component> = {
    due_today: CalendarClock,
    overdue: CircleAlert,
    in_review: ClipboardCheck,
    completed_today: CircleCheck,
    active_projects: FolderKanban,
};

/**
 * What each card says under its number, at zero and above it.
 *
 * Zero is an answer and reads like one: "Nothing is late" is the best line on this page. It
 * is also the second encoding — an overdue count that were only emphasised would say nothing
 * in greyscale and nothing to a screen reader (DESIGN.md §6 rule 6).
 */
const WORK_SUB: Record<string, { zero: string; some: string }> = {
    due_today: { zero: 'Nothing due today', some: 'Due before the day is out' },
    overdue: { zero: 'Nothing is late', some: 'Past their due date' },
    in_review: { zero: 'Nothing waiting on a reviewer', some: 'Waiting on a reviewer' },
    completed_today: { zero: 'None finished yet today', some: 'Finished today' },
    active_projects: { zero: 'No active projects', some: 'Being worked on' },
};

function subFor(stat: WorkStat): string {
    const copy = WORK_SUB[stat.key];

    return copy ? (stat.count === 0 ? copy.zero : copy.some) : '';
}

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

        <!--
            Tier 1 — the five the plan names, and what an Admin opens this page for: what is
            late and what is waiting on them. Each one leads to the list it counted.
        -->
        <section
            v-if="workStats.length > 0"
            aria-label="Work today"
            class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5"
        >
            <StatCard
                v-for="stat in workStats"
                :key="stat.key"
                :label="stat.label"
                :value="stat.count"
                :sub="subFor(stat)"
                :icon="WORK_ICON[stat.key]"
                :href="stat.href"
            />
        </section>

        <!--
            Tier 1b — the people numbers, at a lower weight than the work. "Present today"
            keeps its "Arrives in Phase 4" marker: attendance is not built, and a card that
            quietly went blank would read as nobody being in.
        -->
        <section aria-label="Team today" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard
                size="compact"
                label="Active employees"
                :value="stats.activeEmployees"
                sub="Current headcount · no history to compare yet"
                :icon="Users"
            />
            <StatCard size="compact" label="Present today" :phase="4" :icon="UserCheck" />
        </section>

        <!--
            Tier 2 — the block that tells you what to do, before any chart.

            It still has no feed: no controller sends `items`, so it draws its empty state. The
            copy had to change all the same. It promised "overdue work" in "Phases 2–5" while
            the row of cards directly above it was already counting six overdue tasks and one
            awaiting review — a panel saying nothing needs you, under a number saying six things
            do. The work Phase 2 delivered is named where it actually lives; the marker keeps
            only the phases that have not happened.
        -->
        <AttentionList
            title="Needs your attention"
            :empty-icon="Inbox"
            empty-title="Nothing is queued here yet"
            empty-description="Overdue work and the review queue are the cards above. Approvals and leave decisions arrive in Phases 3–5."
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
