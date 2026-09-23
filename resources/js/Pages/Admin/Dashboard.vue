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
import { computed, type Component } from 'vue';
import AttentionList, { type AttentionItem } from '@/Components/Dashboard/AttentionList.vue';
import AreaTrend from '@/Components/Charts/AreaTrend.vue';
import DonutBreakdown, { type DonutSlice } from '@/Components/Charts/DonutBreakdown.vue';
import type { StatusKey } from '@/Components/StatusBadge.vue';
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

/**
 * One thing this Admin should open. The server decides what is on the list, what it says and
 * where it goes; the screen only chooses the icon, which cannot travel as JSON.
 */
interface AttentionRow {
    id: string;
    /** `review` or `overdue` — which of the panel's sources put it here. */
    kind: string;
    title: string;
    meta: string;
    href: string;
    tone: 'default' | 'urgent';
}

/** One slice of "Tasks by status", already counted and already toned by the server. */
interface TaskStatusCount {
    key: string;
    label: string;
    tone: string;
    count: number;
}

const props = defineProps<{
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
    /**
     * The tier-2 panel's feed: tasks awaiting this Admin's review, then what is overdue. Every
     * row is a query scoped by `Task::visibleTo()` with its predicate from `TaskBucket`, and
     * every row carries the URL of the task itself. Empty when they hold no `tasks.view`.
     */
    attention: AttentionRow[];
    /** Open tasks per status, for the donut. Server-counted, server-toned. */
    taskStatuses: TaskStatusCount[];
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
 * The icon each kind of attention row wears. Same split as WORK_ICON above and for the same
 * reason: an icon is a component, so it cannot come from PHP. What the row SAYS, where it goes
 * and whether it is urgent all do.
 */
const ATTENTION_ICON: Record<string, Component> = {
    review: ClipboardCheck,
    overdue: CircleAlert,
};

const attentionItems = computed<AttentionItem[]>(() =>
    props.attention.map((row) => ({
        id: row.id,
        icon: ATTENTION_ICON[row.kind] ?? Inbox,
        title: row.title,
        meta: row.meta,
        href: row.href,
        tone: row.tone,
    })),
);

/**
 * The donut's slices. The tone is the server's `TaskStatus::tone()`, which is the same mapping
 * every `StatusBadge` on every other screen uses — so the ring and the badges agree, and there
 * is no second copy of it here to drift (decision 2-28's reasoning, applied to a colour).
 */
const taskStatusSlices = computed<DonutSlice[]>(() =>
    props.taskStatuses.map((status) => ({
        label: status.label,
        value: status.count,
        tone: status.tone as StatusKey,
    })),
);

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

            It has a feed now (decision 2-50): what is waiting on this Admin's verdict, then
            what is late. Both are server queries scoped by `Task::visibleTo()`, both link to
            the task, and the empty state is now a real answer rather than a placeholder — the
            panel used to sit under a card counting six overdue tasks saying nothing needed
            anybody.

            The `note` stays whether or not there are rows. Two of this panel's four sources are
            not built, and a list that silently dropped that sentence the moment it had one item
            in it would claim to be the whole of what needs you.
        -->
        <AttentionList
            title="Needs your attention"
            :items="attentionItems"
            :empty-icon="Inbox"
            empty-title="Nothing is waiting on you"
            empty-description="No task is sitting in your review queue, and nothing is past its due date."
            note="Tasks only for now — approvals arrive in Phases 8–9 and leave decisions in Phase 5."
        />

        <!-- Tier 3 — two charts, the screen's whole chart budget. -->
        <section aria-label="Work and attendance" class="grid gap-4 lg:grid-cols-2">
            <Card class="min-w-0 gap-4 p-6 shadow-xs">
                <h2 class="text-sm font-medium">Tasks by status</h2>
                <div class="flex min-h-56 min-w-0 flex-col justify-center">
                    <!--
                        The open work, split by status — the same scoped, non-archived set the
                        cards above are counted from, so the centre number and "Overdue" can be
                        reconciled against each other. It was `:data="[]"` under copy reading
                        "fills in once there is something to count" on a dashboard with 25
                        tasks on it.
                    -->
                    <DonutBreakdown :data="taskStatusSlices" center-label="Open tasks" />
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
