<script setup lang="ts">
import { computed } from 'vue';
import type { RollupPayroll } from '@/Components/Finance/finance';
import { formatMoney } from '@/Components/Finance/finance';
import { moneyValue } from '@/Components/Finance/financeReport';
import { Alert, AlertDescription, AlertTitle } from '@/Components/ui/alert';

/**
 * Whether this month's salaries are inside the expenses figure, said in words.
 *
 * The rollup counts the payroll as an expense only once the period is locked or paid, so a
 * month whose payroll is still a draft shows expenses without salaries. That is correct and
 * still surprising, which is why the page says it rather than leaving the reader to wonder.
 * A second, destructive notice appears when salaries were ALSO entered by hand under the
 * Payroll category: the server flags it, this only prints it.
 */
const props = defineProps<{
    payroll: RollupPayroll;
    monthLabel: string;
    currency: string;
}>();

const hasManualEntries = computed(() => moneyValue(props.payroll.manual_payroll_entries) !== 0);

const isVisible = computed(() => props.payroll.status !== null || hasManualEntries.value);

/** `locked`, `paid`, `draft` — lower case, inside a sentence. */
const statusWord = computed(() => (props.payroll.status ?? '').replace(/_/g, ' ').toLowerCase());

const total = computed(() => formatMoney(props.payroll.total, props.currency));

const manual = computed(() => formatMoney(props.payroll.manual_payroll_entries, props.currency));
</script>

<template>
    <div v-if="isVisible" class="flex min-w-0 flex-col gap-3">
        <Alert v-if="payroll.status !== null && payroll.counted">
            <AlertTitle>Salaries are included</AlertTitle>
            <AlertDescription>
                {{ total }} of net salary from the {{ monthLabel }} payroll ({{ statusWord }}) is counted in expenses.
            </AlertDescription>
        </Alert>

        <Alert v-else-if="payroll.status !== null">
            <AlertTitle>Salaries not counted yet</AlertTitle>
            <AlertDescription>
                The {{ monthLabel }} payroll is {{ statusWord }} ({{ total }} net). It is added to expenses once the
                month is locked or paid.
            </AlertDescription>
        </Alert>

        <!-- No payroll run this month, but salaries were entered by hand: say where they came from. -->
        <Alert v-else>
            <AlertTitle>Salaries entered by hand</AlertTitle>
            <AlertDescription>
                {{ manual }} was entered under the Payroll category this month. There is no {{ monthLabel }} payroll
                run.
            </AlertDescription>
        </Alert>

        <Alert v-if="payroll.double_count_warning" variant="destructive">
            <AlertDescription>
                Possible double count: {{ manual }} was also entered by hand under the Payroll category this month.
                Remove or re-categorise it so salaries are not counted twice.
            </AlertDescription>
        </Alert>
    </div>
</template>
