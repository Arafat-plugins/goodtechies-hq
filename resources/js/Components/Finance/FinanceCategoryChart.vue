<script setup lang="ts">
import { computed } from 'vue';
import BarCompare, { type BarCompareItem } from '@/Components/Charts/BarCompare.vue';
import type { RollupSide } from '@/Components/Finance/finance';
import { moneyValue } from '@/Components/Finance/financeReport';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';

/**
 * The picture of one side of a month's rollup: a bar per category, longest first as the server
 * ordered them.
 *
 * **This draws the shape and nothing else — `LedgerTotals` carries the figures**, and the two
 * sit one above the other in the same column. Money is read exactly or not at all, so the
 * numbers belong in a real table with a caption and `tabular-nums`; a bar only says which
 * category was the big one, which is the part a table is bad at.
 *
 * ## Why the whole chart is `aria-hidden`
 *
 * `BarCompare` ships its own screen-reader table, which is the right default for a chart
 * standing on its own and the wrong one here: `LedgerTotals` is already the accessible copy of
 * these same figures, with its currency and its total. Hiding the chart's whole subtree — the
 * plot and its fallback table together — leaves exactly one accessible copy of the numbers, and
 * it is the better one. A reader who cannot see the bars loses nothing; a reader who gets both
 * would hear every amount twice, once without its currency.
 *
 * ## Colour
 *
 * One series, so every bar is `--chart-1` and they are compared by **length**, never by hue
 * (DESIGN.md §5.6). Each bar carries its category name on the axis. No colour is written in
 * this file — `BarCompare` asks `Charts/chartTokens.ts`, which is the only place a chart in
 * this application learns a colour.
 *
 * Nothing renders when the side is empty: `LedgerTotals` beneath already says the month has no
 * rows on this side, and two empty states for one fact is one too many.
 */
const props = defineProps<{
    title: string;
    side: RollupSide;
    /** Names the series in the chart's own tooltip. */
    label: string;
}>();

/** Lengths for the bars. Every figure a person reads is the server's string, in the table. */
const bars = computed<BarCompareItem[]>(() =>
    props.side.categories.map((line) => ({ label: line.name, value: moneyValue(line.total) })),
);

/** Room for one bar plus its label; the floor keeps a one-category chart from being a sliver. */
const height = computed(() => Math.max(140, bars.value.length * 34));
</script>

<template>
    <Card v-if="bars.length > 0" class="min-w-0">
        <CardHeader>
            <CardTitle>{{ title }}</CardTitle>
        </CardHeader>
        <CardContent>
            <!--
                Decoration. See the docblock for why the fallback table goes with it.

                `[&_.sr-only]:hidden` takes `BarCompare`'s screen-reader table out of the
                layout, not just out of the accessibility tree. **`sr-only` does not clip a
                `<table>`**: the class pins `width: 1px` with `overflow: hidden`, and a table
                box is sized by its content regardless, so a long category name makes the
                hidden fallback wider than the phone and pushes the whole page sideways. It was
                measured at 360 px on the report, where "Buffalo Modular — Website Development"
                made the page 423 px wide. `display: none` is the only thing that removes a
                table from flow, and it costs nothing here because this subtree is already
                `aria-hidden` — the figures below are the accessible copy.
            -->
            <div aria-hidden="true" class="min-w-0 [&_.sr-only]:hidden">

                <BarCompare :data="bars" orientation="horizontal" :label="label" :height="height" />
            </div>
        </CardContent>
    </Card>
</template>
