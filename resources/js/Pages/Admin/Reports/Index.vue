<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ChartColumn } from '@lucide/vue';
import EmptyState from '@/Components/EmptyState.vue';
import PageShell from '@/Components/PageShell.vue';
import type { ReportIndexPayload, ReportSummary } from '@/Components/Reports/reports';
import { reportFilterLabel, reportRoutes } from '@/Components/Reports/reports';
import { Card } from '@/Components/ui/card';
import AdminLayout from '@/Layouts/AdminLayout.vue';

/**
 * Admin → Reports: the catalogue (Part D §15, contract §5).
 *
 * **Every card on this page is a report the viewer may actually open.** The list is the
 * server's answer to "which reports does this person hold the permission for" — a report they
 * may not read is *absent* from the payload, not greyed out and not hidden in Vue. There is no
 * permission logic on this screen, here or anywhere else in `Pages/Admin/Reports` (Part C §1).
 *
 * A card carries the report's own question, because "Task" is not a description of anything
 * and "How much work is there, and what state is it in?" is. It also lists what the report can
 * be narrowed by, so the choice between two reports can be made here rather than by opening
 * both.
 *
 * ## No export control
 *
 * Not a disabled one either. PDF and CSV are spec post-MVP (§44), and a greyed-out *Export*
 * is a promise with a date nobody set (contract §6, DESIGN.md §5.12).
 */
defineOptions({ layout: AdminLayout });

/**
 * The bands are the server's, in the server's order, already emptied of anything this viewer
 * may not read. Nothing on this page re-groups, re-orders or filters them.
 */
defineProps<ReportIndexPayload>();

/** "Date range · Employee · Project", or the sentence for a report that takes none. */
function filterLine(report: ReportSummary): string {
    if (report.filters.length === 0) {
        return 'Takes no filters';
    }

    return `Filter by ${report.filters.map(reportFilterLabel).join(' · ')}`;
}
</script>

<template>
    <Head title="Reports" />

    <PageShell
        title="Reports"
        description="Each one is a cut of the live tables, read at the moment you open it."
    >
        <EmptyState
            v-if="groups.length === 0"
            :icon="ChartColumn"
            title="No reports for your account"
            description="A report needs the permission of the data it reads, and none of them matches what you hold."
        />

        <section v-for="group in groups" :key="group.key" class="flex min-w-0 flex-col gap-4">
            <h2 class="text-base font-semibold tracking-tight">{{ group.label }}</h2>

            <ul class="grid min-w-0 gap-4 md:grid-cols-2 xl:grid-cols-3">
                <li v-for="report in group.reports" :key="report.key" class="min-w-0">
                    <!-- One tab stop per card, and the whole card is the hit area. -->
                    <Link
                        :href="reportRoutes.show(report.key)"
                        class="block h-full min-w-0 rounded-xl outline-none focus-visible:ring-3 focus-visible:ring-ring"
                    >
                        <Card class="h-full min-w-0 gap-2 p-4 transition-colors hover:bg-accent/40">
                            <h3 class="text-sm font-medium">{{ report.label }}</h3>
                            <p class="text-sm text-muted-foreground">{{ report.question }}</p>
                            <p class="text-xs text-muted-foreground">{{ filterLine(report) }}</p>
                        </Card>
                    </Link>
                </li>
            </ul>
        </section>
    </PageShell>
</template>
