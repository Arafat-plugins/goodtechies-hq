<script setup lang="ts">
import { computed } from 'vue';
import type { RollupCurrencySide, RollupSide } from '@/Components/Finance/finance';
import { FINANCE_DEFAULT_CURRENCY, formatMoney } from '@/Components/Finance/finance';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';

/**
 * A month's figure per category, and the grand total under it.
 *
 * **Every number here is the server's.** `side` is one half of
 * `FinanceService::monthlyRollup()` — two SQL sums, computed and never stored (decision 8-10) —
 * and this component neither adds anything up nor rounds anything: `formatMoney()` turns the
 * exact decimal string into text and that is all. A screen that summed its own rows would be a
 * second statement of a fact about money, and the day it disagreed with the dashboard neither
 * would be provably right.
 *
 * **A category with no rows this month is absent**, not listed as zero. The rollup reports what
 * happened; inventing the zero rows to make a layout tidier is how a report starts lying.
 *
 * ## Why a real `<table>` for two columns
 *
 * Because it is a table: a caption naming the month, `<th scope="col">` on the headers and
 * `<th scope="row">` on every category name, so a screen reader announces "Maintenance, 860
 * dollars" rather than two unrelated cells. The total is a `<tfoot>` — the semantic place for
 * it — which is also what keeps it from being read as a fourteenth category.
 *
 * Two columns fit at 360 px, so nothing about this collapses; the amounts are right-aligned and
 * `tabular-nums`, which is what makes a column of money line up on its decimal point.
 *
 * ## One table per currency
 *
 * Every record carries its own currency and amounts in different currencies are never added
 * together (there is no exchange rate), so `sides` holds one entry per currency and each gets
 * its own table and its own total. A currency with no rows on this side this month is not
 * shown; when no currency has any, the empty state is shown once.
 *
 * `side` + `currency` is the older single-table form, still used by the finance dashboard and
 * the monthly report until they move to per-currency totals. It renders without the currency
 * heading, exactly as before.
 */
const props = defineProps<{
    title: string;
    /** The whole sentence the caption reads — it names the month, so it is the caller's. */
    caption: string;
    /** One entry per currency, from the rollup's `by_currency`, default currency first. */
    sides?: RollupCurrencySide[];
    /** @deprecated The single-currency form; use `sides`. */
    side?: RollupSide;
    /** @deprecated Goes with `side`. */
    currency?: string;
    /** What to say when the month has no rows on this side. */
    emptyText: string;
    /** The word under the grand total: "Total income" / "Total expenses". */
    totalLabel: string;
}>();

const perCurrency = computed(() => props.sides !== undefined);

const shown = computed<RollupCurrencySide[]>(() => {
    const sides = props.sides
        ?? (props.side ? [{ ...props.side, currency: props.currency ?? FINANCE_DEFAULT_CURRENCY }] : []);

    return sides.filter((side) => side.categories.length > 0);
});
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle>{{ title }}</CardTitle>
        </CardHeader>
        <CardContent>
            <p v-if="shown.length === 0" class="text-sm text-muted-foreground">
                {{ emptyText }}
            </p>

            <div v-else class="flex flex-col gap-6">
                <section v-for="side in shown" :key="side.currency" class="flex flex-col gap-2">
                    <h3 v-if="perCurrency" class="text-sm font-medium">Totals · {{ side.currency }}</h3>
                    <table class="w-full text-sm">
                        <!--
                            The accessible name of the table. `sr-only` rather than absent: a caption
                            that says which month these figures are is exactly what a reader arriving
                            at the table out of context needs, and printing it again above a card that
                            already carries the month would be saying it twice.
                        -->
                        <caption class="sr-only">{{ perCurrency ? `${caption} (${side.currency})` : caption }}</caption>
                        <thead>
                            <tr class="border-b border-border">
                                <th scope="col" class="py-2 pr-3 text-left font-medium text-muted-foreground">
                                    Category
                                </th>
                                <th scope="col" class="py-2 text-right font-medium text-muted-foreground">
                                    Total
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="line in side.categories" :key="line.category_id" class="border-b border-border/60">
                                <th scope="row" class="py-2 pr-3 text-left font-normal">{{ line.name }}</th>
                                <td class="py-2 text-right tabular-nums">{{ formatMoney(line.total, side.currency) }}</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr>
                                <th scope="row" class="py-2 pr-3 text-left font-medium">{{ totalLabel }}</th>
                                <td class="py-2 text-right font-medium tabular-nums">
                                    {{ formatMoney(side.total, side.currency) }}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </section>
            </div>
        </CardContent>
    </Card>
</template>
