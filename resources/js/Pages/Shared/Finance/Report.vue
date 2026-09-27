<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { FolderKanban, LayoutDashboard } from '@lucide/vue';
import { computed } from 'vue';
import AreaTrend, { type AreaTrendPoint } from '@/Components/Charts/AreaTrend.vue';
import BarCompare, { type BarCompareItem } from '@/Components/Charts/BarCompare.vue';
import EmptyState from '@/Components/EmptyState.vue';
import FinanceMonthNav from '@/Components/Finance/FinanceMonthNav.vue';
import LedgerTotals from '@/Components/Finance/LedgerTotals.vue';
import type { FinanceMonth, MonthlyRollup } from '@/Components/Finance/finance';
import { financeRoutes, formatMoney } from '@/Components/Finance/finance';
import {
    financeTrendHref,
    moneyValue,
    type FinanceProjectCut,
    type FinanceTrend,
} from '@/Components/Finance/financeReport';
import PageShell from '@/Components/PageShell.vue';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import AccountantLayout from '@/Layouts/AccountantLayout.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import EmployeeLayout from '@/Layouts/EmployeeLayout.vue';
import type { SharedProps } from '@/types';

/**
 * The Monthly financial report — Part D §13's three cuts: **by category, by project, trend.**
 *
 * Shared, like the Finance dashboard beside it, and for the same reason: the books belong to
 * the agency rather than to a shell. The layout comes from `auth.user.surface`, the route group
 * is `can:finance.view`, and nobody without the key gets this far.
 *
 * **The whole window is in the URL** — `?month=2026-09&months=12` — so *"the year to September
 * 2026"* is an address. A trend whose length the controller chose privately is a chart nobody
 * can cite.
 *
 * ## Two charts, and where they are not
 *
 * The trend gets a line and the projects get bars. The by-category cut here is **tables only**:
 * its picture is on the Finance dashboard, one click away and for the same month, and drawing
 * it twice would spend a chart on a second copy of one answer. The figures are here in full
 * either way — exact numbers are what a report is for.
 *
 * Each chart on this page is **`aria-hidden` together with the screen-reader table its wrapper
 * ships**, because the visible table beneath it is the better accessible copy: it carries the
 * currency and the total. That is the same decision `FinanceCategoryChart` documents.
 *
 * ## Nothing on this page is computed here
 *
 * Every amount is a string the server made in integer cents. `moneyValue()` turns one into a
 * mark's length and `formatMoney()` turns one into text; nothing adds two of them together.
 */

defineOptions({
    layout: (props: SharedProps) => {
        const surface = props.auth.user?.surface;

        if (surface === 'admin') {
            return AdminLayout;
        }

        return surface === 'accountant' ? AccountantLayout : EmployeeLayout;
    },
});

const props = defineProps<{
    month: FinanceMonth & { is_current: boolean };
    rollup: MonthlyRollup;
    /**
     * Income grouped by the project it was billed against, plus one row for income that was
     * never linked to one. **Income only** — see the note the page prints above the table.
     */
    byProject: FinanceProjectCut;
    trend: FinanceTrend;
    currency: string;
    dashboardHref: string;
}>();

/** The three windows the length control offers. Each is a link, so each is an address. */
const TREND_WINDOWS = [6, 12, 24] as const;

/** Stepping a month keeps the trend length you were looking at. */
const monthHref = (month: string): string => financeTrendHref(month, props.trend.months);

const windowHref = (months: number): string => financeTrendHref(props.month.value, months);

/** The net line. `y` is a length; every figure a reader acts on is the string in the table. */
const netPoints = computed<AreaTrendPoint[]>(() =>
    props.trend.points.map((point) => ({ x: point.short_label, y: moneyValue(point.net) })),
);

const projectBars = computed<BarCompareItem[]>(() =>
    props.byProject.rows.map((row) => ({ label: row.name, value: moneyValue(row.total) })),
);

const hasProjects = computed(() => props.byProject.rows.length > 0);

/** "October 2025 to September 2026", for the trend's heading and its table caption. */
const trendRange = computed(() => {
    const first = props.trend.points[0];
    const last = props.trend.points[props.trend.points.length - 1];

    return first && last ? `${first.label} to ${last.label}` : props.month.label;
});
</script>

