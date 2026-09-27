<script setup lang="ts">
import { computed } from 'vue';
import { formatMoney } from '@/Components/Finance/finance';
import type { LeaveExplanation } from '@/Components/Payroll/payslip';
import { formatDayCount } from '@/Components/Payroll/payslip';

/**
 * **Why the leave impact is what it is.**
 *
 * An unexplained deduction on a payslip is the single most likely thing in this application to
 * generate a complaint, so the arithmetic is written out in a sentence rather than left for
 * somebody to reverse-engineer: *"$120.00 was deducted for 3 unpaid days out of 22 payable days
 * in September 2026."*
 *
 * The two counts are `PayrollService::unpaidDaysIn()` and `payableDaysIn()` — **the same two
 * numbers `leaveImpactCents()` divided** to produce the money — so the explanation cannot drift
 * from the deduction it explains. The money is the **stored** `leave_impact` and is never
 * recomputed from the days: what the payslip says was deducted is what was deducted, and a
 * schedule edited in November must not silently restate a September figure somebody has already
 * been paid against.
 *
 * **A month with no unpaid leave still gets this block**, saying so in the same place every
 * month. A section that disappears leaves somebody wondering whether the screen forgot, and
 * *"nothing was deducted"* is an answer worth reading.
 *
 * Nothing here scores or compares anybody. It is one person's month, and the only numbers on it
 * are days and money (Part H §1).
 */
const props = defineProps<{ leave: LeaveExplanation; monthLabel: string; currency: string }>();

const sentence = computed<string>(() => {
    if (!props.leave.has_impact) {
        return `No unpaid leave in ${props.monthLabel}, so nothing was deducted for it.`;
    }

    return [
        formatMoney(props.leave.impact ?? '0', props.currency),
        'was deducted for',
        formatDayCount(props.leave.unpaid_days),
        'of unpaid leave, out of',
        formatDayCount(props.leave.payable_days),
        `you are paid for in ${props.monthLabel}.`,
    ].join(' ');
});
</script>

<template>
    <div class="flex min-w-0 flex-col gap-2">
        <p class="text-sm">{{ sentence }}</p>

        <!--
            The rule underneath, once, in the words the leave screens use. It is here rather
            than in a tooltip because a payslip is read in a hurry and often printed, and a
            tooltip prints as nothing at all.
        -->
        <p v-if="leave.has_impact" class="text-xs text-muted-foreground">
            Payable days are the working days in the month under your own schedule, with holidays
            excluded. Only leave taken on an unpaid type is deducted — approved paid leave is not.
        </p>
    </div>
</template>
