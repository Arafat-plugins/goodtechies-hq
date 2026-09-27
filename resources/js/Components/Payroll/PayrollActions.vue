<script setup lang="ts">
import { BadgeCheck, Calculator, ClipboardCheck, Lock, Stamp, Undo2 } from '@lucide/vue';
import type { Component } from 'vue';
import { computed } from 'vue';
import type { PayrollAction, PayrollPeriod } from '@/Components/Payroll/payroll';
import { payrollActions, payrollNoActionsReason } from '@/Components/Payroll/payroll';
import { Button } from '@/Components/ui/button';

/**
 * **What this viewer may do to this month, and nothing else.**
 *
 * Every control here comes from `payrollActions()`, which intersects two things the SERVER
 * resolved: `PayrollPeriodResource.permissions` (`PayrollPeriodPolicy`, asked for this
 * requester) and `available_transitions` / `allows_calculation` (`PayrollStatus`, asked for
 * this status). Nothing in this component tests a role, lists a status or knows the transition
 * map. **A control that 403s is worse than no control**, and a control Vue decided to show is
 * one the server never agreed to.
 *
 * Each button carries its consequence in a line beside it rather than in a tooltip. Four of the
 * six moves change what an entirely different screen — the finance ledger — will accept
 * afterwards, and that is not something to discover by hovering.
 *
 * When there is nothing to offer, the component says **why** instead of drawing an empty strip.
 * Part D §14 makes an approved month read-only to the Accountant, and a screen that simply
 * stops having buttons leaves the person wondering whether it is broken or whether they have
 * lost a permission. `payrollNoActionsReason()` is that sentence.
 */
const props = defineProps<{
    period: PayrollPeriod;
    /** The key of the move currently in flight, so nothing can be pressed twice. */
    working: string | null;
}>();

const emit = defineEmits<{ run: [action: PayrollAction] }>();

const actions = computed(() => payrollActions(props.period));
const reason = computed(() => payrollNoActionsReason(props.period));

const ICONS: Record<PayrollAction['key'], Component> = {
    calculate: Calculator,
    review: ClipboardCheck,
    approve: Stamp,
    lock: Lock,
    reverse: Undo2,
    paid: BadgeCheck,
};
</script>

<template>
    <div class="flex min-w-0 flex-col gap-3">
        <p v-if="reason" class="text-sm text-muted-foreground">{{ reason }}</p>

        <ul v-else class="flex min-w-0 list-none flex-col gap-3">
            <li v-for="action in actions" :key="action.key" class="flex min-w-0 flex-col gap-1">
                <div>
                    <Button
                        :id="`payroll-action-${action.key}`"
                        type="button"
                        :variant="action.variant"
                        :disabled="working !== null"
                        @click="emit('run', action)"
                    >
                        <component :is="ICONS[action.key]" aria-hidden="true" />
                        {{ working === action.key ? 'Working…' : action.label }}
                    </Button>
                </div>
                <p class="text-sm text-muted-foreground">{{ action.description }}</p>
            </li>
        </ul>
    </div>
</template>
