<script lang="ts">
/** One bar. The label names the category; the chart is always one series. */
export interface BarCompareItem {
    label: string;
    value: number;
}
</script>

<script setup lang="ts">
import { computed } from 'vue';
import { ChartColumn } from '@lucide/vue';
import { Orientation, StackedBar } from '@unovis/ts';
import { VisAxis, VisStackedBar, VisTooltip, VisXYContainer } from '@unovis/vue';
import EmptyState from '@/Components/EmptyState.vue';
import { Skeleton } from '@/Components/ui/skeleton';
import { cn } from '@/lib/utils';
import { chartCssVars, chartTooltip, niceTicks, useChartTokens } from './chartTokens';

const props = withDefaults(
    defineProps<{
        data: BarCompareItem[];
        orientation?: 'vertical' | 'horizontal';
        /** Names the series. One series means no legend. */
        label?: string;
        height?: number;
        loading?: boolean;
        /**
         * How a value is written out — in the axis, in the tooltip, in the legend and in the
         * screen-reader table, so all four agree.
         *
         * Defaults to grouped digits, which is right for a count. A chart of money or of
         * durations passes its own: **a chart whose axis reads `432` when the table under it
         * reads `432h` is a chart the reader has to be told how to read**, and the unit
         * smuggled into the title is a caption doing a scale's job.
         *
         * It takes a number because a mark's geometry is a number; the exact value — a money
         * string from PostgreSQL, say — stays in the table beside the chart (report contract
         * §3).
         */
        valueFormat?: (value: number) => string;
    }>(),
    { orientation: 'vertical', height: 200, loading: false, valueFormat: (value: number): string => value.toLocaleString() },
);
/** The caller's formatter, or grouped digits. One indirection so every site below agrees. */
const fmt = (value: number): string => props.valueFormat(value);


const tokens = useChartTokens();
const cssVars = computed(() => chartCssVars(tokens.value));

/** One series, so one accent: `--chart-1`. Bars are compared by length, not by colour. */
const accent = computed(() => tokens.value.series[0]);

const hasData = computed(() => props.data.length > 0);
const isHorizontal = computed(() => props.orientation === 'horizontal');

/**
 * unovis always reads `x` as the category and `y` as the value; orientation swaps the
 * scales. A horizontal chart's category scale runs upwards, so the order is flipped to
 * put the first item at the top, where a reader starts.
 */
const slot = (index: number): number => (isHorizontal.value ? props.data.length - 1 - index : index);

/**
 * Recomputed when the orientation flips, so unovis sees a prop change and redraws. The
 * getter reads the values it depends on itself; returning a bare closure would track
 * nothing.
 */
const x = computed(() => {
    const flip = isHorizontal.value;
    const last = props.data.length - 1;

    return (_: BarCompareItem, index: number): number => (flip ? last - index : index);
});
const y = (item: BarCompareItem): number => item.value;

function labelAt(tick: number | Date): string {
    if (typeof tick !== 'number' || !Number.isInteger(tick)) {
        return '';
    }

    return props.data[slot(tick)]?.label ?? '';
}

const valueFormat = (tick: number | Date): string =>
    typeof tick === 'number' ? fmt(tick) : String(tick);

const barOrientation = computed(() => (isHorizontal.value ? Orientation.Horizontal : Orientation.Vertical));

/** A tick per bar, so every category is named. */
const categoryTicks = computed(() => props.data.map((_, index) => index));

/** The one faint rule on the value axis, at round values. */
const valueTicks = computed(() => niceTicks(Math.max(0, ...props.data.map((item) => item.value))));

const triggers = computed(() => ({
    [StackedBar.selectors.bar]: (bar: { datum: BarCompareItem }) =>
        chartTooltip(bar.datum.label, fmt(bar.datum.value), accent.value),
}));

/** Horizontal bars need room for their category labels; vertical ones do not. */
const margin = computed(() =>
    isHorizontal.value ? { top: 8, right: 8, bottom: 0, left: 8 } : { top: 8, right: 8, bottom: 0, left: 0 },
);
</script>

<template>
    <div :class="cn('min-w-0')">
        <div v-if="loading" aria-busy="true" aria-live="polite">
            <span class="sr-only">Loading</span>
            <Skeleton class="bg-muted-foreground/20 w-full" :style="{ height: `${height}px` }" />
        </div>

        <EmptyState
            v-else-if="!hasData"
            :icon="ChartColumn"
            variant="empty"
            title="Nothing to compare"
            description="This chart fills in once there is more than one thing to measure."
        />

        <template v-else>
            <!-- The bars are decorative; the table below them is the accessible copy. -->
            <div :style="cssVars" aria-hidden="true">
                <VisXYContainer :data="data" :height="height" :margin="margin">
                    <VisStackedBar
                        :x="x"
                        :y="y"
                        :color="accent"
                        :orientation="barOrientation"
                        :rounded-corners="4"
                        :bar-padding="0.35"
                        :bar-max-width="32"
                    />
                    <VisAxis
                        v-if="isHorizontal"
                        type="y"
                        :grid-line="false"
                        :tick-line="false"
                        :domain-line="false"
                        :tick-values="categoryTicks"
                        :tick-format="labelAt"
                    />
                    <VisAxis
                        v-if="isHorizontal"
                        type="x"
                        :grid-line="true"
                        :tick-line="false"
                        :domain-line="false"
                        :tick-values="valueTicks"
                        :tick-format="valueFormat"
                    />
                    <VisAxis
                        v-if="!isHorizontal"
                        type="y"
                        :grid-line="true"
                        :tick-line="false"
                        :domain-line="false"
                        :tick-values="valueTicks"
                        :tick-format="valueFormat"
                    />
                    <VisAxis
                        v-if="!isHorizontal"
                        type="x"
                        :grid-line="false"
                        :tick-line="false"
                        :domain-line="false"
                        :tick-values="categoryTicks"
                        :tick-format="labelAt"
                    />
                    <VisTooltip :triggers="triggers" />
                </VisXYContainer>
            </div>

            <!--
                **The wrapper carries `sr-only`, not the table.** `sr-only` pins `width: 1px`
                and `overflow: hidden`, which clips an ordinary box and does NOT clip a table:
                a table is sized by its content whatever its container says, so a long label in
                this fallback pushes the PAGE wide while staying invisible. Measured on the
                finance report at 360px, where one project name added 63px of horizontal scroll
                to a page nobody could see the cause of. A `<div class="sr-only">` around it is
                a box, and a box clips.
            -->
            <div class="sr-only">
            <table>
                <caption>{{ label ?? 'Comparison' }} — {{ data.length }} categories</caption>
                <thead>
                    <tr>
                        <th scope="col">Category</th>
                        <th scope="col">{{ label ?? 'Value' }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="item in data" :key="item.label">
                        <th scope="row">{{ item.label }}</th>
                        <td class="tabular-nums">{{ fmt(item.value) }}</td>
                    </tr>
                </tbody>
            </table>
            </div>
        </template>
    </div>
</template>
