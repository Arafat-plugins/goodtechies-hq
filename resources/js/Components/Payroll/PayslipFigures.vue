<script setup lang="ts">
import { computed } from 'vue';
import { formatMoney } from '@/Components/Finance/finance';
import type { PayslipRow } from '@/Components/Payroll/payslip';

/**
 * The figures on a payslip: what was added, what was taken off, and what is left.
 *
 * ## A real table, because it is one
 *
 * Six named amounts and a total is tabular data, so it is a `<table>` with a `<caption>`, a
 * `<th scope="col">` per column and a `<th scope="row">` per line — which is what lets a screen
 * reader say *"Leave impact, minus $120.00"* instead of reading two disconnected columns of
 * numbers. `DataTable` is deliberately not used: that component is a sortable, filterable,
 * paginated list of records with a column menu and a card fallback, and none of those verbs
 * mean anything on a payslip, which is one record with six rows and no controls at all.
 *
 * ## The sign is a word as well as a glyph
 *
 * Every line carries `+` or `−` in the amount **and** a `direction` column that says *Added* or
 * *Deducted* in words. A minus sign alone is four pixels wide and is the difference between a
 * bonus and a deduction; it is also the first thing lost when somebody prints this in
 * greyscale on a laser printer, photographs it, or reads it at 200 % zoom. Nothing here relies
 * on colour at all (DESIGN.md §5.6) — the deduction rows are not tinted red.
 *
 * ## The total is PostgreSQL's
 *
 * `net_salary` is a generated column. It is printed, never computed: a `reduce()` over these
 * six strings would be a second opinion about somebody's pay, and the day it disagreed with the
 * bank transfer is the day nobody could say which was right.
 */
const props = defineProps<{ payslip: PayslipRow; currency: string }>();

interface FigureLine {
    key: string;
    label: string;
    amount: string;
    /** `+` or `−`, and the same fact in words for everybody who cannot see four pixels. */
    sign: '+' | '−';
    direction: 'Added' | 'Deducted';
}

// Polish 002: allowance is hidden unless an older line still carries one, so a payslip's
// figures always add up to its net.
const lines = computed<FigureLine[]>(() => ([
    { key: 'base_salary', label: 'Base salary', amount: props.payslip.base_salary, sign: '+', direction: 'Added' },
    { key: 'allowance', label: 'Allowance', amount: props.payslip.allowance, sign: '+', direction: 'Added' },
    { key: 'bonus', label: 'Bonus', amount: props.payslip.bonus, sign: '+', direction: 'Added' },
    { key: 'deduction', label: 'Deduction', amount: props.payslip.deduction, sign: '−', direction: 'Deducted' },
    { key: 'advance', label: 'Advance', amount: props.payslip.advance, sign: '−', direction: 'Deducted' },
    { key: 'leave_impact', label: 'Leave impact', amount: props.payslip.leave_impact, sign: '−', direction: 'Deducted' },
] satisfies FigureLine[]).filter((line) => line.key !== 'allowance' || Number(line.amount) !== 0));
</script>

<template>
    <!--
        `overflow-x-auto` on the wrapper and `min-w-0` above it: two columns cannot overflow at
        360 px, but a long currency string in a narrow column would push the page sideways
        rather than the table, and a horizontally scrolling PAGE is the one thing the
        accessibility floor forbids outright.
    -->
    <div class="min-w-0 overflow-x-auto">
        <table class="w-full min-w-0 caption-bottom border-collapse text-sm">
            <caption class="sr-only">
                What was added and deducted this month, and the net pay that is left
            </caption>
            <thead>
                <tr class="border-b border-border">
                    <th scope="col" class="py-2 pr-3 text-left font-medium text-muted-foreground">Item</th>
                    <th scope="col" class="px-3 py-2 text-left font-medium text-muted-foreground">Direction</th>
                    <th scope="col" class="py-2 pl-3 text-right font-medium text-muted-foreground">Amount</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="line in lines" :key="line.key" class="border-b border-border/60">
                    <th scope="row" class="py-2.5 pr-3 text-left font-normal">{{ line.label }}</th>
                    <td class="px-3 py-2.5 text-muted-foreground">{{ line.direction }}</td>
                    <td class="py-2.5 pl-3 text-right font-medium tabular-nums whitespace-nowrap">
                        {{ line.sign }}{{ formatMoney(line.amount, currency) }}
                    </td>
                </tr>
            </tbody>
            <tfoot>
                <tr class="border-t-2 border-foreground">
                    <th scope="row" class="py-3 pr-3 text-left text-base font-semibold">Net pay</th>
                    <td class="px-3 py-3"></td>
                    <td class="py-3 pl-3 text-right text-base font-semibold tabular-nums whitespace-nowrap">
                        {{ formatMoney(payslip.net_salary, currency) }}
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>
</template>
