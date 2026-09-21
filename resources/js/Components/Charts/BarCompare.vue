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
    }>(),
    { orientation: 'vertical', height: 200, loading: false },
);

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
    typeof tick === 'number' ? tick.toLocaleString() : String(tick);

const barOrientation = computed(() => (isHorizontal.value ? Orientation.Horizontal : Orientation.Vertical));

/** A tick per bar, so every category is named. */
const categoryTicks = computed(() => props.data.map((_, index) => index));

/** The one faint rule on the value axis, at round values. */
const valueTicks = computed(() => niceTicks(Math.max(0, ...props.data.map((item) => item.value))));

const triggers = computed(() => ({
    [StackedBar.selectors.bar]: (bar: { datum: BarCompareItem }) =>
        chartTooltip(bar.datum.label, bar.datum.value.toLocaleString(), accent.value),
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

            <table class="sr-only">
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
                        <td class="tabular-nums">{{ item.value.toLocaleString() }}</td>
                    </tr>
                </tbody>
            </table>
        </template>
    </div>
</template>
