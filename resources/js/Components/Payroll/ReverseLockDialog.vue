<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Undo2 } from '@lucide/vue';
import { useId, watch } from 'vue';
import { payrollRoutes } from '@/Components/Payroll/payroll';
import { Button } from '@/Components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';

/**
 * **Reversing a lock. ADMIN only, and the reason is required.**
 *
 * Part D §14 and Part C §4 both say *"only ADMIN can reverse a lock (requires reason,
 * audit-logged)"*, and this dialog is where the reason is asked for. The copy says where it
 * goes — **onto the period and into the audit log, with the name of whoever typed it** —
 * because a person writing a sentence that will be read years later by somebody asking why a
 * closed month changed deserves to know that before they type it rather than after.
 *
 * The requirement is stated three times in the stack and each one refuses a different caller:
 * `ReverseLockRequest` gives the 422 on this field; `PayrollService::reverseLock()` throws a
 * sentence for any other caller; and `payroll_periods_reversal_is_whole` makes a reversal with
 * no stated cause impossible as a row (decision 9-5). The `required` attribute below is the
 * courtesy, not the promise.
 *
 * The control that opens this is only rendered when the server said `can_reverse_lock` — the
 * one place `PayrollPeriodPolicy` names a role — so nothing in this file tests who anybody is.
 */
const props = defineProps<{
    open: boolean;
    periodId: number;
    monthLabel: string;
}>();

const emit = defineEmits<{ close: [] }>();

const reasonId = useId();

const form = useForm({ reason: '' });

function submit(): void {
    // `onSuccess` closes it, for `PayrollItemDialog`'s reason: a reversal answers `back()`, so
    // the page re-renders with the month already reopened while this component keeps its own
    // `open` flag — the dialog would stay up over a screen that had moved on. A validation
    // failure deliberately keeps it open, with the error on the field that caused it.
    form.post(payrollRoutes.reverseLock(props.periodId), {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    });
}

/** A cancelled reversal leaves neither a half-typed reason nor a stale error behind. */
watch(
    () => props.open,
    (open) => {
        form.clearErrors();

        if (!open) {
            form.reason = '';
        }
    },
);
</script>

<template>
    <Dialog :open="open" @update:open="(value: boolean) => { if (!value) { emit('close'); } }">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Reverse the lock on {{ monthLabel }}?</DialogTitle>
                <DialogDescription>
                    This reopens {{ monthLabel }} to the finance ledger: income and expenses dated in it can be
                    recorded, edited, moved and deleted again, and the period goes back to Approved. Only an
                    Admin can do this. Give a reason — it is recorded on the period and written to the audit
                    log with your name, where it cannot be edited or deleted by anyone.
                </DialogDescription>
            </DialogHeader>

            <form class="flex min-w-0 flex-col gap-4" @submit.prevent="submit">
                <div class="flex flex-col gap-2">
                    <Label :for="reasonId">Reason</Label>
                    <Textarea
                        :id="reasonId"
                        v-model="form.reason"
                        rows="3"
                        required
                        placeholder="Why is this month being reopened?"
                    />
                    <p class="text-xs text-muted-foreground">
                        Required. This sentence is what the audit log will show, beside your name and the time.
                    </p>
                    <p v-if="form.errors.reason" class="text-xs text-destructive">
                        {{ form.errors.reason }}
                    </p>
                </div>

                <DialogFooter>
                    <Button type="button" variant="outline" :disabled="form.processing" @click="emit('close')">
                        Keep it locked
                    </Button>
                    <Button type="submit" :disabled="form.processing">
                        <Undo2 aria-hidden="true" />
                        {{ form.processing ? 'Reversing…' : 'Reverse the lock' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
