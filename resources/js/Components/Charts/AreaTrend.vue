<script lang="ts">
/** One point on the trend. `x` is a label, never a scale: the series is plotted by index. */
export interface AreaTrendPoint {
    x: string | number | Date;
    y: number;
}
</script>

<script setup lang="ts">
import { computed } from 'vue';
import { TrendingUp } from '@lucide/vue';
import { CurveType } from '@unovis/ts';
import { VisArea, VisAxis, VisCrosshair, VisLine, VisTooltip, VisXYContainer } from '@unovis/vue';
import EmptyState from '@/Components/EmptyState.vue';
import { Skeleton } from '@/Components/ui/skeleton';
import { cn } from '@/lib/utils';
import { chartCssVars, chartTooltip, niceTicks, useChartTokens } from './chartTokens';

const props = withDefaults(
    defineProps<{
        data: AreaTrendPoint[];
        /** Names the series. One series means no legend, so this is the name. */
        label?: string;
        height?: number;
        loading?: boolean;
    }>(),
    { height: 200, loading: false },
);

const tokens = useChartTokens();
const cssVars = computed(() => chartCssVars(tokens.value));

/** Series 1 only: a trend has one line, so it gets the one accent on the chart. */
const accent = computed(() => tokens.value.series[0]);

const hasData = computed(() => props.data.length > 0);

/** Plotted by index, so a string, a number and a Date behave the same and no date library is needed. */
const x = (_: AreaTrendPoint, index: number): number => index;
const y = (point: AreaTrendPoint): number => point.y;

function labelAt(index: number): string {
    const point = props.data[index];
    if (!point) {
        return '';
    }

    return point.x instanceof Date
        ? point.x.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
        : String(point.x);
}

/** Only whole indices name a point; anything between two points stays unlabelled. */
const tickFormat = (tick: number | Date): string =>
    typeof tick === 'number' && Number.isInteger(tick) ? labelAt(tick) : '';

const valueFormat = (tick: number | Date): string =>
    typeof tick === 'number' ? tick.toLocaleString() : String(tick);

/** Enough ticks to read, never one per point. */
const xTicks = computed(() => Math.min(props.data.length, 5));

/** The one faint horizontal rule, at round values. */
const valueTicks = computed(() => niceTicks(Math.max(0, ...props.data.map((point) => point.y))));

const tooltipTemplate = (point: AreaTrendPoint, tick: number | Date): string =>
    chartTooltip(
        labelAt(typeof tick === 'number' ? Math.round(tick) : 0),
        (point?.y ?? 0).toLocaleString(),
        accent.value,
    );

const caption = computed(() => `${props.label ?? 'Trend'} — ${props.data.length} points`);
</script>

<template>
    <div :class="cn('min-w-0')">
        <div v-if="loading" aria-busy="true" aria-live="polite">
            <span class="sr-only">Loading</span>
            <Skeleton class="bg-muted-foreground/20 w-full" :style="{ height: `${height}px` }" />
        </div>

        <EmptyState
            v-else-if="!hasData"
            :icon="TrendingUp"
            variant="empty"
            title="No data yet"
            description="This trend fills in once there is something to plot."
        />

        <template v-else>
            <!-- The plot is decorative; the table below it is the accessible copy. -->
            <div :style="cssVars" aria-hidden="true">
                <VisXYContainer :data="data" :height="height" :margin="{ top: 8, right: 8, bottom: 0, left: 0 }">
                    <VisArea :x="x" :y="y" :color="accent" :opacity="0.12" :curve-type="CurveType.MonotoneX" />
                    <VisLine :x="x" :y="y" :color="accent" :line-width="2" :curve-type="CurveType.MonotoneX" />
                    <VisAxis
                        type="y"
                        :grid-line="true"
                        :tick-line="false"
                        :domain-line="false"
                        :tick-values="valueTicks"
                        :tick-format="valueFormat"
                    />
                    <VisAxis
                        type="x"
                        :grid-line="false"
                        :tick-line="false"
                        :domain-line="false"
                        :num-ticks="xTicks"
                        :tick-format="tickFormat"
                    />
                    <VisCrosshair :x="x" :y="y" :color="accent" :template="tooltipTemplate" />
                    <VisTooltip />
                </VisXYContainer>
            </div>

            <table class="sr-only">
                <caption>{{ caption }}</caption>
                <thead>
                    <tr>
                        <th scope="col">Point</th>
                        <th scope="col">{{ label ?? 'Value' }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(point, index) in data" :key="index">
                        <th scope="row">{{ labelAt(index) }}</th>
                        <td class="tabular-nums">{{ point.y.toLocaleString() }}</td>
                    </tr>
                </tbody>
            </table>
        </template>
    </div>
</template>
