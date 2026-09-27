<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { FINANCE_DEFAULT_CURRENCY } from '@/Components/Finance/finance';
import PageShell from '@/Components/PageShell.vue';
import ReportBody from '@/Components/Reports/ReportBody.vue';
import ReportFilterBar from '@/Components/Reports/ReportFilterBar.vue';
import type { ReportShowPayload } from '@/Components/Reports/reports';
import { reportRoutes } from '@/Components/Reports/reports';
import { queryOf, resetQuery } from '@/lib/tableState';
import AdminLayout from '@/Layouts/AdminLayout.vue';

/**
 * **One page component for all sixteen reports**, present and future (contract §5).
 *
 * There is no report key in this file and there must never be one. Everything that differs
 * between a payroll report and an overdue-task report arrives as data: the title, the
 * question under it, which chips the bar offers, the columns and their formats, the rows, the
 * footer, up to three charts, the notes and the sentence shown when there is nothing. Adding
 * the next eight reports is adding a builder in PHP; this screen does not change.
 *
 * A `switch` on a report key here would be the failure the contract was written to prevent —
 * if one ever looks necessary, the contract has a gap and the gap is the thing to report.
 *
 * ## Privacy
 *
 * None of it is decided here. A column the viewer may not read is **absent** from `columns`,
 * so the renderer never learns that a salary exists (Part C §1). No role is read on this page,
 * and `auth.user` is not consulted.
 *
 * ## The filters are the URL
 *
 * Every chip and both dates live in the query string, so a report narrowed to one project in
 * one month is an address somebody can paste to a colleague and get the same page. That is
 * also why *Clear filters* is `resetQuery()`: it drops every parameter and the server's own
 * defaults — the current calendar month — come back.
 */
defineOptions({ layout: AdminLayout });

const props = defineProps<ReportShowPayload>();

const page = usePage();

/**
 * Has somebody narrowed this, or is it the window the server chose?
 *
 * Read from the URL rather than from the echoed values, because `from` and `to` are always
 * present in the payload: `ReportRequest` defaults them to the current month. A report that
 * called its own default month "a filter" would offer to clear September for arriving in it.
 */
const filtersActive = computed(() => {
    const query = queryOf(page.url);

    return ['from', 'to', 'employee', 'project', 'client'].some((key) => query[key] !== undefined);
});

const title = computed(() => `${props.report.label} report`);

/**
 * `settings.currency` — which this controller does not send yet, so the seeded value stands in.
 * Every `money` cell wears it (contract §3), and the fallback is the same one `finance.ts`
 * keeps for a screen that has not been handed the prop: right on the seeded install, wrong the
 * day somebody changes the setting. It is one line in `ReportController` and is raised with
 * this slice.
 */
const currency = computed(() => props.currency ?? FINANCE_DEFAULT_CURRENCY);
</script>

<template>
    <Head :title="title" />

    <PageShell
        :title="title"
        :description="report.question"
        :breadcrumb="[{ label: 'Reports', href: reportRoutes.index }, { label: report.label }]"
    >
        <ReportFilterBar :report="report" :filters="filters" :options="options" />

        <ReportBody
            :id="`admin-report-${report.key}`"
            :result="result"
            :currency="currency"
            :filters-active="filtersActive"
            @clear="resetQuery()"
        />
    </PageShell>
</template>
