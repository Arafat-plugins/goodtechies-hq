<script setup lang="ts">
import { computed } from 'vue';
import AreaTrend, { type AreaTrendPoint } from '@/Components/Charts/AreaTrend.vue';
import BarCompare, { type BarCompareItem } from '@/Components/Charts/BarCompare.vue';
import DonutBreakdown, { type DonutSlice } from '@/Components/Charts/DonutBreakdown.vue';
import type { ReportChart } from '@/Components/Reports/reports';
import { reportChartFormatter, reportChartValue, reportStatusKey } from '@/Components/Reports/reports';
import { Card } from '@/Components/ui/card';
import { cn } from '@/lib/utils';

/**
 * A report's pictures — at most three of them (Part D §3).
 *
 * The cap is `ReportResult`'s constructor, which throws on a fourth, so nothing here has to
 * remember it; the slice below is the belt to that braces and is not a way to pass four.
 *
 * Three kinds and no report keys: `bar`, `donut`, `area`, each one of the three wrappers that
 * already exist. No fourth chart component is added in this phase, and none of them learns a
 * colour from here — `chartTokens.ts` is the only place a chart reads one.
 *
 * **Every figure a person acts on is in the table below these, not in a mark.** A chart's
 * value is a length: `reportChartValue()` turns a money string into one for the geometry, and
 * the exact amount stays the server's string in the table.
 *
 * **How a chart writes its figures is the server's, not a caption's.** Each chart carries a
 * `ReportFormat`, and it is bound into one `valueFormat` that the wrapper passes down — so the
 * axis, the tooltip, the legend and the screen-reader table all say `432h`, and none of them
 * says `432` under a title that has had to smuggle the unit in.
 */
const props = defineProps<{
    charts: ReportChart[];
    /** `settings.currency`. Only a `money` chart reads it. */
    currency: string;
}>();

/** The chart's own format, bound once — the same function `ReportCell` renders a cell with. */
const formatter = (chart: ReportChart) => reportChartFormatter(chart.format, props.currency);

const shown = computed(() => props.charts.slice(0, 3));

const bars = (chart: ReportChart): BarCompareItem[] =>
    chart.series.map((point) => ({ label: point.label, value: reportChartValue(point.value) }));

const slices = (chart: ReportChart): DonutSlice[] =>
    chart.series.map((point) => {
        const tone = reportStatusKey(point.tone ?? null);

        return {
            label: point.label,
            value: reportChartValue(point.value),
            // A slice keeps its status colour when the server named one, so a breakdown by
            // status agrees with the badges in the table. `undefined` takes the next
            // categorical slot instead — never a colour decided here.
            ...(tone ? { tone } : {}),
        };
    });

const points = (chart: ReportChart): AreaTrendPoint[] =>
    chart.series.map((point) => ({ x: point.label, y: reportChartValue(point.value) }));
</script>

<template>
    <div
        v-if="shown.length > 0"
        :class="
            cn(
                'grid min-w-0 gap-4',
                shown.length > 1 && 'md:grid-cols-2',
                shown.length > 2 && 'xl:grid-cols-3',
            )
        "
    >
        <Card v-for="(chart, index) in shown" :key="`${chart.kind}-${index}`" class="min-w-0 gap-3 p-4">
            <h3 class="text-sm font-medium">{{ chart.title }}</h3>

            <!--
                Each wrapper ships its own screen-reader table, inside a clipping `div.sr-only`
                of its own. Nothing is added around them here: an `sr-only` table that is not
                wrapped in a box is sized by its content and pushes the page sideways while
                staying invisible, which is a defect this repo has already measured once.
            -->
            <BarCompare
                v-if="chart.kind === 'bar'"
                :data="bars(chart)"
                :label="chart.title"
                :value-format="formatter(chart)"
            />
            <DonutBreakdown
                v-else-if="chart.kind === 'donut'"
                :data="slices(chart)"
                :center-label="chart.title"
                :value-format="formatter(chart)"
            />
            <AreaTrend v-else :data="points(chart)" :label="chart.title" :value-format="formatter(chart)" />
        </Card>
    </div>
</template>
