<script setup lang="ts">
import { computed } from 'vue';
import type { ProjectEarnings } from '@/Components/Finance/finance';
import { formatMoney } from '@/Components/Finance/finance';
import { moneyValue } from '@/Components/Finance/financeReport';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';

/**
 * Each project's month: what it should bring in, what arrived, what it cost, what is still owed.
 *
 * Every figure — the rows and the totals — comes from `App\Support\ProjectEarnings::forMonth()`
 * as an exact decimal string. Nothing is added up here; `limit` only hides rows, and the totals
 * line still covers every project, which is why it says so when rows are hidden.
 */
const props = defineProps<{
    earnings: ProjectEarnings;
    currency: string;
    /** Show only the first N rows (the dashboard); the report shows them all. */
    limit?: number;
}>();

const visibleRows = computed(() =>
    props.limit ? props.earnings.rows.slice(0, props.limit) : props.earnings.rows,
);

const hiddenCount = computed(() => props.earnings.rows.length - visibleRows.value.length);

const money = (amount: string): string => formatMoney(amount, props.currency);

/** Zero owed is a dash, so the column reads as "nothing to chase" at a glance. */
const outstandingText = (amount: string): string => (moneyValue(amount) === 0 ? '—' : money(amount));

/** A negative balance means the client paid more than the price: said in words, not a minus sign. */
const balanceText = (balance: string): string =>
    moneyValue(balance) < 0 ? `Overpaid ${money(balance.trim().replace(/^-/, ''))}` : money(balance);
</script>

<template>
    <Card class="min-w-0">
        <CardHeader>
            <CardTitle>Projects — expected vs received</CardTitle>
            <CardDescription>
                What each project should bring in this month, what arrived, what it cost, and what is still owed.
            </CardDescription>
        </CardHeader>
        <CardContent class="flex min-w-0 flex-col gap-4">
            <p v-if="earnings.rows.length === 0" class="text-sm text-muted-foreground">
                No project has billing set or money linked this month.
            </p>

            <template v-else>
                <div class="min-w-0 overflow-x-auto">
                    <table class="w-full text-sm">
                        <caption class="sr-only">
                            Projects, expected against received this month, with costs, profit and what is still
                            owed.
                        </caption>
                        <thead>
                            <tr class="border-b border-border">
                                <th scope="col" class="py-2 pr-3 text-left font-medium text-muted-foreground">
                                    Project
                                </th>
                                <th scope="col" class="py-2 pr-3 text-left font-medium text-muted-foreground">
                                    Billing
                                </th>
                                <th scope="col" class="py-2 pr-3 text-right font-medium text-muted-foreground">
                                    Expected
                                </th>
                                <th scope="col" class="py-2 pr-3 text-right font-medium text-muted-foreground">
                                    Received
                                </th>
                                <th scope="col" class="py-2 pr-3 text-right font-medium text-muted-foreground">
                                    Costs
                                </th>
                                <th scope="col" class="py-2 pr-3 text-right font-medium text-muted-foreground">
                                    Profit
                                </th>
                                <th scope="col" class="py-2 text-right font-medium text-muted-foreground">
                                    Outstanding
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in visibleRows" :key="row.project.id" class="border-b border-border/60">
                                <th scope="row" class="py-2 pr-3 text-left font-normal">
                                    {{ row.project.name }}
                                    <span class="block text-xs text-muted-foreground">
                                        {{ row.project.domain ?? 'No domain' }}
                                    </span>
                                </th>
                                <td class="min-w-40 py-2 pr-3">
                                    {{ row.type_label }}
                                    <span class="block text-xs text-muted-foreground">{{ row.basis }}</span>
                                </td>
                                <td class="py-2 pr-3 text-right whitespace-nowrap tabular-nums">
                                    {{ row.expected === null ? '—' : money(row.expected) }}
                                </td>
                                <td class="py-2 pr-3 text-right whitespace-nowrap tabular-nums">
                                    {{ money(row.received) }}
                                </td>
                                <td class="py-2 pr-3 text-right whitespace-nowrap tabular-nums">
                                    {{ money(row.costs) }}
                                    <span
                                        v-if="moneyValue(row.billable_costs) > 0"
                                        class="block text-xs text-muted-foreground"
                                    >
                                        incl. {{ money(row.billable_costs) }} billable
                                    </span>
                                </td>
                                <td class="py-2 pr-3 text-right whitespace-nowrap tabular-nums">
                                    {{ money(row.profit) }}
                                </td>
                                <td class="py-2 text-right whitespace-nowrap tabular-nums">
                                    {{ outstandingText(row.outstanding) }}
                                </td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr>
                                <th scope="row" class="py-2 pr-3 text-left font-medium">
                                    {{ hiddenCount > 0 ? 'Total, all projects' : 'Total' }}
                                </th>
                                <td />
                                <td class="py-2 pr-3 text-right font-medium whitespace-nowrap tabular-nums">
                                    {{ money(earnings.totals.expected) }}
                                </td>
                                <td class="py-2 pr-3 text-right font-medium whitespace-nowrap tabular-nums">
                                    {{ money(earnings.totals.received) }}
                                </td>
                                <td class="py-2 pr-3 text-right font-medium whitespace-nowrap tabular-nums">
                                    {{ money(earnings.totals.costs) }}
                                </td>
                                <td class="py-2 pr-3 text-right font-medium whitespace-nowrap tabular-nums">
                                    {{ money(earnings.totals.profit) }}
                                </td>
                                <td class="py-2 text-right font-medium whitespace-nowrap tabular-nums">
                                    {{ outstandingText(earnings.totals.outstanding) }}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <p v-if="hiddenCount > 0" class="text-sm text-muted-foreground">+{{ hiddenCount }} more on the report</p>
            </template>

            <div v-if="earnings.contracts.length" class="flex min-w-0 flex-col gap-2">
                <h3 class="text-sm font-medium">One-time contracts</h3>
                <div class="min-w-0 overflow-x-auto">
                    <table class="w-full text-sm">
                        <caption class="sr-only">One-time contracts: price against everything received to date</caption>
                        <thead>
                            <tr class="border-b border-border">
                                <th scope="col" class="py-2 pr-3 text-left font-medium text-muted-foreground">
                                    Project
                                </th>
                                <th scope="col" class="py-2 pr-3 text-right font-medium text-muted-foreground">
                                    Price
                                </th>
                                <th scope="col" class="py-2 pr-3 text-right font-medium text-muted-foreground">
                                    Received to date
                                </th>
                                <th scope="col" class="py-2 text-right font-medium text-muted-foreground">Balance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="contract in earnings.contracts"
                                :key="contract.project.id"
                                class="border-b border-border/60"
                            >
                                <th scope="row" class="py-2 pr-3 text-left font-normal">
                                    {{ contract.project.name }}
                                    <span class="block text-xs text-muted-foreground">
                                        {{ contract.project.domain ?? 'No domain' }}
                                    </span>
                                </th>
                                <td class="py-2 pr-3 text-right whitespace-nowrap tabular-nums">
                                    {{ money(contract.price) }}
                                </td>
                                <td class="py-2 pr-3 text-right whitespace-nowrap tabular-nums">
                                    {{ money(contract.received_to_date) }}
                                </td>
                                <td class="py-2 text-right whitespace-nowrap tabular-nums">
                                    {{ balanceText(contract.balance) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </CardContent>
    </Card>
</template>