<template>
    <Head :title="`Monthly financial report — ${month.label}`" />

    <PageShell
        title="Monthly financial report"
        description="One month by category, that month by project, and how the year around it has run."
    >
        <template #actions>
            <Button as-child variant="outline">
                <Link :href="dashboardHref">
                    <LayoutDashboard aria-hidden="true" />
                    Finance dashboard
                </Link>
            </Button>
        </template>

        <section aria-labelledby="report-month" class="flex min-w-0 flex-col gap-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex min-w-0 flex-col gap-0.5">
                    <h2 id="report-month" class="text-lg font-semibold tracking-tight">{{ month.label }}</h2>
                    <p class="text-xs text-muted-foreground">
                        {{ month.is_current ? 'This month, still in progress' : 'A closed month' }}
                    </p>
                </div>

                <FinanceMonthNav :month="month" :href="monthHref" />
            </div>

            <div class="grid min-w-0 gap-4 sm:grid-cols-3">
                <Card class="min-w-0 gap-1 p-4">
                    <p class="text-sm text-muted-foreground">Income</p>
                    <p class="text-2xl font-semibold tabular-nums">
                        {{ formatMoney(rollup.income.total, currency) }}
                    </p>
                </Card>
                <Card class="min-w-0 gap-1 p-4">
                    <p class="text-sm text-muted-foreground">Expenses</p>
                    <p class="text-2xl font-semibold tabular-nums">
                        {{ formatMoney(rollup.expenses.total, currency) }}
                    </p>
                </Card>
                <Card class="min-w-0 gap-1 p-4">
                    <p class="text-sm text-muted-foreground">Net</p>
                    <p class="text-2xl font-semibold tabular-nums">{{ formatMoney(rollup.net, currency) }}</p>
                </Card>
            </div>
        </section>

        <!-- ── Cut one: by category ─────────────────────────────────────────────────── -->
        <section aria-labelledby="report-by-category" class="flex min-w-0 flex-col gap-4">
            <div class="flex min-w-0 flex-col gap-1">
                <h2 id="report-by-category" class="text-base font-semibold tracking-tight">
                    By category — {{ month.label }}
                </h2>
                <p class="text-sm text-muted-foreground">
                    A category with nothing filed under it this month is not on these lists. The same cut is drawn
                    as a chart on the
                    <Link :href="dashboardHref" class="underline underline-offset-4">Finance dashboard</Link>.
                </p>
            </div>

            <div class="grid min-w-0 gap-4 lg:grid-cols-2">
                <LedgerTotals
                    title="Income"
                    :caption="`Income by category, ${month.label}`"
                    :side="rollup.income"
                    :currency="currency"
                    empty-text="No income is recorded against this month."
                    total-label="Total income"
                />
                <LedgerTotals
                    title="Expenses"
                    :caption="`Expenses by category, ${month.label}`"
                    :side="rollup.expenses"
                    :currency="currency"
                    empty-text="No expense is recorded against this month."
                    total-label="Total expenses"
                />
            </div>
        </section>

        <!-- ── Cut two: by project ──────────────────────────────────────────────────── -->
        <section aria-labelledby="report-by-project" class="flex min-w-0 flex-col gap-4">
            <div class="flex min-w-0 flex-col gap-1">
                <h2 id="report-by-project" class="text-base font-semibold tracking-tight">
                    By project — {{ month.label }}
                </h2>
                <!--
                    The sentence this cut cannot do without. An expense has no project link
                    anywhere in this system (Part D §20), so there is no per-project cost — and
                    an empty expense column beside the income one would invite exactly the wrong
                    conclusion, that projects cost nothing. Said in words, once, above the table.
                -->
                <p class="text-sm text-muted-foreground">
                    <strong class="font-medium text-foreground">Income only.</strong>
                    An expense is not linked to a project anywhere in this system, so there is no per-project cost
                    to set beside these figures — and an empty column would read as though projects cost nothing.
                    A project appears here by name and domain; nothing else about it belongs on a finance screen.
                </p>
            </div>

            <Card class="min-w-0">
                <CardHeader>
                    <CardTitle>Income by project</CardTitle>
                </CardHeader>
                <CardContent class="flex min-w-0 flex-col gap-4">
                    <EmptyState
                        v-if="!hasProjects"
                        :icon="FolderKanban"
                        variant="empty"
                        title="No income this month"
                        description="Once income is recorded for this month it is grouped here by the project it was billed against."
                    />

                    <template v-else>
                        <!--
                            Decoration; the table below is the accessible copy of the figures.
                            `[&_.sr-only]:hidden` removes `BarCompare`'s own screen-reader
                            table from the LAYOUT as well as from the accessibility tree —
                            `sr-only` does not clip a `<table>`, so a long project name in the
                            hidden fallback pushes the page sideways at 360 px. See
                            `FinanceCategoryChart` for the measurement.
                        -->
                        <div aria-hidden="true" class="min-w-0 [&_.sr-only]:hidden">
                            <BarCompare
                                :data="projectBars"
                                orientation="horizontal"
                                label="Income"
                                :height="Math.max(140, projectBars.length * 34)"
                            />
                        </div>

                        <div class="min-w-0 overflow-x-auto">
                            <table class="w-full text-sm">
                                <caption class="sr-only">
                                    Income by project, {{ month.label }}. Expenses are not in this cut.
                                </caption>
                                <thead>
                                    <tr class="border-b border-border">
                                        <th scope="col" class="py-2 pr-3 text-left font-medium text-muted-foreground">
                                            Project
                                        </th>
                                        <th
                                            scope="col"
                                            class="hidden py-2 pr-3 text-left font-medium text-muted-foreground sm:table-cell"
                                        >
                                            Domain
                                        </th>
                                        <th scope="col" class="py-2 text-right font-medium text-muted-foreground">
                                            Income
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="row in byProject.rows"
                                        :key="row.project_id ?? 'unlinked'"
                                        class="border-b border-border/60"
                                    >
                                        <th scope="row" class="py-2 pr-3 text-left font-normal">
                                            {{ row.name }}
                                            <!--
                                                The unlinked row names itself in words rather
                                                than being an odd blank at the bottom of a list.
                                            -->
                                            <span
                                                v-if="row.project_id === null"
                                                class="block text-xs text-muted-foreground"
                                            >
                                                Income not billed against a project
                                            </span>
                                            <span v-else class="block text-xs text-muted-foreground sm:hidden">
                                                {{ row.domain ?? 'No domain' }}
                                            </span>
                                        </th>
                                        <td class="hidden py-2 pr-3 text-muted-foreground sm:table-cell">
                                            {{ row.domain ?? '—' }}
                                        </td>
                                        <td class="py-2 text-right tabular-nums">
                                            {{ formatMoney(row.total, currency) }}
                                        </td>
                                    </tr>
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <th scope="row" class="py-2 pr-3 text-left font-medium">Total income</th>
                                        <td class="hidden sm:table-cell" />
                                        <td class="py-2 text-right font-medium tabular-nums">
                                            {{ formatMoney(byProject.total, currency) }}
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </template>
                </CardContent>
            </Card>
        </section>

        <!-- ── Cut three: the trend ─────────────────────────────────────────────────── -->
        <section aria-labelledby="report-trend" class="flex min-w-0 flex-col gap-4">
            <div class="flex min-w-0 flex-wrap items-end justify-between gap-4">
                <div class="flex min-w-0 flex-col gap-1">
                    <h2 id="report-trend" class="text-base font-semibold tracking-tight">Trend — {{ trendRange }}</h2>
                    <p class="text-sm text-muted-foreground">
                        {{ trend.months }} months to {{ month.label }}. The line is the net; the table has income,
                        expenses and net for every month in the window. A month with nothing recorded is a point at
                        zero, not a gap — dropping it would draw a line straight over it.
                    </p>
                </div>

                <!--
                    The window length is a set of links, so the trend somebody is looking at is
                    the trend they can send. The current one is marked by `aria-current` and by
                    its filled variant — a word and a shape, never colour alone.
                -->
                <div class="flex shrink-0 items-center gap-2" role="group" aria-label="Trend length">
                    <Button
                        v-for="months in TREND_WINDOWS"
                        :key="months"
                        as-child
                        size="sm"
                        :variant="months === trend.months ? 'default' : 'outline'"
                    >
                        <Link :href="windowHref(months)" :aria-current="months === trend.months ? 'true' : undefined">
                            {{ months }} months
                        </Link>
                    </Button>
                </div>
            </div>

            <Card class="min-w-0">
                <CardHeader>
                    <CardTitle>Net by month</CardTitle>
                </CardHeader>
                <CardContent class="flex min-w-0 flex-col gap-4">
                    <!-- Same as the bars above: the plot and its hidden fallback table both go. -->
                    <div aria-hidden="true" class="min-w-0 [&_.sr-only]:hidden">
                        <AreaTrend :data="netPoints" label="Net" :height="220" />
                    </div>

                    <div class="min-w-0 overflow-x-auto">
                        <table class="w-full text-sm">
                            <caption class="sr-only">
                                Income, expenses and net by month, {{ trendRange }}
                            </caption>
                            <thead>
                                <tr class="border-b border-border">
                                    <th scope="col" class="py-2 pr-3 text-left font-medium text-muted-foreground">
                                        Month
                                    </th>
                                    <th scope="col" class="py-2 pr-3 text-right font-medium text-muted-foreground">
                                        Income
                                    </th>
                                    <th scope="col" class="py-2 pr-3 text-right font-medium text-muted-foreground">
                                        Expenses
                                    </th>
                                    <th scope="col" class="py-2 text-right font-medium text-muted-foreground">Net</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="point in trend.points" :key="point.month" class="border-b border-border/60">
                                    <th scope="row" class="py-2 pr-3 text-left font-normal whitespace-nowrap">
                                        <Link
                                            :href="financeRoutes.dashboard(point.month)"
                                            class="underline-offset-4 hover:underline"
                                        >
                                            {{ point.label }}
                                        </Link>
                                    </th>
                                    <td class="py-2 pr-3 text-right tabular-nums">
                                        {{ formatMoney(point.income, currency) }}
                                    </td>
                                    <td class="py-2 pr-3 text-right tabular-nums">
                                        {{ formatMoney(point.expense, currency) }}
                                    </td>
                                    <td class="py-2 text-right tabular-nums">
                                        {{ formatMoney(point.net, currency) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </CardContent>
            </Card>
        </section>
    </PageShell>
</template>
