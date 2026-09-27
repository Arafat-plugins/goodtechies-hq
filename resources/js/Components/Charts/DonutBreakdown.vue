<script lang="ts">
import type { StatusKey } from '@/Components/StatusBadge.vue';

/**
 * One slice. A slice that carries a `tone` is coloured by that status, not by the
 * categorical palette — so a "tasks by status" donut agrees with the status badges.
 */
export interface DonutSlice {
    label: string;
    value: number;
    tone?: StatusKey;
}
</script>

<script setup lang="ts">
import { computed } from 'vue';
import { PieChart } from '@lucide/vue';
import { Donut } from '@unovis/ts';
import { VisDonut, VisSingleContainer, VisTooltip } from '@unovis/vue';
import EmptyState from '@/Components/EmptyState.vue';
import { Skeleton } from '@/Components/ui/skeleton';
import { cn } from '@/lib/utils';
import { chartCssVars, chartTooltip, useChartTokens } from './chartTokens';

const props = withDefaults(
    defineProps<{
        data: DonutSlice[];
        /** The number in the middle. Defaults to the sum of the slices. */
        total?: number;
        /** The line under it. */
        centerLabel?: string;
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
    { height: 200, loading: false, valueFormat: (value: number): string => value.toLocaleString() },
);
/** The caller's formatter, or grouped digits. One indirection so every site below agrees. */
const fmt = (value: number): string => props.valueFormat(value);


const tokens = useChartTokens();
const cssVars = computed(() => chartCssVars(tokens.value));

const hasData = computed(() => props.data.some((slice) => slice.value > 0));

/** A slice's own status wins; otherwise it takes the next categorical slot, in order. */
const colourFor = (slice: DonutSlice, index: number): string =>
    slice.tone ? tokens.value.status[slice.tone] : tokens.value.series[index % tokens.value.series.length];

const swatches = computed(() => props.data.map((slice, index) => colourFor(slice, index)));

const sum = computed(() => props.data.reduce((running, slice) => running + slice.value, 0));
const centreValue = computed(() => fmt(props.total ?? sum.value));

const value = (slice: DonutSlice): number => slice.value;

/**
 * A new accessor whenever the tokens change. unovis only redraws when a prop changes
 * identity, so a stable function would keep the light colours after a theme flip — and
 * the getter has to read `tokens.value` itself, or the computed has nothing to track.
 */
const colour = computed(() => {
    const t = tokens.value;

    return (slice: DonutSlice, index: number): string =>
        slice.tone ? t.status[slice.tone] : t.series[index % t.series.length];
});

/**
 * Hover on a segment. The donut's label is what identifies a slice; colour alone
 * never does, which is also what lets the palette sit in the 6–8 CVD band.
 */
const triggers = computed(() => ({
    [Donut.selectors.segment]: (arc: { data: DonutSlice; index: number }) =>
        chartTooltip(arc.data.label, fmt(arc.data.value), colourFor(arc.data, arc.index)),
}));
</script>

<template>
    <div :class="cn('flex min-w-0 flex-col gap-4')">
        <div v-if="loading" aria-busy="true" aria-live="polite" class="flex flex-col gap-4">
            <span class="sr-only">Loading</span>
            <Skeleton
                class="bg-muted-foreground/20 mx-auto w-full max-w-48 rounded-full"
                :style="{ height: `${height}px` }"
            />
            <div class="flex flex-col gap-2">
                <Skeleton v-for="row in 3" :key="row" class="bg-muted-foreground/20 h-3 w-2/3" />
            </div>
        </div>

        <EmptyState
            v-else-if="!hasData"
            :icon="PieChart"
            variant="empty"
            title="Nothing to break down"
            description="This chart fills in once there is something to count."
        />

        <template v-else>
            <!-- The ring is decorative; the legend and the table below carry the numbers. -->
            <div :style="cssVars" aria-hidden="true">
                <VisSingleContainer :data="data" :height="height">
                    <VisDonut
                        :value="value"
                        :color="colour"
                        :arc-width="28"
                        :corner-radius="2"
                        :pad-angle="0.02"
                        :central-label="centreValue"
                        :central-sub-label="centerLabel"
                    />
                    <VisTooltip :triggers="triggers" />
                </VisSingleContainer>
            </div>

            <!--
                Direct labels: identity is never colour alone, which is what lets the
                palette sit in the 6–8 CVD band. It is the sighted copy of the table
                below, so it is hidden from assistive tech rather than read twice.
            -->
            <ul class="flex flex-col gap-1.5" aria-hidden="true">
                <li
                    v-for="(slice, index) in data"
                    :key="slice.label"
                    class="flex items-center gap-2 text-xs text-muted-foreground"
                >
                    <span
                        class="size-2 shrink-0 rounded-full"
                        :style="{ backgroundColor: swatches[index] }"
                        aria-hidden="true"
                    />
                    <span class="min-w-0 truncate">{{ slice.label }}</span>
                    <span class="ml-auto tabular-nums text-foreground">{{ fmt(slice.value) }}</span>
                </li>
            </ul>

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
                <caption>{{ centerLabel ?? 'Breakdown' }} — {{ centreValue }} in total</caption>
                <thead>
                    <tr>
                        <th scope="col">Slice</th>
                        <th scope="col">Value</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="slice in data" :key="slice.label">
                        <th scope="row">{{ slice.label }}</th>
                        <td class="tabular-nums">{{ fmt(slice.value) }}</td>
                    </tr>
                </tbody>
            </table>
            </div>
        </template>
    </div>
</template>
