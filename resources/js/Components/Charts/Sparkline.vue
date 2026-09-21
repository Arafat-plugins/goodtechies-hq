<script setup lang="ts">
import { computed } from 'vue';
import { CurveType } from '@unovis/ts';
import { VisLine, VisXYContainer } from '@unovis/vue';
import { Skeleton } from '@/Components/ui/skeleton';
import { cn } from '@/lib/utils';
import { chartCssVars, useChartTokens } from './chartTokens';

const props = withDefaults(
    defineProps<{
        data: number[];
        /**
         * `neutral` is the brand accent. `up` and `down` borrow the two status colours
         * that already mean "finished well" and "stopped", so a sparkline can never
         * introduce a colour the status badges do not already use.
         */
        tone?: 'neutral' | 'up' | 'down';
        height?: number;
        /** What the line is of, for the screen-reader summary. */
        label?: string;
        loading?: boolean;
    }>(),
    { tone: 'neutral', height: 32, loading: false },
);

const tokens = useChartTokens();
const cssVars = computed(() => chartCssVars(tokens.value));

const colour = computed(() => {
    if (props.tone === 'up') {
        return tokens.value.status.done;
    }

    if (props.tone === 'down') {
        return tokens.value.status.cancelled;
    }

    return tokens.value.series[0];
});

/** Two points is the minimum that draws a line; one number is not a trend. */
const hasData = computed(() => props.data.length > 1);

const x = (_: number, index: number): number => index;
const y = (value: number): number => value;

/**
 * The accessible fallback here is an `aria-label`, not a table: a sparkline sits inside
 * a stat card whose headline number is already in the DOM, so a second table of every
 * point would be noise. The label says the direction and the endpoints, which is all
 * the line itself conveys at this size.
 */
const summary = computed(() => {
    if (!hasData.value) {
        return '';
    }

    const first = props.data[0];
    const last = props.data[props.data.length - 1];
    const direction = last > first ? 'up' : last < first ? 'down' : 'flat';

    return `${props.label ?? 'Trend'}: ${direction}, from ${first.toLocaleString()} to ${last.toLocaleString()} over ${props.data.length} points`;
});
</script>

<template>
    <div :class="cn('min-w-0')">
        <div v-if="loading" aria-busy="true" aria-live="polite">
            <span class="sr-only">Loading</span>
            <Skeleton class="bg-muted-foreground/20 w-full" :style="{ height: `${height}px` }" />
        </div>

        <!-- No axes, no grid, no tooltip: at this size the line is the whole chart. -->
        <div v-else-if="hasData" :style="cssVars" role="img" :aria-label="summary">
            <VisXYContainer
                :data="data"
                :height="height"
                :margin="{ top: 2, right: 1, bottom: 2, left: 1 }"
            >
                <VisLine :x="x" :y="y" :color="colour" :line-width="2" :curve-type="CurveType.MonotoneX" />
            </VisXYContainer>
        </div>

        <!--
            Too little to plot. A stat card must not grow an EmptyState medallion, so the
            no-data state here is a dash on the baseline — the same "—" the placeholder
            stat card uses.
        -->
        <p v-else class="text-xs text-muted-foreground" :style="{ lineHeight: `${height}px` }">—</p>
    </div>
</template>
