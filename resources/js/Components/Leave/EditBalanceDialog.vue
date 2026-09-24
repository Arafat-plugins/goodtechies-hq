<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ShieldAlert } from '@lucide/vue';
import { useId, watch } from 'vue';
import type { LeavePerson, LeaveTypeOption } from '@/Components/Leave/leave';
import { formatDays, leaveRoutes } from '@/Components/Leave/leave';
import { Button } from '@/Components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';

/**
 * An Admin setting one person's balance for one capped type.
 *
 * ## It asks for the number, not for a change to it
 *
 * The field is the balance that should be there afterwards, pre-filled with what is there now.
 * That is what the Admin is actually deciding — "Yaseen has twelve days" — and a delta would
 * have made "set it to twelve" a subtraction somebody had to do in their head while looking at
 * a dialog that had covered up the number.
 *
 * ## The reason is required, and the dialog says why
 *
 * Part D §9 asks for balance adjustments to be audit-logged, so `UpdateLeaveBalanceRequest`
 * requires the reason and the endpoint refuses without one — the `required` attribute here is
 * the convenience, not the rule. The line under the field says the change is recorded against
 * the Admin's name, for the same reason `EditDayDialog` does: a number that decides what
 * somebody is allowed to take later should not be changeable quietly.
 *
 * ## There is no accrual to undo
 *
 * Nothing tops these up, so the number in this box is the whole truth until somebody changes it
 * again or a leave request spends some of it. The dialog says that too.
 */

const props = defineProps<{
    open: boolean;
    employee: LeavePerson | null;
    type: LeaveTypeOption | null;
    current: number;
}>();

const emit = defineEmits<{ 'update:open': [open: boolean] }>();

const daysId = useId();
const reasonId = useId();

const form = useForm({ balance_days: 0, reason: '' });

watch(
    () => [props.open, props.employee?.id, props.type?.id],
    () => {
        if (props.open) {
            form.clearErrors();
            form.balance_days = props.current;
            form.reason = '';
        }
    },
    { immediate: true },
);

function submit(): void {
    if (!props.employee || !props.type) {
        return;
    }

    form.put(leaveRoutes.setBalance(props.employee.id, props.type.id), {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    });
}
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Set leave balance</DialogTitle>
                <DialogDescription v-if="employee && type">
                    {{ employee.name }} — {{ type.name }}. Currently {{ formatDays(current) }}.
                </DialogDescription>
            </DialogHeader>

            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <div class="flex flex-col gap-2">
                    <Label :for="daysId">Days</Label>
                    <Input
                        :id="daysId"
                        v-model="form.balance_days"
                        type="number"
                        min="0"
                        max="365"
                        step="1"
                        inputmode="numeric"
                        required
                    />
                    <p v-if="form.errors.balance_days" class="text-xs text-destructive">
                        {{ form.errors.balance_days }}
                    </p>
                </div>

                <div class="flex flex-col gap-2">
                    <Label :for="reasonId">Reason</Label>
                    <Textarea
                        :id="reasonId"
                        v-model="form.reason"
                        rows="3"
                        placeholder="Why this number is changing."
                        required
                    />
                    <p v-if="form.errors.reason" class="text-xs text-destructive">{{ form.errors.reason }}</p>
                </div>

                <p class="flex items-start gap-2 text-xs text-muted-foreground">
                    <ShieldAlert class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                    <span>
                        Nothing tops a balance up automatically, so this is the number until you change it again or
                        a request is approved against it. The old and the new value are recorded in the audit log
                        against your name, with this reason.
                    </span>
                </p>

                <DialogFooter>
                    <Button type="button" variant="outline" @click="emit('update:open', false)">Cancel</Button>
                    <Button type="submit" :disabled="form.processing">Save balance</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
