<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { PencilLine } from '@lucide/vue';
import { computed, ref, useId, watch } from 'vue';
import { formatMoney } from '@/Components/Finance/finance';
import type { SalaryEmployee } from '@/Components/Payroll/payslip';
import { formatEffectiveDate, salaryUpdateRoute } from '@/Components/Payroll/payslip';
import { Button } from '@/Components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';

/**
 * **Set a salary from a date** (master prompt Part D §14, Phase 9, slice 2b).
 *
 * ## This form adds a row; it does not edit one
 *
 * Decision 9-1 is the whole design of this dialog. `employee_salaries` holds **effective-dated
 * rows**: `PayrollService::setSalary()` writes a new one per change and `salaryFor()` reads the
 * latest row at or before whichever day it is asked about — which is why September keeps
 * recomputing to September's figure after a November raise. Nothing anywhere updates an old
 * row, and a screen that looked like an edit form would quietly teach the opposite.
 *
 * So the dialog is titled *Set a new salary*, its primary button says *Set from <date>*, the
 * date field is required and labelled **Starts on**, and the confirmation line underneath spells
 * out what will actually happen before the button is pressed. The current figures are prefilled
 * because an allowance that is not changing still has to be stated — `setSalary()` writes a
 * whole row, not a patch — and a blank field would make "leave it as it was" mean "set it to
 * nothing".
 *
 * **The start date defaults to the first of next month.** Payroll here is monthly, salaries
 * conventionally start on a month boundary, and that default cannot disturb a month whose draft
 * has already been created. It is a default, not a constraint: a backdated correction is
 * ordinary payroll work and the field accepts any day.
 *
 * ## Focus
 *
 * The trigger is a `DialogTrigger as-child`, so reka-ui owns the focus trap and **returns focus
 * to the button that opened it** on every close — Esc, the overlay, *Cancel* and a successful
 * save alike. Nothing here moves focus by hand; `open` is the only state this component holds.
 *
 * ## The audit trail is not this component's business
 *
 * Part D §14: *"changes are audit-logged as 'salary changed'"*. `setSalary()` writes that row
 * inside the same transaction as the salary. There is no second trail here and no note field
 * asking for one.
 */
const props = defineProps<{ employee: SalaryEmployee; currency: string }>();

const open = ref(false);

const baseId = useId();
const fromId = useId();

/** The first of next month, as `YYYY-MM-DD`. See the class note for why that is the default. */
function firstOfNextMonth(): string {
    const now = new Date();
    const next = new Date(now.getFullYear(), now.getMonth() + 1, 1);
    const month = `${next.getMonth() + 1}`.padStart(2, '0');

    return `${next.getFullYear()}-${month}-01`;
}

const form = useForm({
    base_salary: props.employee.current?.base_salary ?? '',
    // Polish 002: allowance is no longer used anywhere; a new salary always sets it to 0.
    allowance: '0.00',
    effective_from: firstOfNextMonth(),
});

/** Re-arm the form each time the dialog opens, so a cancelled edit never leaks into the next. */
watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    form.clearErrors();
    form.base_salary = props.employee.current?.base_salary ?? '';
    form.allowance = '0.00';
    form.effective_from = firstOfNextMonth();
});

const startsOn = computed(() => formatEffectiveDate(form.effective_from || null));

const triggerLabel = computed(() =>
    props.employee.current ? `Set a new salary for ${props.employee.name}` : `Set a salary for ${props.employee.name}`,
);

function submit(): void {
    form.put(salaryUpdateRoute(props.employee.id), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <Button type="button" variant="outline" size="sm" :aria-label="triggerLabel">
                <PencilLine aria-hidden="true" />
                {{ employee.current ? 'New salary' : 'Set salary' }}
            </Button>
        </DialogTrigger>

        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Set a new salary for {{ employee.name }}</DialogTitle>
                <DialogDescription>
                    This adds a salary that starts on a date. Months that have already been drafted keep the figures
                    they were drafted with.
                </DialogDescription>
            </DialogHeader>

            <form class="flex min-w-0 flex-col gap-4" @submit.prevent="submit">
                <p v-if="employee.current" class="text-sm text-muted-foreground">
                    Currently
                    <span class="font-medium text-foreground tabular-nums">
                        {{ formatMoney(employee.current.base_salary, currency) }}
                    </span>
                    a month, since {{ formatEffectiveDate(employee.current.effective_from) }}.
                </p>
                <p v-else class="text-sm text-muted-foreground">
                    {{ employee.name }} has no salary set yet, so no payroll draft can include them.
                </p>

                <div class="flex min-w-0 flex-col gap-2">
                    <Label :for="baseId">Base salary</Label>
                    <Input
                        :id="baseId"
                        v-model="form.base_salary"
                        type="number"
                        step="0.01"
                        min="0.01"
                        inputmode="decimal"
                        class="tabular-nums"
                        required
                    />
                    <p v-if="form.errors.base_salary" class="text-xs text-destructive">{{ form.errors.base_salary }}</p>
                </div>

                <div class="flex min-w-0 flex-col gap-2">
                    <Label :for="fromId">Starts on</Label>
                    <Input :id="fromId" v-model="form.effective_from" type="date" required />
                    <p v-if="form.errors.effective_from" class="text-xs text-destructive">
                        {{ form.errors.effective_from }}
                    </p>
                </div>

                <!--
                    What pressing the button will actually do, in a sentence, before it is
                    pressed. This is the one screen in the application where getting the DATE
                    wrong moves money in a month nobody meant to touch.
                -->
                <p class="rounded-md border border-border bg-muted/40 p-3 text-xs text-muted-foreground">
                    From {{ startsOn }}, {{ employee.name }} is paid this. Earlier months keep their own figures, and
                    the change is recorded in the audit log.
                </p>

                <DialogFooter>
                    <Button type="button" variant="outline" @click="open = false">Cancel</Button>
                    <Button type="submit" :disabled="form.processing">Set from {{ startsOn }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
